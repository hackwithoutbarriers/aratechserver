# Phase 4 — Import CSV Hotspot

## Périmètre

Implémentation isolée de l'import CSV des utilisateurs Hotspot. Le système existant de tickets/vouchers reste dans `admin/inventory.php` et n'est pas réutilisé pour cet import.

## Audit effectué

- `admin/inventory.php` : import existant confirmé comme import de `tickets`; aucune réutilisation pour les utilisateurs Hotspot.
- `admin/users.php` et `api.php` : routes Hotspot existantes et architecture `hotspot_commands` inspectées.
- `database/migrations/002_hotspot_users.sql`, `003_hotspot_profiles.sql`, `005_hotspot_commands.sql` : schéma Phase 2 utilisé comme contrat.
- Worker RouterOS Phase 3 : actions `create/update/enable/disable/delete` et limites `limit-uptime` / `limit-bytes-total` conservées.

## Architecture finale

```text
CSV
 ↓
Validation serveur + preview
 ↓
Confirmation explicite
 ↓
Transaction PostgreSQL/Supabase
 ├─ hotspot_users (insert/update)
 └─ hotspot_commands (create/update)
        ↓
   MikroTik worker
        ↓
   ACK / état réel
```

L'import ne contacte jamais directement MikroTik.

## Format CSV

Colonnes obligatoires, insensibles à la casse et aux espaces superflus :

- `Username`
- `Password`
- `Profile`
- `Time Limit`
- `Data Limit`
- `Comment`

Accepté : UTF-8, UTF-8 BOM, séparateur `,` ou `;`.

Colonnes supplémentaires : ignorées et signalées dans la prévisualisation.

Limites : 2 MiB et 2 000 lignes de données.

## Mapping

| CSV | Supabase | MikroTik worker |
|---|---|---|
| Username | username | username/name |
| Password | password | password |
| Profile | profile | profile |
| Time Limit | limit_uptime | limit-uptime |
| Data Limit | limit_bytes_total | limit-bytes-total |
| Comment | comment | comment |

`limit_uptime` reste une valeur compatible RouterOS avant insertion dans le type PostgreSQL `INTERVAL`.
`limit_bytes_total` conserve les valeurs numériques, y compris `0`.

## Validation

- Username obligatoire, maximum 64 caractères, caractères compatibles avec la validation Hotspot existante.
- Password obligatoire, sans politique supplémentaire inventée.
- Profile obligatoire et présent dans `hotspot_profiles`.
- Time Limit vide autorisé ; sinon syntaxe RouterOS de durée contrôlée (`10h`, `24h`, `7d`, `1w`, combinaisons espacées).
- Data Limit vide autorisé ; sinon entier non négatif.
- Comment facultatif.
- Doublons internes au CSV rejetés.
- Utilisateurs existants classés en UPDATE.
- Fichier sans lignes de données rejeté.
- Contenu ressemblant à un script PHP rejeté.

## Preview / confirmation

La preview ne modifie pas la base. Les mots de passe sont conservés uniquement côté serveur pour la confirmation et sont masqués dans l'interface.

La confirmation utilise une transaction PostgreSQL : si une erreur critique survient, rollback complet.

## Idempotence des commandes

Avant de créer une commande, le système recherche une commande `PENDING`, `PROCESSING` ou `EXECUTED` portant le même utilisateur, la même action et le même payload. Une commande identique peut donc être réutilisée au lieu d'être recréée.

## Sécurité

- Session admin existante réutilisée.
- Protection CSRF dédiée au formulaire d'import.
- Validation serveur obligatoire.
- Requêtes PDO préparées.
- Aucun mot de passe dans les logs ou rapports.
- Aucun secret MikroTik dans le code du nouvel import.
- Le fichier uploadé reste le fichier temporaire PHP et n'est pas conservé après la requête.

## Fichiers Phase 4

- `admin/user-import.php` — interface preview/confirmation/rapport.
- `lib/hotspot_csv_import.php` — parsing, validation, import transactionnel et mise en file.
- `admin/header.php` — ajout du lien « Import CSV Hotspot ».
- `tests/hotspot_csv_import_test.php` — tests statiques et règles de validation.

## Baseline Phase 3 portée dans le package

Le package est construit sur le correctif Phase 3 fourni/précédemment produit afin que l'import utilise réellement les limites et la file `hotspot_commands` prévues par cette phase :

- `api.php`
- `mikrotik-scripts/hotspot-command-worker.rsc`
- `mikrotik-scripts/sync-users-supabase.rsc`
- `tests/sync_users_static_test.php`
- `tests/phase3_hotspot_static_test.php`

Ces changements ne constituent pas une nouvelle fonctionnalité Phase 4 ; ils sont le socle Phase 3 requis.

## Tests

- `php -l` sur 29 fichiers PHP : aucune erreur de syntaxe.
- `tests/hotspot_csv_import_test.php` : tous les contrôles disponibles passent.
- `tests/sync_users_static_test.php` : 7/7 PASS.
- `tests/phase3_hotspot_static_test.php` : tous les contrôles PASS.
- Les tests d'intégration CSV dépendant d'un driver SQLite PDO ont été détectés comme non exécutables dans cet environnement, car le driver SQLite PHP n'est pas installé.
- Aucun test de connexion réel à Supabase ou d'exécution réelle sur MikroTik n'a été effectué dans cet environnement.

## Problèmes restants

1. Exécuter les migrations Phase 2 sur la base Supabase réelle si elles ne le sont pas déjà.
2. Tester l'import avec un véritable export Mikhmon représentatif.
3. Tester le cycle complet `PENDING → PROCESSING → EXECUTED/FAILED` avec un MikroTik RouterOS cible.
4. Les secrets déjà présents dans le dépôt fourni doivent rester considérés comme compromis et être rotatés avant production.
