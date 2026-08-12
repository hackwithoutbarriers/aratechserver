-- =============================================================================
-- 005_hotspot_commands.sql
-- Table cible : hotspot_commands (file de commandes MikroTik, H2/H3)
-- =============================================================================
-- Source : ensure_hotspot_commands_table() dans api.php (~L.323). Déjà bien
-- typée (BIGSERIAL, ON CONFLICT-friendly). Cette migration :
--   - reproduit la structure telle quelle (non destructif) ;
--   - convertit les colonnes de temps en TIMESTAMPTZ : elles sont TOUJOURS
--     générées côté serveur via date('c') (jamais saisies par un tiers),
--     donc conversion sûre (§14, §20) ;
--   - ajoute les contraintes et index explicitement demandés (§15/§16).
-- =============================================================================

CREATE TABLE IF NOT EXISTS hotspot_commands (
    id               BIGSERIAL PRIMARY KEY,
    action           TEXT NOT NULL,
    username         TEXT NOT NULL,
    payload          TEXT,
    status           TEXT NOT NULL DEFAULT 'PENDING',
    created_at       TEXT NOT NULL,
    processing_at    TEXT,
    processed_at     TEXT,
    router_identity  TEXT,
    result           TEXT,
    message          TEXT
);

ALTER TABLE hotspot_commands ADD COLUMN IF NOT EXISTS processing_at   TEXT;
ALTER TABLE hotspot_commands ADD COLUMN IF NOT EXISTS router_identity TEXT;
ALTER TABLE hotspot_commands ADD COLUMN IF NOT EXISTS result          TEXT;
ALTER TABLE hotspot_commands ADD COLUMN IF NOT EXISTS message         TEXT;

UPDATE hotspot_commands SET status = UPPER(status) WHERE status <> UPPER(status);

-- Conversion sûre TEXT -> TIMESTAMPTZ (valeurs toujours générées par date('c')).
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN
        SELECT column_name FROM information_schema.columns
        WHERE table_name = 'hotspot_commands'
          AND column_name IN ('created_at','processing_at','processed_at')
          AND data_type <> 'timestamp with time zone'
    LOOP
        EXECUTE format(
            'ALTER TABLE hotspot_commands ALTER COLUMN %I TYPE TIMESTAMPTZ USING NULLIF(%I, '''')::timestamptz',
            r.column_name, r.column_name
        );
    END LOOP;
END $$;

ALTER TABLE hotspot_commands ALTER COLUMN created_at SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'hotspot_commands_action_valid') THEN
        ALTER TABLE hotspot_commands
            ADD CONSTRAINT hotspot_commands_action_valid
            CHECK (action IN ('create','update','enable','disable','delete')) NOT VALID;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'hotspot_commands_status_valid') THEN
        ALTER TABLE hotspot_commands
            ADD CONSTRAINT hotspot_commands_status_valid
            CHECK (UPPER(status) IN ('PENDING','PROCESSING','EXECUTED','FAILED')) NOT VALID;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_hotspot_commands_status_created
    ON hotspot_commands (status, created_at);

INSERT INTO schema_migrations (version) VALUES ('005_hotspot_commands') ON CONFLICT (version) DO NOTHING;
