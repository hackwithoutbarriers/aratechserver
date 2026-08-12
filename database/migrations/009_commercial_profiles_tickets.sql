-- =============================================================================
-- 009_commercial_profiles_tickets.sql
-- Tables cibles : profiles (produit commercial) et tickets (codes importés)
-- =============================================================================
-- BUG CRITIQUE IDENTIFIÉ (hors périmètre DB strict, signalé ici car il
-- explique pourquoi ce fichier existe) : admin/inventory.php appelle
-- ara_ensure_finance_tables($pdoSupa) à deux reprises (~L.47, ~L.147), mais
-- cette fonction n'est définie NULLE PART dans le dépôt (ni db.php, ni
-- api.php, ni aucun autre fichier). Tout appel à /admin/inventory.php
-- provoque donc une Fatal Error PHP (Call to undefined function) tant que
-- cette fonction n'existe pas. Cette migration ne corrige pas le code (hors
-- périmètre, §1), mais fournit le schéma SQL que cette fonction est censée
-- garantir, pour que la base soit prête dès que le correctif PHP sera
-- appliqué (phase ultérieure).
--
-- profiles = produit commercial vendu (distinct de hotspot_profiles =
-- profil technique MikroTik, §7 — les deux ne sont PAS fusionnées).
-- Colonnes déduites des seules requêtes réellement exécutées dans le code
-- (admin/inventory.php) : id, name. Aucune autre colonne n'est utilisée
-- nulle part : on n'invente rien au-delà (voir §23 sur le seed prix, non
-- traité ici faute de preuve dans le dépôt).
--
-- tickets.profile_id -> profiles.id : ON DELETE RESTRICT (un profil vendu
-- avec des tickets existants ne doit pas pouvoir être supprimé "en
-- silence" ; le code ne fait d'ailleurs aucune suppression de profils).
-- =============================================================================

CREATE TABLE IF NOT EXISTS profiles (
    id    BIGSERIAL PRIMARY KEY,
    name  TEXT NOT NULL
);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'profiles_name_uniq') THEN
        ALTER TABLE profiles ADD CONSTRAINT profiles_name_uniq UNIQUE (name);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'profiles_name_not_empty') THEN
        ALTER TABLE profiles ADD CONSTRAINT profiles_name_not_empty CHECK (name <> '') NOT VALID;
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS tickets (
    id           BIGSERIAL PRIMARY KEY,
    profile_id   BIGINT NOT NULL REFERENCES profiles(id) ON DELETE RESTRICT,
    code         TEXT NOT NULL,
    status       TEXT NOT NULL DEFAULT 'Disponible',
    imported_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tickets_code_uniq') THEN
        ALTER TABLE tickets ADD CONSTRAINT tickets_code_uniq UNIQUE (code);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tickets_code_not_empty') THEN
        ALTER TABLE tickets ADD CONSTRAINT tickets_code_not_empty CHECK (code <> '') NOT VALID;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tickets_status_valid') THEN
        ALTER TABLE tickets ADD CONSTRAINT tickets_status_valid
            CHECK (status IN ('Disponible','Vendu','Expiré')) NOT VALID;
    END IF;
END $$;

-- Index (§15) : recherche par code (déjà couverte par UNIQUE), filtre par
-- statut (compteurs de stock) et par profil (jointure tickets<->profiles).
CREATE INDEX IF NOT EXISTS idx_tickets_profile_id ON tickets (profile_id);
CREATE INDEX IF NOT EXISTS idx_tickets_status     ON tickets (status);

-- -----------------------------------------------------------------------
-- expenses : NON traitée dans cette migration.
-- -----------------------------------------------------------------------
-- Aucune trace de table "expenses" dans le code : admin/finances.php
-- utilise un tableau PHP statique codé en dur (§25, ligne 14), aucune
-- lecture/écriture SQL. Créer une table ici reviendrait à inventer un
-- schéma sans preuve d'usage réel (contraire au principe "ne pas
-- réinventer le projet"). Voir rapport, section Risques, pour la
-- recommandation de traiter ce point comme une nouvelle fonctionnalité
-- dans une phase dédiée, hors normalisation DB.
--
-- sales (table distincte de sales_log) : NON traitée pour la même raison
-- — aucune requête SQL dans le dépôt ne cible une table "sales".
-- -----------------------------------------------------------------------

INSERT INTO schema_migrations (version) VALUES ('009_commercial_profiles_tickets') ON CONFLICT (version) DO NOTHING;
