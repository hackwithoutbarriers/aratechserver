-- =============================================================================
-- 008_sales_log.sql
-- Table cible : sales_log (journal technique des ventes MikroTik/on-login)
-- =============================================================================
-- Distinction fonctionnelle (§11) : sales_log N'EST PAS un doublon de
-- "sales". Aucune table "sales" n'a été trouvée dans le code (voir
-- 009_commercial_profiles_tickets.sql et le rapport, section E) : la
-- seule couche "ventes" réellement utilisée par l'API et le dashboard
-- (routes log-sale, get-sales, dashboard) est sales_log, alimentée
-- directement par le script on-login MikroTik au moment de la connexion
-- d'un voucher. C'est donc la source de vérité actuelle du chiffre
-- d'affaires, pas un doublon à fusionner.
--
-- Comme pour router_logs, aucun ensure_*_table() n'existe dans le dépôt :
-- cette table est supposée pré-existante côté Supabase. Migration non
-- destructive, alignée sur les colonnes réellement utilisées par le code.
--
-- sale_date/sale_time restent en TEXT (contrairement à router_logs) :
-- contrairement à log_date/log_time, ces deux champs proviennent
-- directement du payload JSON envoyé par le script on-login MikroTik
-- (route log-sale, api.php ~L.1501-1519) SANS validation de format côté
-- PHP. Les typer en DATE/TIME ferait échouer l'insertion sur toute valeur
-- imprévue et casserait l'enregistrement des ventes en production (§20) —
-- recommandation : ajouter la validation dans une phase dédiée MikroTik/
-- API, puis migrer ces colonnes.
-- amount : entier (francs CFA), conformément à §14 (pas de NUMERIC
-- inutile).
-- =============================================================================

CREATE TABLE IF NOT EXISTS sales_log (
    id           BIGSERIAL PRIMARY KEY,
    sale_date    TEXT NOT NULL,
    sale_time    TEXT,
    username     TEXT NOT NULL,
    amount       INTEGER NOT NULL DEFAULT 0,
    ip           TEXT,
    mac          TEXT,
    profile      TEXT,
    comment      TEXT,
    received_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

DO $$
DECLARE col_type TEXT;
BEGIN
    SELECT data_type INTO col_type FROM information_schema.columns
    WHERE table_name = 'sales_log' AND column_name = 'amount';
    IF col_type IS NOT NULL AND col_type NOT IN ('integer','bigint') THEN
        ALTER TABLE sales_log
            ALTER COLUMN amount TYPE INTEGER USING NULLIF(amount::text, '')::integer;
    END IF;

    SELECT data_type INTO col_type FROM information_schema.columns
    WHERE table_name = 'sales_log' AND column_name = 'received_at';
    IF col_type IS NOT NULL AND col_type <> 'timestamp with time zone' THEN
        ALTER TABLE sales_log
            ALTER COLUMN received_at TYPE TIMESTAMPTZ USING received_at::timestamptz;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'sales_log_amount_nonneg') THEN
        ALTER TABLE sales_log ADD CONSTRAINT sales_log_amount_nonneg CHECK (amount >= 0) NOT VALID;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'sales_log_username_not_empty') THEN
        ALTER TABLE sales_log ADD CONSTRAINT sales_log_username_not_empty CHECK (username <> '') NOT VALID;
    END IF;
END $$;

-- Index (§15) : filtres/aggrégations confirmés (get-sales, dashboard,
-- get-sales-daily) par plage de sale_date, et par username pour l'audit.
CREATE INDEX IF NOT EXISTS idx_sales_log_username    ON sales_log (username);
CREATE INDEX IF NOT EXISTS idx_sales_log_received_at ON sales_log (received_at);
CREATE INDEX IF NOT EXISTS idx_sales_log_sale_date    ON sales_log (sale_date);

INSERT INTO schema_migrations (version) VALUES ('008_sales_log') ON CONFLICT (version) DO NOTHING;
