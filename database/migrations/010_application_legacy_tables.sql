-- =============================================================================
-- 010_application_legacy_tables.sql
-- Finalisation de la migration SQLite -> Supabase/PostgreSQL
-- =============================================================================
-- Les routes historiques ads / loyalty / tracking utilisent encore ces tables.
-- Elles deviennent donc explicitement des tables Supabase, sans aucun stockage
-- local dans le conteneur Render.
-- =============================================================================

CREATE TABLE IF NOT EXISTS ads (
    id          TEXT PRIMARY KEY,
    type        TEXT NOT NULL DEFAULT 'sponsored',
    title       TEXT NOT NULL,
    description TEXT,
    image       TEXT,
    url         TEXT,
    start       DATE,
    "end"      DATE,
    active      BOOLEAN NOT NULL DEFAULT TRUE,
    price       INTEGER,
    views       INTEGER NOT NULL DEFAULT 0,
    clicks      INTEGER NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS track_events (
    id          BIGSERIAL PRIMARY KEY,
    item_id     TEXT NOT NULL,
    event_type  TEXT NOT NULL,
    "user"      TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS loyalty (
    "user"        TEXT PRIMARY KEY,
    points        INTEGER NOT NULL DEFAULT 0,
    topups        INTEGER NOT NULL DEFAULT 0,
    referral_code TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_ads_active_dates
    ON ads (active, start, "end");

CREATE INDEX IF NOT EXISTS idx_track_events_item_type
    ON track_events (item_id, event_type, created_at);

-- Compatibility bridge for the historical api.php expiry fallback.
-- Phase 2 standardized the production key on user_id. The legacy fallback
-- still uses user, so both names must address the same row while the fallback
-- code is being retired in a later cleanup pass.
ALTER TABLE hotspot_expiry
    ADD COLUMN IF NOT EXISTS "user" TEXT;

CREATE OR REPLACE FUNCTION sync_hotspot_expiry_legacy_user()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.user_id := COALESCE(NEW.user_id, NEW."user");
    NEW."user" := COALESCE(NEW."user", NEW.user_id);
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_sync_hotspot_expiry_legacy_user ON hotspot_expiry;
CREATE TRIGGER trg_sync_hotspot_expiry_legacy_user
BEFORE INSERT OR UPDATE ON hotspot_expiry
FOR EACH ROW
EXECUTE FUNCTION sync_hotspot_expiry_legacy_user();

CREATE UNIQUE INDEX IF NOT EXISTS idx_hotspot_expiry_legacy_user
    ON hotspot_expiry ("user")
    WHERE "user" IS NOT NULL;

INSERT INTO schema_migrations (version)
VALUES ('010_application_legacy_tables')
ON CONFLICT (version) DO NOTHING;
