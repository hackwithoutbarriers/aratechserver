# ARA Tech WiFi — Serveur de gestion

Application web de gestion pour une WiFi Zone commerciale (remplacement de
MikhMon local), en PHP + PostgreSQL/Supabase, hébergée sur Render.

## Architecture

Le routeur MikroTik (réseau local, IP privée) ne peut pas être joint
directement depuis le backend hébergé sur Render. Toute la donnée transite
donc par un miroir Supabase, alimenté et consommé de façon asynchrone :

```
Routeur MikroTik  ──push (scripts .rsc)──>  api.php  ──>  Supabase (miroir)
        ^                                                       │
        └──────── récupère les commandes en attente ────────────┘
                  (file hotspot_commands, cf. hotspot-command-worker.rsc)
```

- **Lecture** (utilisateurs, profils, sessions, vouchers) : pages admin →
  `api.php?route=hotspot-...` → tables Supabase (`hotspot_users`,
  `hotspot_profiles`, `hotspot_snapshots`, ...).
- **Écriture** (créer/modifier/activer/désactiver/supprimer un utilisateur) :
  pages admin → `api.php` → file `hotspot_commands` → le routeur récupère et
  applique la commande au prochain cycle de synchronisation
  (`mikrotik-scripts/hotspot-command-worker.rsc`). Les mutations sont donc
  **asynchrones** : l'interface affiche l'état "en attente" jusqu'au prochain
  cycle.
- Les sessions actives sont un instantané (snapshot) poussé périodiquement
  par le routeur, pas une connexion live.
- **Persistance** : toutes les données applicatives persistantes sont dans
  PostgreSQL/Supabase. Le conteneur Render ne maintient plus de SQLite,
  fichier de log applicatif ou autre état métier local.

Le jeton administrateur transite uniquement via l'en-tête HTTP
`X-Admin-Token`, jamais dans une URL (voir `lib/api_client.php`).

## Arborescence

```
├── index.php, api.php, config.php, db.php, ads.json   # Webroot
├── admin/               # Back-office
├── lib/                 # Logique partagée
├── database/migrations/ # Schéma PostgreSQL/Supabase, à exécuter dans l'ordre
├── mikrotik-scripts/    # Scripts RouterOS déployés sur le routeur
└── docs/                # Documentation API + historique des audits
```

## Déploiement

`Dockerfile` copie l'intégralité du dépôt dans `/var/www/html/` : la racine
du dépôt est le webroot servi. Le runtime PHP embarque uniquement PDO
PostgreSQL (`pdo_pgsql`) pour la persistance applicative.

Exécuter les migrations SQL de `database/migrations/` (dans l'ordre numérique)
sur la base Supabase avant le premier déploiement, notamment
`010_application_legacy_tables.sql` pour les fonctions annonces/fidélité/
tracking encore exposées par l'API.

## Documentation

- `docs/API_DOCUMENTATION.md` — contrat des routes de `api.php`.
- `docs/audits/` — historique des audits de code (Phases 2 à 5).
