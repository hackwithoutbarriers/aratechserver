-- =============================================================================
-- 014_sales_transactions.sql
-- Canonical commercial ledger.
--
-- sales_log is a technical/on-login journal and must never be used as the
-- business source of truth. Every confirmed sale gets exactly one row here,
-- identified by transaction_id.
-- =============================================================================

CREATE TABLE IF NOT EXISTS sales_transactions (
    id                    BIGSERIAL PRIMARY KEY,
    transaction_id        TEXT NOT NULL,
    sale_date             TEXT NOT NULL,
    sale_time             TEXT,
    username              TEXT NOT NULL,
    amount                INTEGER NOT NULL DEFAULT 0,
    currency              TEXT NOT NULL DEFAULT 'XOF',
    ip                    TEXT,
    mac                   TEXT,
    profile               TEXT,
    comment               TEXT,
    voucher_expires_at    TEXT,
    status                TEXT NOT NULL DEFAULT 'PAID',
    is_business_sale      BOOLEAN NOT NULL DEFAULT TRUE,
    source                TEXT NOT NULL DEFAULT 'MIKROTIK',
    inferred              BOOLEAN NOT NULL DEFAULT FALSE,
    metadata              JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'sales_transactions_transaction_id_uniq'
    ) THEN
        ALTER TABLE sales_transactions
            ADD CONSTRAINT sales_transactions_transaction_id_uniq
            UNIQUE (transaction_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'sales_transactions_amount_nonneg'
    ) THEN
        ALTER TABLE sales_transactions
            ADD CONSTRAINT sales_transactions_amount_nonneg
            CHECK (amount >= 0) NOT VALID;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'sales_transactions_status_valid'
    ) THEN
        ALTER TABLE sales_transactions
            ADD CONSTRAINT sales_transactions_status_valid
            CHECK (status IN ('PAID','VOID','REFUNDED')) NOT VALID;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_sales_transactions_sale_date
    ON sales_transactions (sale_date);
CREATE INDEX IF NOT EXISTS idx_sales_transactions_username
    ON sales_transactions (username);
CREATE INDEX IF NOT EXISTS idx_sales_transactions_status_date
    ON sales_transactions (status, sale_date);
CREATE INDEX IF NOT EXISTS idx_sales_transactions_profile_date
    ON sales_transactions (profile, sale_date);

-- One-time historical reconstruction.
-- This is explicitly marked inferred because the legacy journal contains
-- repeated on-login events, not authoritative payment transactions.
-- When a historical voucher has several observed amounts, the highest amount
-- is selected because the export demonstrates legacy price drift (e.g. 24H
-- rows at 100 and 200); this remains an inferred value and is shown as such.
INSERT INTO sales_transactions (
    transaction_id, sale_date, sale_time, username, amount, currency,
    ip, mac, profile, comment, voucher_expires_at, status,
    is_business_sale, source, inferred, metadata, created_at, updated_at
)
SELECT
    'LEGACY-' || md5(
        COALESCE(username, '') || E'\x1f' ||
        COALESCE(comment, '') || E'\x1f' ||
        COALESCE(profile, '')
    ) AS transaction_id,
    legacy.sale_date,
    legacy.sale_time,
    legacy.username,
    legacy.amount,
    'XOF',
    legacy.ip,
    legacy.mac,
    legacy.profile,
    legacy.comment,
    legacy.comment,
    'PAID',
    CASE
        WHEN lower(trim(COALESCE(legacy.profile, ''))) IN ('test','testing','demo') THEN FALSE
        ELSE TRUE
    END,
    'LEGACY_INFERRED',
    TRUE,
    jsonb_build_object(
        'legacy_sales_log_id', legacy.id,
        'inference_rule', 'highest observed amount per username + comment; earliest row for tie',
        'warning', 'historical activation proxy; not a payment gateway transaction'
    ),
    COALESCE(legacy.received_at, now()),
    now()
FROM (
    SELECT DISTINCT ON (username, comment)
        id, sale_date, sale_time, username, amount, ip, mac,
        profile, comment, received_at
    FROM sales_log
    WHERE amount > 0
      AND username <> ''
      AND comment <> ''
    ORDER BY username, comment, amount DESC, received_at ASC NULLS FIRST, id ASC
) AS legacy
ON CONFLICT (transaction_id) DO NOTHING;

INSERT INTO schema_migrations (version)
VALUES ('014_sales_transactions')
ON CONFLICT (version) DO NOTHING;
