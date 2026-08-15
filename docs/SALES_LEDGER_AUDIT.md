# ARA Tech WiFi — Sales Ledger Audit

## Root cause confirmed

The legacy `sales_log` table is a technical journal populated from MikroTik `on-login`. The supplied script calls the old `log-sale` endpoint on every login/re-login and also creates a Mikhmon `system script` entry on every login.

Therefore:

- `COUNT(sales_log)` is not "tickets sold".
- `SUM(sales_log.amount)` is not a reliable business CA.
- A voucher can produce multiple technical rows without another purchase.
- A second legitimate purchase must also be possible for the same username; therefore `username + comment` is not a valid permanent transaction key.

## Evidence from the supplied export

The provided `sales_log_rows.csv` contains 210 raw events across 11–14 August 2026, but only 41 unique `username + comment` identities. The sample contains 61 `test` events, which must not become commercial revenue.

Observed profile/amount combinations in the export:

- `10H`: 130 raw events at 100 XOF.
- `24H`: 5 raw events, including 4 × 100 XOF and 1 × 200 XOF.
- `Abonnement`: 14 raw events at 1000 XOF.
- `test`: 61 raw events at 100 XOF.

This proves that the legacy `amount=100` hard-coding is not a safe source of commercial truth.

## New model

`database/migrations/014_sales_transactions.sql` introduces `sales_transactions`.

One confirmed sale = one row with:

- unique `transaction_id`;
- sale date/time;
- username/voucher;
- amount + currency;
- profile;
- voucher expiry;
- `status` (`PAID`, `VOID`, `REFUNDED`);
- `is_business_sale`;
- source;
- `inferred` for historical reconstruction;
- JSON metadata for auditability.

The business application reads only `sales_transactions` where `status='PAID'` and `is_business_sale=TRUE`.

## Idempotency

`record-sale.php` accepts a client `transaction_id`. Repeating the same request is safe and returns `duplicate=true`. If the client omits a transaction id, the backend deterministically derives one from the event payload so HTTP retries do not create another row.

Two legitimate purchases by the same username are allowed because transaction identity is event-based, not username-based.

## MikroTik change

The old `log-sale` call must not run on every login. The corrected handler in `mikrotik-scripts/on-login-sale-handler.rsc` emits the commercial transaction only when the voucher enters the new-activation state (`vc`, `up`, or empty comment), while expiry synchronization remains allowed on every login.

The Mikhmon history script is also created only for a real sale, not for a reconnect.

## Historical data

Migration 014 creates inferred historical transactions from the first `sales_log` row per `username + comment`. These are explicitly marked `inferred=true` and `source='LEGACY_INFERRED'` because the legacy journal cannot prove payment identity.

This gives the application a stable historical baseline without pretending that the old data is a perfect payment ledger.

## Rollout order

1. Apply migration 014 in Supabase.
2. Deploy the application containing `record-sale.php` and the new ledger reader.
3. Replace the MikroTik on-login field with `mikrotik-scripts/on-login-sale-handler.rsc` (after replacing the placeholder sync key).
4. Test: one sale → one transaction; reconnect → zero additional transactions; second sale → second transaction.
5. Reconcile the Mikhmon day total against `sales_transactions` for the same business date.
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
x
