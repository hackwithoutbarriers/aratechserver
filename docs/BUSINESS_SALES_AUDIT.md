# ARA Tech — Business Sales Audit

## Root cause confirmed

The supplied MikroTik `on-login` script calls:

- `route=log-sale` on every login;
- with a hard-coded amount (`100` in the supplied script);
- with a hard-coded profile (`10H` in the supplied script).

Therefore `sales_log` is a technical login/activation journal, not a payment transaction ledger. A reconnect of the same voucher creates another `sales_log` row.

## Evidence from `sales_log_rows.csv`

210 rows were supplied for 2026-08-11 through 2026-08-14.

| Date | Raw log rows | Distinct users | Raw amount sum |
|---|---:|---:|---:|
| 2026-08-11 | 61 | 20 | 6,100 FCFA |
| 2026-08-12 | 59 | 15 | 5,900 FCFA |
| 2026-08-13 | 57 | 14 | 15,800 FCFA |
| 2026-08-14 | 33 | 15 | 6,200 FCFA |

Across the whole sample there are only **41 distinct usernames** and **41 distinct `(username, comment)` pairs**. The repeated rows are therefore overwhelmingly reconnect/login events.

Examples:

- `Asahel1ER`: 29 technical log rows across 4 dates with the same expiry comment.
- `wkdjh`: 11 rows for the same voucher/comment.
- `yyt2c`: 16 rows for the same voucher/comment.

## Canonical historical KPI rule

Until a real transaction ledger is available, the business UI uses the first activation for each `(username, comment)` pair as a historical activation proxy.

This is deliberately **not** described as confirmed payment revenue.

The canonical service is `lib/business_sales.php`.

## Required future source of truth

A future payment/checkout flow should write a dedicated transaction record with:

- `transaction_id` (unique);
- `voucher_username`;
- `profile`;
- `amount`;
- `payment_method`;
- `paid_at`;
- `status` (`PAID`, `CANCELLED`, `REFUNDED`);
- `source`.

Business KPIs should then use only `PAID` transactions.

## MikroTik correction

The supplied `on-login` handler must not call `log-sale` on every login.

The sale/activation event should be emitted only when the voucher is first activated (the point where the script initializes the expiry comment), or, preferably, from the payment/voucher issuance workflow itself.

Reconnections must only update/observe hotspot state and must never create new sales rows.

## Security

The API key contained in the supplied RouterOS command is considered exposed and must be rotated.
