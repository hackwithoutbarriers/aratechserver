-- =============================================================================
-- 004_hotspot_expiry.sql
-- Table cible : hotspot_expiry (expiration des utilisateurs Hotspot)
-- =============================================================================
-- DOUBLON CONFIRMÉ (§8 du brief) : le dépôt contient DEUX définitions
-- incompatibles de cette table :
--   1) SQLite locale (ensure_hotspot_expiry_table, api.php ~L.271) :
--        colonne "user" (clé primaire)
--   2) Supabase/PostgreSQL (aucune fonction ensure_* dans le dépôt — table
--      supposée pré-existante côté Supabase) : le code utilise partout
--      "user_id" (INSERT ... (user_id, ...) / ON CONFLICT (user_id) /
--      SELECT ... WHERE user_id = ?), voir api.php ~L.1298-1376.
--
-- Cette migration ne touche PAS la base SQLite locale : elle est hors
-- périmètre strict de cette phase (§1 : "PostgreSQL ; Supabase" uniquement).
-- Côté Supabase, on standardise sur "user_id", qui est déjà la convention
-- réellement utilisée en production par le code — c'est donc l'option qui
-- ne casse rien.
--
-- Type de "expiry" : reste TEXT dans cette phase, volontairement. La
-- fonction get_user_expiry_from_router() (api.php ~L.211-269) a un chemin de
-- repli qui peut renvoyer le commentaire brut du routeur tel quel si aucun
-- des formats de date connus ne correspond (ligne "if ($comment !== '')
-- return $comment;"). Typer cette colonne en TIMESTAMPTZ aujourd'hui ferait
-- échouer l'écriture dans ce cas de repli — régression non acceptable
-- (§20). Recommandation : durcir ce repli dans api.php dans une phase
-- dédiée, PUIS migrer ce champ en TIMESTAMPTZ (migration de suivi).
-- "updated_at" est en revanche toujours généré par date('c') côté serveur
-- (ISO-8601 fiable) : conversion sûre en TIMESTAMPTZ.
-- =============================================================================

CREATE TABLE IF NOT EXISTS hotspot_expiry (
    user_id     TEXT PRIMARY KEY,
    expiry      TEXT NOT NULL,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE hotspot_expiry ADD COLUMN IF NOT EXISTS expiry TEXT;

-- Conversion sûre de updated_at si la colonne existe encore en TEXT.
DO $$
DECLARE
    col_type TEXT;
BEGIN
    SELECT data_type INTO col_type
    FROM information_schema.columns
    WHERE table_name = 'hotspot_expiry' AND column_name = 'updated_at';

    IF col_type IS NOT NULL AND col_type <> 'timestamp with time zone' THEN
        ALTER TABLE hotspot_expiry
            ALTER COLUMN updated_at TYPE TIMESTAMPTZ USING updated_at::timestamptz;
    END IF;
END $$;

ALTER TABLE hotspot_expiry ALTER COLUMN updated_at SET DEFAULT now();

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'hotspot_expiry_user_id_not_empty'
    ) THEN
        ALTER TABLE hotspot_expiry
            ADD CONSTRAINT hotspot_expiry_user_id_not_empty CHECK (user_id <> '') NOT VALID;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_hotspot_expiry_expiry ON hotspot_expiry (expiry);

INSERT INTO schema_migrations (version) VALUES ('004_hotspot_expiry') ON CONFLICT (version) DO NOTHING;
