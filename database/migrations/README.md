# Migrations DB — Phase 2 (ARA-Tech / aratechserver)

## Portée

Ces migrations ciblent **uniquement PostgreSQL/Supabase**. La base SQLite
locale (`db.php`, `ara_db()`) et Turso ne sont pas touchées dans cette phase
(voir `AUDIT_PHASE2.md`, section F).

## Ordre d'exécution

Les fichiers sont numérotés et doivent être exécutés **dans l'ordre**, une
seule fois chacun (ou autant de fois que nécessaire : chaque fichier est
idempotent — `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`,
contraintes ajoutées seulement si absentes).

```
001_baseline.sql
002_hotspot_users.sql
003_hotspot_profiles.sql
004_hotspot_expiry.sql
005_hotspot_commands.sql
006_hotspot_snapshots.sql
007_router_logs.sql
008_sales_log.sql
009_commercial_profiles_tickets.sql
```

## Comment exécuter

Avec `psql` (recommandé, permet de voir chaque étape) :

```bash
for f in database/migrations/0*.sql; do
  echo "== $f =="
  psql "$SUPABASE_CONNECTION_STRING" -v ON_ERROR_STOP=1 -f "$f" || break
done
```

`SUPABASE_CONNECTION_STRING` doit pointer vers la même base que
`SUPABASE_PGHOST` / `SUPABASE_PGPORT` / `SUPABASE_PGDATABASE` /
`SUPABASE_PGUSER` / `SUPABASE_PGPASSWORD` (voir `.env` / variables Render).

**Recommandé avant toute exécution en production** : lancer d'abord la
même séquence sur une base de test (ou une branche Supabase si
disponible) — voir section "Tests" du rapport `AUDIT_PHASE2.md`.

## Ce que ces migrations NE font PAS

- Elles ne suppriment aucune colonne ni aucune table existante.
- Elles ne valident pas immédiatement les contraintes `CHECK` ajoutées
  (`NOT VALID`) : une contrainte `NOT VALID` s'applique à toute nouvelle
  écriture mais n'est pas vérifiée contre les lignes déjà en base. C'est
  volontaire (§16 du brief : ne pas casser des données historiques sans
  migration préalable). Pour valider une contrainte plus tard, une fois les
  données historiques nettoyées :
  ```sql
  ALTER TABLE hotspot_users VALIDATE CONSTRAINT hotspot_users_bytes_in_nonneg;
  ```
- Elles ne modifient pas `api.php`, les scripts MikroTik, ni la logique
  métier.
- Elles ne créent pas de table `expenses` ni de table `sales` (distincte de
  `sales_log`) : aucune preuve d'usage dans le code actuel (voir
  `009_commercial_profiles_tickets.sql`).

## Rollback

Aucun script de rollback automatique n'est fourni : toutes les opérations
sont additives (ajout de colonnes/contraintes/index) ou des conversions de
type documentées comme sûres. En cas de problème sur une conversion de
type précise, revenir en arrière colonne par colonne, par exemple :

```sql
ALTER TABLE hotspot_snapshots ALTER COLUMN received_at TYPE TEXT;
```

## Prochaine étape suggérée (hors périmètre de cette phase)

Une fois ces migrations appliquées et validées en production, désactiver
progressivement les `CREATE TABLE IF NOT EXISTS` / `ALTER TABLE ADD COLUMN`
exécutés à chaque requête dans `api.php` (`ensure_hotspot_*_table()`,
`ensure_hotspot_snapshot_columns()`) au profit de ce pipeline de
migrations versionnées — voir §18 du brief et section "Risques" du
rapport.
