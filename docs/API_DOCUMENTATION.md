# API PHP — Phase 5

## Contrat général

Toutes les réponses API sont `application/json; charset=utf-8` et utilisent :

### Succès
```json
{"success":true,"data":{}}
```

Les routes historiques peuvent conserver leurs propriétés métier au niveau racine (`items`, `logs`, etc.) pour compatibilité frontend, mais les erreurs exposent désormais aussi le contrat normalisé.

### Erreur
```json
{
  "success": false,
  "error": {"code": "VALIDATION_ERROR", "message": "Données invalides"},
  "message": "Données invalides"
}
```

Le champ `message` est conservé pour les anciens consommateurs.

## Authentification

- **Admin** : `X-Admin-Token: <ADMIN_TOKEN>` ou `Authorization: Bearer <ADMIN_TOKEN>`.
- Compatibilité legacy : `token` en query/body reste accepté, mais n'est pas recommandé car il peut apparaître dans des URLs/logs/proxies.
- **MikroTik / machine-to-machine** : `X-API-Key: <HOTSPOT_SYNC_KEY>` ou Bearer.
- L'API ne retourne jamais ces secrets.

## CORS et sécurité HTTP

- `Access-Control-Allow-Origin: *` est supprimé.
- Une origine est autorisée uniquement si elle correspond à `ALLOWED_ORIGIN`.
- `Cache-Control: no-store`, `X-Content-Type-Options: nosniff` et `Referrer-Policy: no-referrer` sont envoyés.
- Les corps JSON invalides sont rejetés.

## Endpoints

| Endpoint (`route`) | Méthode | Auth | Fonction / DB | Consommateur |
|---|---|---|---|---|
| `ads` | GET | Public | annonces / SQLite legacy | portail |
| `loyalty` | GET | Public | fidélité / SQLite legacy | portail |
| `track` | POST | Public | tracking / SQLite legacy | portail |
| `admin` | GET | Admin | annonces/fidélité / SQLite legacy | `admin/ads.php` |
| `admin_save_ad` | POST | Admin | upsert annonce / SQLite legacy | `admin/ads.php` |
| `admin_delete_ad` | POST | Admin | suppression annonce / SQLite legacy | `admin/ads.php` |
| `admin_reseed_ads` | POST | Admin | reseed annonces / SQLite legacy | `admin/ads.php` |
| `hotspot-commands-pending` | GET | Sync key | claim `hotspot_commands` | worker MikroTik |
| `hotspot-command-ack` | POST | Sync key | ACK + miroir `hotspot_users` | worker MikroTik |
| `hotspot-command-status` | GET | Admin | état d'une commande | admin/users + import |
| `set-expiry` | POST | Sync key | miroir `hotspot_expiry` | scripts MikroTik |
| `expiry` | GET | Public legacy | lecture miroir `hotspot_expiry` | portail |
| `push-logs` | POST | Sync key | `router_logs` | MikroTik |
| `get-logs` | GET | Admin | `router_logs` | `admin/logs.php` |
| `log-sale` | POST | Sync key | `sales_log` | MikroTik |
| `get-sales` | GET | Admin | `sales_log` | `admin/reports.php` |
| `get-sales-daily` | GET | Admin | `sales_log` | `admin/reports.php` |
| `push-status` | POST | Sync key | `hotspot_snapshots` | MikroTik |
| `status` | GET | Admin | snapshot réseau | dashboard/status |
| `sync-users` | POST | Sync key | UPSERT `hotspot_users` | `sync-users-supabase.rsc` |
| `sync-profiles` | POST | Sync key | UPSERT `hotspot_profiles` | scripts MikroTik |
| `dashboard` | GET | Admin | agrégats Supabase | dashboard |
| `hotspot-users` | GET | Admin | `hotspot_users` + expiry | `admin/users.php` |
| `hotspot-user` | GET | Admin | détail `hotspot_users` | `admin/users.php` |
| `hotspot-user-create` | POST | Admin | file `hotspot_commands` | `admin/users.php` |
| `hotspot-user-update` | POST | Admin | file `hotspot_commands` | `admin/users.php` |
| `hotspot-user-enable` | POST | Admin | file `hotspot_commands` | `admin/users.php` |
| `hotspot-user-disable` | POST | Admin | file `hotspot_commands` | `admin/users.php` |
| `hotspot-user-delete` | POST | Admin | file `hotspot_commands` | `admin/users.php` |
| `hotspot-profiles` | GET | Admin | `hotspot_profiles` | `admin/profiles`/frontend |
| `hotspot-profile` | GET | Admin | `hotspot_profiles` | frontend |
| `hotspot-profile-create` | POST | Admin | non implémenté | compatibilité H1 |
| `hotspot-profile-update` | POST | Admin | non implémenté | compatibilité H1 |
| `hotspot-profile-delete` | POST | Admin | non implémenté | compatibilité H1 |
| `hotspot-active` | GET | Admin | snapshot sessions | `admin/active-users.php` / frontend |
| `hotspot-session-disconnect` | POST | Admin | non implémenté | frontend |
| `hotspot-vouchers` | GET | Admin | `hotspot_users` filtrés | vouchers |
| `hotspot-voucher-generate` | POST | Admin | non implémenté | vouchers |

## Hotspot : création / modification

Les routes `hotspot-user-create`, `hotspot-user-update`, `hotspot-user-enable`, `hotspot-user-disable` et `hotspot-user-delete` ne contactent pas MikroTik.

Flux :

```text
Web/API
  ↓
Supabase hotspot_users / hotspot_commands
  ↓
RouterOS worker
  ↓
MikroTik
  ↓
ACK
  ↓
Supabase miroir
```

Les payloads de commandes utilisent une whitelist explicite. Les champs `limit_uptime` et `limit_bytes_total` sont validés côté serveur et transmis au worker sous les clés attendues par Phase 3.

## Commandes

États physiques du schéma actuel :

- `PENDING` : en attente
- `PROCESSING` : réclamée par un routeur
- `EXECUTED` : exécutée et ACK confirmée
- `FAILED` : exécution échouée

L'API ne transforme pas une insertion de commande en synchronisation réussie.

Les commandes identiques sont réutilisées lorsqu'une commande correspondante est déjà `PENDING`, `PROCESSING` ou `EXECUTED`.

## CSV Phase 4

L'import CSV reste une fonctionnalité dédiée (`admin/user-import.php` + `lib/hotspot_csv_import.php`). L'API Hotspot ne crée pas une seconde logique CSV.

Le backend CSV alimente Supabase puis `hotspot_commands`; l'API conserve la même file et le même worker.

## Schéma

`hotspot_users`, `hotspot_profiles`, `hotspot_commands` et le schéma Supabase Phase 2 ne sont plus créés/modifiés dynamiquement par l'API.

Pour PostgreSQL, une incompatibilité de schéma doit être corrigée par une migration de déploiement, pas par une requête `CREATE/ALTER` exécutée depuis `api.php`.

La couche SQLite reste utilisée par les anciennes fonctionnalités commerciales/publicitaires. Cette coexistence est conservée volontairement pour ne pas migrer `sales`, `expenses`, `ads` ou Turso dans cette phase.
