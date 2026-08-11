-- =============================================================================
-- 002_hotspot_users.sql
-- Table cible : hotspot_users (utilisateurs Hotspot synchronisés depuis MikroTik)
-- =============================================================================
-- Source de vérité côté code : ensure_hotspot_users_table() dans api.php.
-- Cette migration reproduit son besoin actuel ET ajoute les colonnes requises
-- par §5 du brief (préparation du futur import CSV Time Limit / Data Limit),
-- sans rien casser côté PHP :
--   - toutes les colonnes déjà lues/écrites par api.php restent inchangées
--     (mêmes noms, mêmes types) ;
--   - les colonnes ajoutées (limit_uptime, limit_bytes_total, last_sync) sont
--     nouvelles : aucun code existant n'y écrit ni n'en dépend aujourd'hui,
--     donc aucun risque de régression.
--
-- Choix de type pour limit_uptime : INTERVAL plutôt que TEXT ou BIGINT brut.
-- C'est le type PostgreSQL natif pour une durée ("Time Limit" MikroTik,
-- ex. 10h, 1j) ; BIGINT aurait imposé une convention implicite (secondes ?
-- millisecondes ?) non documentée nulle part dans le dépôt actuel.
-- limit_bytes_total suit la règle explicite du brief (§5) : BIGINT, comme
-- bytes_in/bytes_out déjà en place.
-- =============================================================================

CREATE TABLE IF NOT EXISTS hotspot_users (
    username     TEXT PRIMARY KEY,
    password     TEXT,
    profile      TEXT,
    mac_address  TEXT,
    comment      TEXT,
    disabled     TEXT NOT NULL DEFAULT 'false',
    bytes_in     BIGINT NOT NULL DEFAULT 0,
    bytes_out    BIGINT NOT NULL DEFAULT 0,
    uptime       TEXT,
    server       TEXT
);

-- Colonnes déjà attendues par le code (ALTER idempotents, au cas où une
-- base existante aurait été créée avant l'ajout d'une de ces colonnes) :
ALTER TABLE hotspot_users ADD COLUMN IF NOT EXISTS password    TEXT;
ALTER TABLE hotspot_users ADD COLUMN IF NOT EXISTS profile     TEXT;
ALTER TABLE hotspot_users ADD COLUMN IF NOT EXISTS mac_address TEXT;
ALTER TABLE hotspot_users ADD COLUMN IF NOT EXISTS comment     TEXT;
ALTER TABLE hotspot_users ADD COLUMN IF NOT EXISTS disabled    TEXT NOT NULL DEFAULT 'false';
ALTER TABLE hotspot_users ADD COLUMN IF NOT EXISTS bytes_in    BIGINT NOT NULL DEFAULT 0;
ALTER TABLE hotspot_users ADD COLUMN IF NOT EXISTS bytes_out   BIGINT NOT NULL DEFAULT 0;
ALTER TABLE hotspot_users ADD COLUMN IF NOT EXISTS uptime      TEXT;
ALTER TABLE hotspot_users ADD COLUMN IF NOT EXISTS server      TEXT;

-- Nouvelles colonnes — préparation import CSV (§5, §22). Non utilisées par
-- le code aujourd'hui : ajout pur, non destructif.
ALTER TABLE hotspot_users ADD COLUMN IF NOT EXISTS limit_uptime      INTERVAL;
ALTER TABLE hotspot_users ADD COLUMN IF NOT EXISTS limit_bytes_total BIGINT;
ALTER TABLE hotspot_users ADD COLUMN IF NOT EXISTS last_sync         TIMESTAMPTZ;

-- Contraintes (§16). CHECK ajoutés avec NOT VALID puis validés séparément :
-- si des lignes historiques violaient la contrainte, l'ALTER ne casse pas
-- les écritures en cours, seule une future VALIDATE échouerait (et resterait
-- à traiter au cas par cas plutôt que de bloquer le déploiement).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'hotspot_users_username_not_empty'
    ) THEN
        ALTER TABLE hotspot_users
            ADD CONSTRAINT hotspot_users_username_not_empty CHECK (username <> '') NOT VALID;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'hotspot_users_bytes_in_nonneg'
    ) THEN
        ALTER TABLE hotspot_users
            ADD CONSTRAINT hotspot_users_bytes_in_nonneg CHECK (bytes_in >= 0) NOT VALID;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'hotspot_users_bytes_out_nonneg'
    ) THEN
        ALTER TABLE hotspot_users
            ADD CONSTRAINT hotspot_users_bytes_out_nonneg CHECK (bytes_out >= 0) NOT VALID;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'hotspot_users_limit_bytes_nonneg'
    ) THEN
        ALTER TABLE hotspot_users
            ADD CONSTRAINT hotspot_users_limit_bytes_nonneg CHECK (limit_bytes_total >= 0) NOT VALID;
    END IF;
END $$;

-- Index (§15) : recherches confirmées dans le code (admin/users.php, sync,
-- filtres par profil) et champ de fraîcheur last_sync.
CREATE INDEX IF NOT EXISTS idx_hotspot_users_profile   ON hotspot_users (profile);
CREATE INDEX IF NOT EXISTS idx_hotspot_users_last_sync ON hotspot_users (last_sync);
-- Pas d'index séparé sur username : déjà PRIMARY KEY (index automatique).

INSERT INTO schema_migrations (version) VALUES ('002_hotspot_users') ON CONFLICT (version) DO NOTHING;
