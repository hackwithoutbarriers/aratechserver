-- =============================================================================
-- 015_hotspot_inventory.sql
-- Stock applicatif des tickets/utilisateurs importés depuis Mikhmon.
--
-- hotspot_users reste le miroir opérationnel MikroTik.
-- hotspot_inventory représente le stock commercial/importé :
--   AVAILABLE = encore dans le stock
--   USED       = le compte a réellement été vu dans sales_log après import
--
-- On ne supprime pas l'historique consommé : il est conservé pour audit.
-- Aucune modification MikroTik n'est requise.
-- =============================================================================

CREATE TABLE IF NOT EXISTS hotspot_inventory (
    username        TEXT PRIMARY KEY,
    profile         TEXT,
    source          TEXT NOT NULL DEFAULT 'MIKHMON_CSV',
    imported_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    status          TEXT NOT NULL DEFAULT 'AVAILABLE',
    consumed_at     TIMESTAMPTZ,
    consumed_reason TEXT,
    metadata        JSONB NOT NULL DEFAULT '{}'::jsonb
);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'hotspot_inventory_status_valid'
    ) THEN
        ALTER TABLE hotspot_inventory
            ADD CONSTRAINT hotspot_inventory_status_valid
            CHECK (status IN ('AVAILABLE','USED')) NOT VALID;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_hotspot_inventory_status_imported
    ON hotspot_inventory (status, imported_at);
CREATE INDEX IF NOT EXISTS idx_hotspot_inventory_profile
    ON hotspot_inventory (profile);
CREATE INDEX IF NOT EXISTS idx_hotspot_inventory_consumed_at
    ON hotspot_inventory (consumed_at);

INSERT INTO schema_migrations (version)
VALUES ('015_hotspot_inventory')
ON CONFLICT (version) DO NOTHING;
