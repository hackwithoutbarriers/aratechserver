-- =============================================================================
-- 006_hotspot_snapshots.sql
-- Table cible : hotspot_snapshots (dernier état poussé par le routeur)
-- =============================================================================
-- Champs séparés snapshot_date/snapshot_time (§9) : le code en dépend
-- encore activement (admin/index.php et compute_router_status() les lisent
-- explicitement), donc ils NE SONT PAS supprimés. En revanche, ils sont
-- toujours générés côté serveur via date('Y-m-d') / date('H:i:s') (jamais
-- saisis par un tiers) : conversion sûre vers des types PostgreSQL natifs
-- DATE / TIME, qui restent lisibles à l'identique par le PHP existant
-- (DateTime::createFromFormat('Y-m-d H:i:s', ...) obtient toujours la même
-- chaîne en lecture via PDO).
-- received_at, toujours généré par date('c') côté serveur (ISO-8601
-- fiable, voir commentaire ligne ~854 d'api.php) : conversion sûre en
-- TIMESTAMPTZ.
-- =============================================================================

CREATE TABLE IF NOT EXISTS hotspot_snapshots (
    id             BIGSERIAL PRIMARY KEY,
    snapshot_date  TEXT NOT NULL,
    snapshot_time  TEXT NOT NULL,
    active_count   INTEGER NOT NULL,
    users_blob     TEXT,
    received_at    TEXT NOT NULL
);

-- Colonnes "Statut V2.1" (ensure_hotspot_snapshot_columns, api.php ~L.821)
ALTER TABLE hotspot_snapshots ADD COLUMN IF NOT EXISTS router_identity TEXT;
ALTER TABLE hotspot_snapshots ADD COLUMN IF NOT EXISTS router_uptime   TEXT;
ALTER TABLE hotspot_snapshots ADD COLUMN IF NOT EXISTS router_version  TEXT;
ALTER TABLE hotspot_snapshots ADD COLUMN IF NOT EXISTS cpu_load        DOUBLE PRECISION;
ALTER TABLE hotspot_snapshots ADD COLUMN IF NOT EXISTS memory_total    BIGINT;
ALTER TABLE hotspot_snapshots ADD COLUMN IF NOT EXISTS memory_free     BIGINT;
ALTER TABLE hotspot_snapshots ADD COLUMN IF NOT EXISTS users_json      JSONB;
ALTER TABLE hotspot_snapshots ADD COLUMN IF NOT EXISTS network_json    JSONB;

-- Conversions sûres (valeurs toujours générées côté serveur).
DO $$
DECLARE col_type TEXT;
BEGIN
    SELECT data_type INTO col_type FROM information_schema.columns
    WHERE table_name = 'hotspot_snapshots' AND column_name = 'snapshot_date';
    IF col_type IS NOT NULL AND col_type <> 'date' THEN
        ALTER TABLE hotspot_snapshots
            ALTER COLUMN snapshot_date TYPE DATE USING snapshot_date::date;
    END IF;

    SELECT data_type INTO col_type FROM information_schema.columns
    WHERE table_name = 'hotspot_snapshots' AND column_name = 'snapshot_time';
    IF col_type IS NOT NULL AND col_type <> 'time without time zone' THEN
        ALTER TABLE hotspot_snapshots
            ALTER COLUMN snapshot_time TYPE TIME USING snapshot_time::time;
    END IF;

    SELECT data_type INTO col_type FROM information_schema.columns
    WHERE table_name = 'hotspot_snapshots' AND column_name = 'received_at';
    IF col_type IS NOT NULL AND col_type <> 'timestamp with time zone' THEN
        ALTER TABLE hotspot_snapshots
            ALTER COLUMN received_at TYPE TIMESTAMPTZ USING received_at::timestamptz;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'hotspot_snapshots_active_count_nonneg') THEN
        ALTER TABLE hotspot_snapshots
            ADD CONSTRAINT hotspot_snapshots_active_count_nonneg CHECK (active_count >= 0) NOT VALID;
    END IF;
END $$;

-- Requête dominante : "dernier snapshot" (ORDER BY id DESC LIMIT 1) — déjà
-- couverte par la clé primaire. Un index sur received_at aide les vues
-- historiques/diagnostic éventuelles.
CREATE INDEX IF NOT EXISTS idx_hotspot_snapshots_received_at ON hotspot_snapshots (received_at);

INSERT INTO schema_migrations (version) VALUES ('006_hotspot_snapshots') ON CONFLICT (version) DO NOTHING;
