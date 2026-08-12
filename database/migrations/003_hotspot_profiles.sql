-- =============================================================================
-- 003_hotspot_profiles.sql
-- Table cible : hotspot_profiles (profils MikroTik synchronisés — technique)
-- =============================================================================
-- IMPORTANT : contrairement à ce que supposait le brief initial (§4), l'audit
-- du code n'a trouvé QU'UNE SEULE définition de hotspot_profiles dans le
-- dépôt (api.php, route sync-profiles, ~L.1839). Aucune deuxième définition
-- concurrente n'existe dans le code source. Le doublon éventuel, s'il existe,
-- ne peut donc vivre que directement dans le schéma Supabase (hors dépôt) :
-- ce fichier ne peut pas le "réparer" à l'aveugle, il aligne la base sur
-- l'unique définition trouvée dans le code, de façon non destructive.
--
-- profile_name reste la clé primaire naturelle (pas de clé artificielle),
-- conformément à §6, et compatible avec le ON CONFLICT (profile_name) déjà
-- utilisé par sync-profiles.
-- =============================================================================

CREATE TABLE IF NOT EXISTS hotspot_profiles (
    profile_name  TEXT PRIMARY KEY,
    shared_users  INTEGER NOT NULL DEFAULT 1,
    rate_limit    TEXT,
    on_login      TEXT,
    address_pool  TEXT
);

ALTER TABLE hotspot_profiles ADD COLUMN IF NOT EXISTS shared_users INTEGER NOT NULL DEFAULT 1;
ALTER TABLE hotspot_profiles ADD COLUMN IF NOT EXISTS rate_limit   TEXT;
ALTER TABLE hotspot_profiles ADD COLUMN IF NOT EXISTS on_login     TEXT;
ALTER TABLE hotspot_profiles ADD COLUMN IF NOT EXISTS address_pool TEXT;

-- Nouvelle colonne demandée par §6, absente du code actuel (aucune route ne
-- l'écrit aujourd'hui : ajout pur, non destructif — voir Risques §H du
-- rapport pour la recommandation de compléter sync-profiles plus tard).
ALTER TABLE hotspot_profiles ADD COLUMN IF NOT EXISTS last_sync TIMESTAMPTZ;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'hotspot_profiles_name_not_empty'
    ) THEN
        ALTER TABLE hotspot_profiles
            ADD CONSTRAINT hotspot_profiles_name_not_empty CHECK (profile_name <> '') NOT VALID;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'hotspot_profiles_shared_users_pos'
    ) THEN
        ALTER TABLE hotspot_profiles
            ADD CONSTRAINT hotspot_profiles_shared_users_pos CHECK (shared_users > 0) NOT VALID;
    END IF;
END $$;

INSERT INTO schema_migrations (version) VALUES ('003_hotspot_profiles') ON CONFLICT (version) DO NOTHING;
