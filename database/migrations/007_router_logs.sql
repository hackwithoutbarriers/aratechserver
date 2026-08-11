-- =============================================================================
-- 007_router_logs.sql
-- Table cible : router_logs (logs bruts poussés par le routeur — push-logs)
-- =============================================================================
-- ANOMALIE (§20, §10) : aucune fonction ensure_router_logs_table() ni
-- CREATE TABLE n'existe nulle part dans le dépôt pour cette table, alors
-- qu'elle est utilisée en lecture/écriture (api.php ~L.1400-1447) avec un
-- ON CONFLICT (log_date, log_time, message) — ce qui exige, côté Postgres,
-- une vraie contrainte UNIQUE (ou un index unique) sur exactement ce
-- triplet, sinon l'INSERT échoue à l'exécution. Cette table existe donc
-- forcément déjà côté Supabase (créée manuellement, hors dépôt), avec un
-- schéma non versionné. Cette migration :
--   - crée la table si elle est absente (CREATE TABLE IF NOT EXISTS, donc
--     sans effet si elle existe déjà) ;
--   - garantit la contrainte UNIQUE nécessaire au ON CONFLICT du code,
--     qu'elle existe déjà ou non (vérification avant ajout).
--
-- log_date et log_time sont validés côté PHP avant insertion (normalize_
-- router_date() -> toujours 'Y-m-d' ; log_time filtré par regex
-- ^\d{2}:\d{2}:\d{2}$) : typage DATE/TIME sûr. received_at est généré par
-- date('c') côté serveur : TIMESTAMPTZ sûr.
-- =============================================================================

CREATE TABLE IF NOT EXISTS router_logs (
    id           BIGSERIAL PRIMARY KEY,
    log_date     DATE NOT NULL,
    log_time     TIME NOT NULL,
    topics       TEXT,
    message      TEXT NOT NULL,
    received_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Si la table existait déjà (hors dépôt) avec des types texte, on les
-- convertit prudemment.
DO $$
DECLARE col_type TEXT;
BEGIN
    SELECT data_type INTO col_type FROM information_schema.columns
    WHERE table_name = 'router_logs' AND column_name = 'log_date';
    IF col_type IS NOT NULL AND col_type <> 'date' THEN
        ALTER TABLE router_logs ALTER COLUMN log_date TYPE DATE USING log_date::date;
    END IF;

    SELECT data_type INTO col_type FROM information_schema.columns
    WHERE table_name = 'router_logs' AND column_name = 'log_time';
    IF col_type IS NOT NULL AND col_type <> 'time without time zone' THEN
        ALTER TABLE router_logs ALTER COLUMN log_time TYPE TIME USING log_time::time;
    END IF;

    SELECT data_type INTO col_type FROM information_schema.columns
    WHERE table_name = 'router_logs' AND column_name = 'received_at';
    IF col_type IS NOT NULL AND col_type <> 'timestamp with time zone' THEN
        ALTER TABLE router_logs ALTER COLUMN received_at TYPE TIMESTAMPTZ USING received_at::timestamptz;
    END IF;
END $$;

-- Contrainte UNIQUE requise par le ON CONFLICT du code (ajoutée seulement
-- si absente, quel que soit son nom actuel).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint c
        JOIN pg_class t ON t.oid = c.conrelid
        WHERE t.relname = 'router_logs' AND c.contype = 'u'
          AND c.conkey = (
              SELECT array_agg(a.attnum ORDER BY a.attnum)
              FROM pg_attribute a
              WHERE a.attrelid = t.oid AND a.attname IN ('log_date','log_time','message')
          )
    ) THEN
        ALTER TABLE router_logs
            ADD CONSTRAINT router_logs_date_time_message_uniq UNIQUE (log_date, log_time, message);
    END IF;
END $$;

-- Index (§10, §15) : recherches confirmées dans admin/logs.php côté API
-- (filtre par date + topic, tri par heure).
CREATE INDEX IF NOT EXISTS idx_router_logs_received_at ON router_logs (received_at);
CREATE INDEX IF NOT EXISTS idx_router_logs_log_date     ON router_logs (log_date);
CREATE INDEX IF NOT EXISTS idx_router_logs_message      ON router_logs (message);

INSERT INTO schema_migrations (version) VALUES ('007_router_logs') ON CONFLICT (version) DO NOTHING;
