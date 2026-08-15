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
