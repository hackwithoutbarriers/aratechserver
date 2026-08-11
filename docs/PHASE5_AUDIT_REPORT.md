# Phase 5 — Audit / normalisation API PHP

## Périmètre audité

Le dépôt fourni contient une API centrale `api.php`, les pages d'administration, les scripts RouterOS et les tests Phase 3/4. Les appels ont été recherchés dans les PHP et scripts `.rsc`.

Le dépôt confirme que `api.php` est l'implémentation API active : les pages admin et les scripts MikroTik l'appellent directement.

## Constats avant correction

### Sécurité

1. CORS utilisait `Access-Control-Allow-Origin: *`.
2. Le token admin était principalement transmis en query string (`?token=`), ce qui augmente le risque de fuite par logs, historique et outils intermédiaires.
3. Les réponses d'erreur historiques utilisaient seulement `success/message`, tandis que les routes Hotspot utilisaient `success/error`.
4. Le parseur JSON pouvait retomber silencieusement sur `$_POST` pour un JSON invalide.
5. Certaines routes de mutation historiques ne forçaient pas explicitement POST.

### Architecture

1. L'API contenait encore une connexion directe à RouterOS pour l'expiration et pour un statut réseau legacy.
2. Des helpers de schéma pouvaient modifier le schéma PostgreSQL à l'exécution.
3. `hotspot-user-update` transmettait le payload reçu presque tel quel à la file de commandes.
4. Les commandes identiques pouvaient être créées plusieurs fois.

### Compatibilité

- Les routes existantes ont été conservées.
- Les clés métier historiques ne sont pas supprimées.
- Le champ `message` est conservé en erreur en plus du contrat `error.code/error.message`.
- `X-API-Key` reste compatible avec les scripts RouterOS.
- Le token admin query/body reste accepté en compatibilité, mais les nouveaux clients doivent utiliser un header.

## Corrections réalisées

### 1. Contrat JSON

- succès Hotspot : `success=true,data=...`.
- erreurs : `success=false,error={code,message}`.
- compatibilité legacy : `message` reste présent sur les erreurs.
- `JSON_INVALID_UTF8_SUBSTITUTE` et `JSON_THROW_ON_ERROR` sont utilisés pour les réponses.

### 2. CORS / headers

- suppression du wildcard `*`.
- origine contrôlée par `ALLOWED_ORIGIN`.
- `Cache-Control: no-store`.
- `X-Content-Type-Options: nosniff`.
- `Referrer-Policy: no-referrer`.

### 3. Authentification

- admin : `X-Admin-Token` ou Bearer.
- machine-to-machine : `X-API-Key` ou Bearer.
- compatibilité avec les anciens appels query/body conservée.
- aucun secret n'est retourné dans les réponses.

### 4. Validation

- JSON invalide explicitement rejeté.
- méthodes HTTP des routes machine-to-machine et mutations historiques imposées.
- dates de rapports validées.
- `limit_uptime` et `limit_bytes_total` validés côté serveur.
- actions Hotspot limitées à `create/update/enable/disable/delete`.

### 5. Hotspot

- les mutations Web passent par `hotspot_commands`.
- aucune connexion RouterOS directe depuis les routes de commande.
- `hotspot-user-update` utilise une whitelist de champs.
- les commandes identiques sont réutilisées pour éviter les doublons accidentels.
- le statut reste distinct entre commande en file et ACK exécuté.

### 6. Schéma PostgreSQL

- `hotspot_users` et `hotspot_commands` ne sont pas créés dynamiquement.
- les colonnes PostgreSQL de `hotspot_snapshots` sont désormais vérifiées ; l'API ne fait plus de `ALTER TABLE` Supabase à chaud.
- les modifications de schéma doivent passer par la migration Phase 2/déploiement.

### 7. RouterOS direct

Les connexions directes depuis `api.php` ont été supprimées. L'état opérationnel Hotspot doit venir de la synchronisation/snapshot RouterOS → Supabase et les mutations doivent passer par `hotspot_commands`.

## Inventaire des endpoints

Voir `API_DOCUMENTATION.md` pour la cartographie complète.

Aucun endpoint n'a été supprimé.

## Tests réalisés

- `php -l api.php` : PASS.
- `tests/sync_users_static_test.php` : PASS.
- `tests/phase3_hotspot_static_test.php` : PASS.
- `tests/phase5_api_static_test.php` : PASS.

Le test CSV existant dans le package Phase 4 ne peut pas être exécuté dans ce package isolé car `admin/inventory.php` n'y est pas inclus. Ce test dépend du dépôt complet ; le dépôt complet fourni a bien été utilisé pour l'audit des références.

Aucun test réel Supabase/PostgreSQL ou MikroTik physique n'est revendiqué dans cet environnement.

## Compatibilité / limites restantes

1. Les fonctionnalités commerciales/publicitaires historiques continuent d'utiliser SQLite ; les migrer vers Supabase serait hors périmètre de cette phase.
2. Les endpoints admin legacy utilisent encore `?token=` lorsqu'ils sont appelés par certains consommateurs existants. Le header `X-Admin-Token` est désormais disponible et recommandé. Une migration progressive des consommateurs peut supprimer complètement le token en URL.
3. Les routes de profils Hotspot et de déconnexion de session restent `501 NOT_IMPLEMENTED` comme dans le contrat existant ; elles n'ont pas été inventées dans cette phase.
4. Le rate limiting distribué n'a pas été ajouté : il nécessite un stockage partagé et n'est pas nécessaire pour corriger la couche API sans introduire une nouvelle infrastructure.

## Fichiers modifiés

- `api.php` — sécurité, validation, contrat JSON, auth, suppression des connexions RouterOS directes, protection du schéma, idempotence et whitelist.
- `tests/phase5_api_static_test.php` — tests statiques Phase 5.
- `API_DOCUMENTATION.md` — documentation et inventaire.
- `PHASE5_AUDIT_REPORT.md` — rapport.

## Aucun changement volontaire

- logique commerciale tickets/sales/expenses.
- SQLite/Turso.
- UI métier.
- scripts MikroTik.
- logique CSV Phase 4.

## Sécrets détectés et traitement

Le dépôt source fourni contenait des credentials et une clé `HOTSPOT_SYNC_KEY` en clair dans `.env` et plusieurs scripts RouterOS. Ces valeurs ne doivent pas être redistribuées.

- `.env` a été exclu du package de livraison.
- Les clés RouterOS codées en clair ont été remplacées par `REPLACE_WITH_ROTATED_HOTSPOT_SYNC_KEY` dans les scripts livrés.
- **Action production obligatoire :** révoquer/rotater les credentials déjà exposés dans le dépôt source avant déploiement.
