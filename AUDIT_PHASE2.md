# Phase 2 — Audit & normalisation DB — hackwithoutbarriers/aratechserver

Périmètre strict : PostgreSQL/Supabase uniquement. Aucune modification de
`api.php`, de l'UI, de la logique métier, des scripts MikroTik (hors
constat), ni migration/suppression de SQLite/Turso. Voir
`database/migrations/` pour le SQL livré.

---

## ⚠️ Constat hors périmètre, à traiter en urgence

Ce n'est pas un livrable "DB" au sens strict, mais il doit être signalé
immédiatement : le dépôt cloné contient un fichier **`.env` commité** avec
des identifiants réels (host/port/db/user/**password** Supabase, clé
`HOTSPOT_SYNC_KEY`, `ADMIN_TOKEN`, `ADMIN_PASSWORD_HASH`, identifiants
MikroTik). Le même `HOTSPOT_SYNC_KEY` est aussi codé en clair dans
`mikrotik-scripts/sync-users-supabase.rsc` et
`mikrotik-scripts/push-hotspot-status.rsc`. Le fichier `db.php` contient
par ailleurs, en valeur de repli (`?:`), le nom de projet Supabase et
l'utilisateur pooler par défaut.

**Recommandation indépendante de cette phase DB** : si ce dépôt est public
(ou l'a été à un moment de son historique Git), considérer tous ces
secrets comme compromis, les régénérer (mot de passe Postgres, clé de
sync, token admin), retirer `.env` du suivi Git et l'ajouter à
`.gitignore`, purger l'historique si nécessaire. Je n'ai pas tenté de me
connecter à la base Supabase avec ces identifiants.

---

## A. Audit DB (par table)

| Table | État actuel (code) | Problème | Action | Priorité |
|---|---|---|---|---|
| `hotspot_users` | Supabase, gérée par `ensure_hotspot_users_table()` (api.php). Colonnes : username(PK), password, profile, mac_address, comment, disabled(TEXT), bytes_in/out(BIGINT), uptime, server | Pas de colonnes pour Time Limit/Data Limit/last_sync (bloque le futur import CSV) | Ajouter `limit_uptime INTERVAL`, `limit_bytes_total BIGINT`, `last_sync TIMESTAMPTZ` + contraintes | Haute |
| `hotspot_profiles` | Supabase, `sync-profiles` (api.php ~L.1839). Une seule définition trouvée dans le code | Le brief supposait 2 définitions concurrentes ; non confirmé côté code. Pas de `last_sync` | Aligner schéma, ajouter `last_sync` (colonne neuve, non lue/écrite par le code aujourd'hui) | Moyenne |
| `hotspot_expiry` | **Deux définitions incompatibles réelles** : SQLite locale (colonne `user`) vs Supabase (colonne `user_id`, `ON CONFLICT (user_id)`) | Confusion de convention confirmée (§8) | Standardiser Supabase sur `user_id` (déjà la convention en prod) ; SQLite locale hors périmètre | Haute |
| `hotspot_commands` | Supabase, `ensure_hotspot_commands_table()`. BIGSERIAL déjà en place | Timestamps en TEXT, pas de CHECK sur `action`/`status` | Convertir created_at/processing_at/processed_at en TIMESTAMPTZ, ajouter CHECK + index (status, created_at) | Moyenne |
| `hotspot_snapshots` | SQLite locale ET Supabase (double écriture, admin/index.php + api.php). Colonnes de base + colonnes "V2.1" ajoutées dynamiquement | snapshot_date/snapshot_time/received_at en TEXT alors que toujours générés serveur | Typer DATE/TIME/TIMESTAMPTZ (conversion sûre) ; conserver les champs (code en dépend) | Basse |
| `router_logs` | Supabase, **aucun `CREATE TABLE` dans le dépôt** — table supposée créée manuellement hors dépôt | Schéma non versionné ; le `ON CONFLICT (log_date, log_time, message)` suppose une contrainte UNIQUE non garantie par le code | Créer/aligner le schéma, garantir la contrainte UNIQUE utilisée par le code, indexer | Haute |
| `sales_log` | Supabase, **aucun `CREATE TABLE` dans le dépôt** — table supposée créée manuellement | Idem router_logs ; sale_date/sale_time non validés côté PHP (payload MikroTik libre) | Créer/aligner le schéma sans typer sale_date/sale_time (risque de casse, voir migration 008) | Haute |
| `profiles` | Supabase, utilisée seulement par `admin/inventory.php` (`SELECT id, name FROM profiles`) | Aucune fonction ne la crée ; `ara_ensure_finance_tables()` (censée le faire) **n'existe pas dans le dépôt** | Fournir le schéma manquant (migration 009) ; signaler le bug PHP séparément | Haute |
| `tickets` | Supabase, idem (`admin/inventory.php`) : id, profile_id, code, status, imported_at | Même bug `ara_ensure_finance_tables()` manquante | Idem profiles | Haute |
| `sales` | **N'existe pas dans le code.** Aucune requête SQL ne cible une table `sales` | Le brief supposait son existence (§13) | Ne rien créer sans preuve ; documenter (voir section E) | N/A |
| `expenses` | **N'existe pas en base.** `admin/finances.php` utilise un tableau PHP statique codé en dur | Le brief supposait une table DB | Ne rien créer sans preuve d'usage réel | N/A |
| `ads`, `track_events`, `loyalty`, `transactions` | SQLite locale uniquement (`db.php`, `ara_db()`) | Hors périmètre PostgreSQL/Supabase de cette phase | Aucune action DB Postgres cette phase ; voir section F | Basse |

---

## B. Schéma cible (tables retenues, PostgreSQL/Supabase)

```
profiles (id PK, name UNIQUE)
   │ 1
   │
   │ N
tickets (id PK, profile_id FK -> profiles.id, code UNIQUE, status, imported_at)

hotspot_profiles (profile_name PK, shared_users, rate_limit, on_login,
                   address_pool, last_sync)

hotspot_users (username PK, password, profile, mac_address, comment,
               disabled, bytes_in, bytes_out, uptime, server,
               limit_uptime, limit_bytes_total, last_sync)
    -- "profile" référence hotspot_profiles.profile_name par convention
    -- applicative (hotspot_profile_exists()), sans FK stricte : le code
    -- tolère explicitement une table hotspot_profiles absente/inconnue
    -- (voir api.php ~L.466, commentaire "ne bloque pas"). Ajouter une FK
    -- stricte casserait ce comportement volontaire — non fait cette phase.

hotspot_expiry (user_id PK, expiry, updated_at)

hotspot_commands (id PK, action, username, payload, status, created_at,
                   processing_at, processed_at, router_identity, result,
                   message)

hotspot_snapshots (id PK, snapshot_date, snapshot_time, active_count,
                    users_blob, received_at, router_identity, router_uptime,
                    router_version, cpu_load, memory_total, memory_free,
                    users_json, network_json)

router_logs (id PK, log_date, log_time, topics, message, received_at,
             UNIQUE(log_date, log_time, message))

sales_log (id PK, sale_date, sale_time, username, amount, ip, mac,
           profile, comment, received_at)
```

Aucune relation formelle `tickets -> sales` : elle n'existe pas dans le
code (voir section A/E).

---

## C. Migrations SQL livrées

Voir `database/migrations/` :
`001_baseline.sql`, `002_hotspot_users.sql`, `003_hotspot_profiles.sql`,
`004_hotspot_expiry.sql`, `005_hotspot_commands.sql`,
`006_hotspot_snapshots.sql`, `007_router_logs.sql`, `008_sales_log.sql`,
`009_commercial_profiles_tickets.sql`, et `README.md` (ordre d'exécution,
garanties de non-destructivité, rollback).

---

## D. Mapping code ↔ DB

```
hotspot_users.username
    ↕
api.php : ensure_hotspot_users_table(), upsert_hotspot_sync_user(),
          normalize_hotspot_sync_user(), routes "sync-users", "hotspot-users"
    ↕
admin/users.php (liste/filtre par profil)
    ↕
mikrotik-scripts/sync-users-supabase.rsc (champs envoyés : name, password,
          profile, mac-address, comment, disabled, bytes-in, bytes-out,
          uptime, server — PAS de limit-uptime/limit-bytes-total/last-sync
          aujourd'hui, voir section G/§21 ci-dessous)

hotspot_profiles.profile_name
    ↕
api.php : route "sync-profiles" (~L.1829), hotspot_profile_exists()
    ↕
admin/users.php (liste déroulante des profils)

hotspot_expiry.user_id (Supabase) / hotspot_expiry.user (SQLite locale)
    ↕
api.php : routes "set-expiry", "expiry", get_user_expiry_from_router()

hotspot_commands.*
    ↕
api.php : queue_hotspot_command(), claim_hotspot_commands(),
          routes "hotspot-commands-pending", "hotspot-command-ack",
          "hotspot-command-status"
    ↕
mikrotik-scripts/hotspot-command-worker.rsc

hotspot_snapshots.*
    ↕
api.php : route "push-status", hotspot_online_usernames(),
          compute_router_status(), extract_snapshot_users()
    ↕
admin/index.php (dashboard, restauration Turso si local vide)
    ↕
mikrotik-scripts/push-hotspot-status.rsc

router_logs.*
    ↕
api.php : route "push-logs" (écriture), route de lecture par date/topic
          (~L.1442-1447)
    ↕
admin/logs.php (consommateur, via l'API)

sales_log.*
    ↕
api.php : routes "log-sale" (écriture, appelée par le script on-login
          MikroTik), "get-sales", "dashboard"
    ↕
admin/reports.php, admin/index.php (tableau de bord)

profiles.id / profiles.name, tickets.*
    ↕
admin/inventory.php (import CSV de codes, tableau de bord des stocks)
    ⚠ dépend de ara_ensure_finance_tables() — fonction absente du dépôt
```

---

## E. Doublons / incohérences supprimés ou clarifiés

- **`hotspot_expiry`** : divergence réelle de convention de colonne
  (`user` en SQLite locale vs `user_id` en Supabase). Standardisé sur
  `user_id` côté Supabase (déjà la convention utilisée en production par
  le code) — voir `004_hotspot_expiry.sql`. La colonne `user` de la base
  SQLite locale n'est pas renommée : hors périmètre PostgreSQL de cette
  phase, et la renommer impliquerait de modifier `api.php`.
- **`hotspot_profiles`** : le brief supposait deux définitions
  concurrentes dans le code ; l'audit du dépôt actuel n'en trouve qu'une
  seule (api.php ~L.1839). Aucune suppression de doublon nécessaire côté
  code — signalé au cas où un doublon existerait uniquement côté schéma
  Supabase live (non observable depuis ce dépôt).
- **Confusion "sales" vs "sales_log"** : ce ne sont pas deux définitions
  de la même chose. `sales_log` est le journal technique alimenté par
  MikroTik (source réelle du CA affiché) ; une table `sales` distincte
  n'existe nulle part dans le code. Rien à fusionner ni supprimer — juste
  à documenter, ce que fait ce rapport.
- **`ara_ensure_finance_tables()`** : fonction appelée deux fois dans
  `admin/inventory.php` mais introuvable dans tout le dépôt. Ce n'est pas
  un doublon mais une fonction manquante — signalé car il explique
  pourquoi `profiles`/`tickets` n'avaient aucun schéma versionné avant
  cette phase.

---

## F. SQLite / Turso — inventaire (non migré cette phase)

| Table/fonction | Utilisation actuelle | Dépendance | Risque | Plan de migration ultérieur |
|---|---|---|---|---|
| `ads` (SQLite, `db.php`) | CRUD annonces (`admin/ads.php`, routes ads-*) | Forte (fonctionnalité active) | Colonne `end` (mot réservé en Postgres, à quoter), `active`/booléens en INTEGER | Migrer vers Supabase avec `active BOOLEAN`, `id` en UUID ou conserver TEXT, quoter `"end"` |
| `track_events` (SQLite) | Comptage vues/clics des annonces (`record_track_event()`) | Liée à `ads` | Aucun majeur | Migrer avec `ads` dans la même phase |
| `loyalty` (SQLite) | Points de fidélité par utilisateur | Faible usage apparent dans le code audité | Aucun majeur | À confirmer avant migration (fonctionnalité encore active ?) |
| `transactions` (SQLite) | "Gardée pour une éventuelle réactivation" (commentaire db.php L.50) | Explicitement inactive | Aucun | Ne pas migrer tant que non réactivée |
| `hotspot_snapshots` (SQLite locale, en parallèle de Supabase) | Cache rapide + restauration Turso si local vide (`restore_from_turso_if_empty`) | Forte (chemin de repli en cas d'échec Supabase) | Dépendance à Turso maintenue intentionnellement comme filet de sécurité | Ne pas toucher tant que la fiabilité Supabase n'est pas prouvée en continu |
| Turso (générique, `turso_pipeline()`/`turso_rows()`) | Utilisé par `restore_from_turso_if_empty()` (snapshots) et une requête dans `admin/settings.php` (`SELECT COUNT(*) FROM sales_log` côté Turso) | Filet de secours + vérification de comptage | Double source de vérité potentielle pour `sales_log` (Turso ET Supabase) | Clarifier en phase dédiée si Turso doit rester source de vérité pour `sales_log` ou être définitivement remplacé par Supabase |

La cible reste PostgreSQL/Supabase pour toute nouvelle fonctionnalité ;
aucune suppression de SQLite/Turso n'est effectuée cette phase (§19).

---

## G. Compatibilité MikroTik (§21)

Champs actuellement envoyés par `sync-users-supabase.rsc` pour chaque
utilisateur : `name, password, profile, mac-address, comment, disabled,
bytes-in, bytes-out, uptime, server`.

Les nouvelles colonnes `hotspot_users.limit_uptime` et
`hotspot_users.limit_bytes_total` (ajoutées §5/§22 pour préparer le futur
import CSV) **ne sont pas alimentées par ce script** : MikroTik n'envoie
pas aujourd'hui les propriétés `limit-uptime`/`limit-bytes-total` du
`/ip hotspot user`. C'est un ajout de schéma pur (colonnes nullable), donc
sans impact sur la synchronisation actuelle.

| Champ DB | Source MikroTik | Script concerné | Modification nécessaire |
|---|---|---|---|
| `hotspot_users.limit_uptime` | `/ip hotspot user` → propriété `limit-uptime` | `sync-users-supabase.rsc` | Ajouter `"limit-uptime"=($u->"limit-uptime")` au `userMap` |
| `hotspot_users.limit_bytes_total` | `/ip hotspot user` → propriété `limit-bytes-total` | `sync-users-supabase.rsc` | Ajouter `"limit-bytes-total"=($u->"limit-bytes-total")` au `userMap` |
| `hotspot_users.last_sync` | Aucune (générée côté serveur PHP au moment de l'UPSERT) | — (backend uniquement) | Aucune, à traiter côté `api.php` dans une phase dédiée |
| `hotspot_profiles.last_sync` | Aucune (générée côté serveur PHP) | — (backend uniquement) | Aucune, à traiter côté `api.php` dans une phase dédiée |

Ces modifications de scripts `.rsc` sont **volontairement non
appliquées** dans cette phase (périmètre DB strict, §21 : "les
modifications fonctionnelles des scripts seront traitées dans la phase
dédiée MikroTik").

---

## H. Tests (documentés — non exécutés en production)

Aucun accès réseau à la base Supabase de production n'était disponible
depuis cet environnement pour exécuter les migrations en conditions
réelles. Recommandation de validation avant déploiement, sur une base de
test avec le même schéma que la production :

1. `psql` sur une base vide → exécuter `001` à `009` dans l'ordre →
   vérifier `SELECT * FROM schema_migrations ORDER BY version` (9 lignes).
2. Rejouer `002` à `009` une deuxième fois → doit réussir sans erreur
   (idempotence) et sans doublon dans `schema_migrations`
   (`ON CONFLICT DO NOTHING`).
3. `INSERT`/`UPSERT` de test sur chaque table via les requêtes exactes du
   code (ex. reproduire `upsert_hotspot_sync_user()`, `queue_hotspot_
   command()`, la route `push-logs`, `log-sale`) → confirmer qu'aucune
   requête existante ne casse sur le nouveau schéma.
4. `FOREIGN KEY` : tenter d'insérer un `tickets.profile_id` inexistant →
   doit être rejeté. Tenter de supprimer un `profiles` référencé par des
   `tickets` → doit être rejeté (`ON DELETE RESTRICT`).
5. `UNIQUE`/`INDEX` : réinsérer deux fois la même ligne `router_logs`
   (mêmes `log_date`/`log_time`/`message`) → la deuxième doit être
   silencieusement ignorée (`ON CONFLICT DO NOTHING` côté code).
6. Vérifier `EXPLAIN` sur les requêtes `get-sales`/`dashboard`
   (`WHERE sale_date BETWEEN ? AND ?`) pour confirmer l'usage de
   `idx_sales_log_sale_date`.

---

## Risques restants (non résolus volontairement, hors périmètre)

1. **Secrets commités** (`.env`, clé de sync en clair dans les `.rsc`) —
   voir encadré en tête de rapport. Urgent, hors périmètre DB.
2. **`ara_ensure_finance_tables()` non définie** → `admin/inventory.php`
   plante (Fatal Error) tant que cette fonction n'est pas ajoutée au code.
   Le schéma qu'elle est censée garantir est désormais versionné
   (migration 009), mais le correctif PHP lui-même est hors périmètre.
3. **`admin/hotspot.php` inclut `admin/profiles.php`**, fichier absent du
   dépôt (`include __DIR__ . '/profiles.php'`, onglet "profiles" du menu
   hotspot) → erreur PHP si cet onglet est ouvert. Hors périmètre DB,
   signalé pour visibilité.
4. **`router_logs` et `sales_log` sans schéma versionné avant cette
   phase** : leur définition réelle en production reste une hypothèse
   construite à partir des seules requêtes du code (aucun accès direct à
   Supabase pour confirmer). À valider en environnement de test avant
   application en production (voir section H).
5. **`hotspot_expiry.expiry`** reste en TEXT (repli non structuré possible
   côté `get_user_expiry_from_router()`) — typer en TIMESTAMPTZ nécessite
   d'abord un durcissement du code (hors périmètre).
6. **`sales_log.sale_date`/`sale_time`** restent en TEXT pour la même
   raison (payload MikroTik non validé côté PHP).
7. **Pas de FK stricte `hotspot_users.profile → hotspot_profiles.profile_
   name`** : comportement tolérant volontairement conservé (voir section
   B), car le code gère explicitement le cas d'une table
   `hotspot_profiles` absente/incohérente.
8. **Turso comme source parallèle possible pour `sales_log`**
   (`admin/settings.php`) : à clarifier en phase dédiée (section F).

---

## Critères d'acceptation

- [x] Une seule définition cohérente de chaque table gérée par ce dépôt
- [x] `hotspot_users` supporte Time Limit (`limit_uptime`) et Data Limit
      (`limit_bytes_total`)
- [x] `username` correctement identifié comme identifiant métier unique
      (PRIMARY KEY, déjà en place)
- [x] `hotspot_profiles` a une structure unique (`profile_name` PK)
- [x] `profiles` et `hotspot_profiles` restent distinctes
- [x] Relation `tickets → profiles` cohérente (FK + index)
- [ ] Relation `sales → tickets` : **non applicable**, aucune table
      `sales` distincte n'existe dans le code (voir section E) — signalé
      plutôt que fabriqué
- [x] Types PostgreSQL adaptés (BIGINT compteurs, TIMESTAMPTZ horodatages
      fiables, DATE/TIME quand sûr, TEXT conservé quand une conversion
      casserait le code existant)
- [x] Index essentiels créés (voir chaque fichier de migration)
- [x] Contraintes essentielles ajoutées (`NOT VALID`, non bloquantes sur
      l'historique)
- [x] Migrations versionnées (`database/migrations/001` à `009`)
- [x] Créations de tables runtime identifiées (`ensure_hotspot_*`) et
      traitées via migrations équivalentes, sans modifier `api.php`
- [x] Aucune migration destructive non justifiée
- [x] Compatibilité du code existant vérifiée requête par requête (voir
      justifications dans chaque fichier `.sql`)
- [x] Aucune fonctionnalité métier supprimée
- [x] SQLite/Turso non supprimé (inventorié uniquement, section F)
- [x] Aucune implémentation d'import CSV réalisée
- [x] Aucune refactorisation de `api.php` réalisée
