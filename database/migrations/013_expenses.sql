-- =============================================================================
-- 013_expenses.sql
-- Dépenses réelles, pour remplacer les constantes codées en dur de
-- admin/finances.php (rapport d'audit §3.3 : "aucune preuve d'usage dans le
-- code actuel" pour une table expenses — confirmé aussi par la note dans
-- 009_commercial_profiles_tickets.sql, qui l'avait explicitement exclue de
-- son périmètre à l'époque).
-- =============================================================================
-- Une fois cette table peuplée, le "bénéfice réel" de finances.php devient
-- une vraie requête : SUM(sales_log.amount) - SUM(expenses.amount) sur la
-- période choisie, au lieu des deux constantes actuelles.
-- =============================================================================

CREATE TABLE IF NOT EXISTS expenses (
    id           BIGSERIAL PRIMARY KEY,
    expense_date DATE NOT NULL,
    description  TEXT NOT NULL,
    category     TEXT NOT NULL,
    amount       INTEGER NOT NULL CHECK (amount >= 0),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'expenses_description_not_empty') THEN
        ALTER TABLE expenses ADD CONSTRAINT expenses_description_not_empty CHECK (description <> '') NOT VALID;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'expenses_category_valid') THEN
        ALTER TABLE expenses ADD CONSTRAINT expenses_category_valid
            CHECK (category IN ('Internet', 'Électricité', 'Matériel', 'Loyer', 'Autre')) NOT VALID;
    END IF;
END $$;

-- Filtre par période (rapports mensuels) et par catégorie (répartition des
-- coûts) : les deux axes de lecture attendus dans finances.php.
CREATE INDEX IF NOT EXISTS idx_expenses_date     ON expenses (expense_date);
CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses (category);

INSERT INTO schema_migrations (version) VALUES ('013_expenses') ON CONFLICT (version) DO NOTHING;
