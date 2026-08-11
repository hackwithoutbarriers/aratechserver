# Phase 3 — Audit et corrections Hotspot

## Périmètre
Audit ciblé de `api.php`, des migrations Hotspot, des scripts MikroTik Hotspot et des tests du dépôt fourni.

## Corrections réalisées

1. **MikroTik → Supabase / hotspot_users**
   - Synchronisation idempotente par `username`.
   - Suppression destructive du miroir supprimée.
   - Mapping ajouté pour `limit-uptime` → `limit_uptime`.
   - Mapping ajouté pour `limit-bytes-total` → `limit_bytes_total`.
   - `last_sync` renseigné.
   - Les lots partiels n'effacent plus les utilisateurs absents du lot courant.

2. **hotspot_profiles**
   - UPSERT par `profile_name` conservé.
   - `last_sync` renseigné.
   - La route ne recrée plus la table Supabase à l'exécution.

3. **File hotspot_commands**
   - Le schéma Supabase reste piloté par les migrations Phase 2, pas par le PHP runtime.
   - Le worker conserve les actions `create`, `update`, `enable`, `disable`, `delete`.
   - Les commandes web ne modifient plus `hotspot_users` avant confirmation MikroTik.
   - L'ACK applique l'état confirmé au miroir Supabase.
   - Les opérations restent idempotentes autant que possible.

4. **Limites**
   - `create` et `update` transmettent `limit-uptime` et `limit-bytes-total`.
   - Les propriétés absentes ne sont pas envoyées au routeur.
   - `0` pour `limit-bytes-total` reste une valeur valide.

5. **Expiration**
   - Les scripts `expire-10H`, `expire-24H`, `expire-week`, `expire-month` et `expire-abonnement` ont été audités.
   - Ils utilisent des profils distincts et une logique homogène d'expiration locale au routeur.
   - `sync-all-expiry` continue d'alimenter `hotspot_expiry`.

## Tests

- `php -l` : **26 fichiers PHP, 0 erreur**.
- `tests/sync_users_static_test.php` : **7/7 PASS**.
- `tests/phase3_hotspot_static_test.php` : **13/13 PASS**.
- La syntaxe RouterOS n'a pas pu être exécutée sur un vrai MikroTik dans cet environnement ; les scripts ont donc été contrôlés statiquement.

## Points restant à traiter avant production

- Le dépôt fourni contient des secrets réels dans `.env` et certains scripts MikroTik. Ils doivent être considérés comme compromis et **rotatés** avant déploiement si le dépôt a été partagé/public.
- Le fichier `tous_Script.rsc.rsc` est un export console/dump tronqué et ne doit pas être considéré comme la source de déploiement principale ; les fichiers `.rsc` dédiés restent les références opérationnelles.
- Les migrations Phase 2 doivent être appliquées sur la base Supabase réelle avant d'utiliser les nouvelles colonnes.
- Un test d'intégration réel doit être exécuté avec un MikroTik RouterOS correspondant à la version cible.

## Hors périmètre
Aucune modification volontaire de l'UI, du système commercial, de l'import CSV Phase 4, de SQLite/Turso ou des publicités.
