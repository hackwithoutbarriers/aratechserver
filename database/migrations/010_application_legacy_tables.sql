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
    "user"       TEXT PRIMARY KEY,
    points       INTEGER NOT NULL DEFAULT 0,
    topups       INTEGER NOT NULL DEFAULT 0,
    referral_code TEXT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_ads_active_dates
    ON ads (active, start, "end");

CREATE INDEX IF NOT EXISTS idx_track_events_item_type
    ON track_events (item_id, event_type, created_at);

INSERT INTO schema_migrations (version)
VALUES ('010_application_legacy_tables')
ON CONFLICT (version) DO NOTHING;
