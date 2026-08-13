-- =============================================================================
-- 012_hotspot_removed_users.sql
-- Historique "Remove & record" : copie des utilisateurs hotspot juste avant
-- leur suppression physique du routeur (expiration ou action manuelle).
-- =============================================================================
-- Pourquoi cette table (rapport d'audit, §3.2) : hotspot_expiry (migration
-- 004) suit une date d'expiration par utilisateur, mais dès que
-- HotspotService::removeExpiredUser() supprime l'utilisateur côté MikroTik
-- ET côté hotspot_users (mode "Remove"), la ligne correspondante disparaît
-- du miroir Supabase sans laisser de trace exploitable. Cette table capture
-- un instantané complet juste avant suppression.
--
-- router_identity (et non router_id) : pas de dépendance à une table
-- `routers` (migration 011, non demandée à ce stade car un seul routeur est
-- géré aujourd'hui). On réutilise la même convention TEXT libre que
-- hotspot_commands / hotspot_snapshots pour router_identity, cohérente avec
-- l'existant. Si une table `routers` est introduite plus tard, une colonne
-- router_id pourra être ajoutée sans casser celle-ci (ALTER TABLE ADD COLUMN).
-- =============================================================================

CREATE TABLE IF NOT EXISTS hotspot_removed_users (
    id               BIGSERIAL PRIMARY KEY,
    username         TEXT NOT NULL,
    router_identity  TEXT,
    profile          TEXT,
    mac_address      TEXT,
    bytes_in         BIGINT,
    bytes_out        BIGINT,
    uptime_total     TEXT,
    expired_at       TIMESTAMPTZ,
    removed_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    removal_reason   TEXT NOT NULL DEFAULT 'expired',
    original_comment TEXT
);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'hotspot_removed_users_username_not_empty') THEN
        ALTER TABLE hotspot_removed_users
            ADD CONSTRAINT hotspot_removed_users_username_not_empty CHECK (username <> '') NOT VALID;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'hotspot_removed_users_reason_valid') THEN
        ALTER TABLE hotspot_removed_users
            ADD CONSTRAINT hotspot_removed_users_reason_valid
                CHECK (removal_reason IN ('expired', 'manual', 'replaced')) NOT VALID;
    END IF;
END $$;

-- Recherche par utilisateur (historique d'un client donné) et tri/purge
-- par date de suppression (rapports, nettoyage périodique).
CREATE INDEX IF NOT EXISTS idx_hotspot_removed_users_username   ON hotspot_removed_users (username);
CREATE INDEX IF NOT EXISTS idx_hotspot_removed_users_removed_at ON hotspot_removed_users (removed_at);

INSERT INTO schema_migrations (version) VALUES ('012_hotspot_removed_users') ON CONFLICT (version) DO NOTHING;
