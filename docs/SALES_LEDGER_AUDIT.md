# ARA Tech WiFi — Sales Ledger Audit

## 1. Root cause

The legacy `sales_log` table is a technical `on-login` journal, not a payment ledger. The supplied MikroTik script called `log-sale` on every login/re-login and created a Mikhmon `system script` on every login.

Therefore:

- `COUNT(sales_log)` is not tickets sold.
- `SUM(sales_log.amount)` is not reliable CA.
- reconnects inflate sales.
- a historical username-based deduplication can also hide legitimate repeat purchases.

## 2. Evidence from the supplied export

`/mnt/data/sales_log_rows.csv` contains 210 raw events across 2026-08-11 to 2026-08-14.

Observed facts:

- 41 unique `username + comment` identities.
- 61 rows use profile `test` with amount 100; they are not business revenue.
- 130 rows are `10H` at 100.
- 5 rows are `24H`; both 100 and 200 amounts appear.
- 14 rows are `Abonnement` at 1000.
- The same voucher can produce many rows; e.g. `Asahel1ER` appears 29 times.

The raw 210 rows therefore cannot be used as sales.

## 3. Correct business model

`database/migrations/014_sales_transactions.sql` introduces the canonical ledger.

A business sale is one transaction with:

- unique `transaction_id`;
- amount/currency;
- sale date/time;
- username;
- profile;
- voucher expiry;
- status (`PAID`, `VOID`, `REFUNDED`);
- `is_business_sale`;
- source and audit metadata;
- `inferred` for historical reconstruction.

Business KPI queries read only `sales_transactions` with `status='PAID'` and `is_business_sale=TRUE`.

## 4. Idempotency

`record-sale.php` accepts an explicit transaction id and rejects conflicting reuse. Retries of the same event are ignored. The same username can legitimately have multiple transactions because transaction identity is event-based, not username-based.

## 5. MikroTik logic

The new `mikrotik-scripts/on-login-sale-handler.rsc` separates:

1. expiry synchronization (allowed on every login/re-login), and
2. commercial recording (only when the configured new-activation marker is present).

The handler reads the actual Hotspot profile instead of hard-coding `10H` for every event, and it does not create Mikhmon sale-history entries on reconnects.

The price map is currently based on values observed in the provided data (`10H=100`, `24H=200`, `Abonnement=1000`). Verify these against the live Mikhmon price configuration before deployment.

## 6. Historical data

Migration 014 reconstructs one inferred historical transaction per `username + comment`, using the highest observed amount for that identity and the earliest row when amounts tie. Historical rows are marked `inferred=true` and `source='LEGACY_INFERRED'` because the old journal cannot prove payment identity.

This is a reconciliation baseline, not a claim that the historical ledger is a payment gateway ledger.

## 7. Operational truth after rollout

- Mikhmon is the operational sale interface.
- MikroTik emits a single business event for a new activation.
- `sales_transactions` is the business source of truth.
- `sales_log` remains a technical/audit journal.
- Dashboard, Business, Rapports and Finances consume the same transaction ledger.

## 8. Live rollout checklist

1. Apply migration `014_sales_transactions.sql` in Supabase.
2. Deploy the PHP code with `record-sale.php` and the canonical business-sales reader.
3. Replace the current HotSpot `on-login` logic with the reviewed handler, after setting the real API key and verifying the price map.
4. Test one sale: one `sales_transactions` row.
5. Reconnect the same voucher: zero new business transactions.
6. Make a second legitimate sale: a second `transaction_id` and second transaction.
7. Compare the Mikhmon day total with `sales_transactions` for the same date.

Rotate any database password or RouterOS/API key that was exposed during the audit before production rollout.
