<?php
declare(strict_types=1);

/**
 * Canonical business-sales aggregation.
 *
 * From migration 014 onward, sales_transactions is the only source of truth
 * for business KPIs. sales_log remains a technical/legacy journal and is not
 * counted as revenue or tickets.
 *
 * The transaction ledger carries a unique transaction_id, so legitimate
 * repeat purchases by the same username are allowed and HTTP retries are
 * idempotent. Historical rows are marked inferred=true by migration 014.
 */
function ara_business_sales(PDO $pdo, string $startDate, string $endDate): array
{
    $source = 'transactions';
    $sourceLabel = 'Transactions commerciales';
    $inferred = 0;

    try {
        $stmt = $pdo->prepare(
            'SELECT
                id,
                transaction_id,
                sale_date,
                sale_time,
                username,
                amount,
                currency,
                profile,
                comment,
                voucher_expires_at,
                status,
                source,
                inferred,
                created_at
             FROM sales_transactions
             WHERE sale_date BETWEEN ? AND ?
               AND status = \'PAID\'
               AND is_business_sale = TRUE
             ORDER BY sale_date ASC, sale_time ASC NULLS FIRST, created_at ASC, id ASC'
        );
        $stmt->execute([$startDate, $endDate]);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rawStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM sales_transactions
             WHERE sale_date BETWEEN ? AND ?'
        );
        $rawStmt->execute([$startDate, $endDate]);
        $rawRows = (int)$rawStmt->fetchColumn();

        $inferredStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM sales_transactions
             WHERE sale_date BETWEEN ? AND ?
               AND status = \'PAID\'
               AND is_business_sale = TRUE
               AND inferred = TRUE'
        );
        $inferredStmt->execute([$startDate, $endDate]);
        $inferred = (int)$inferredStmt->fetchColumn();
    } catch (Throwable $e) {
        // Safe rollout fallback before migration 014 is applied. Once the
        // ledger exists, this branch is never used and no legacy rows are
        // mixed into current transaction data.
        $source = 'legacy_activation_proxy';
        $sourceLabel = 'Activations Hotspot historiques (proxy)';

        $stmt = $pdo->prepare(
            'WITH ranked AS (
                SELECT
                    id,
                    sale_date,
                    sale_time,
                    username,
                    amount,
                    profile,
                    comment,
                    received_at,
                    ROW_NUMBER() OVER (
                        PARTITION BY username, comment
                        ORDER BY sale_date ASC, sale_time ASC NULLS FIRST,
                                 received_at ASC NULLS FIRST, id ASC
                    ) AS rn
                FROM sales_log
                WHERE amount > 0
                  AND username <> \'\'
                  AND comment <> \'\'
                  AND lower(trim(COALESCE(profile, \'\'))) NOT IN (\'test\',\'testing\',\'demo\')
            )
            SELECT id, sale_date, sale_time, username, amount, profile, comment, received_at
            FROM ranked
            WHERE rn = 1
              AND sale_date BETWEEN ? AND ?
            ORDER BY sale_date ASC, sale_time ASC NULLS FIRST, received_at ASC NULLS FIRST, id ASC'
        );
        $stmt->execute([$startDate, $endDate]);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rawStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM sales_log
             WHERE sale_date BETWEEN ? AND ?
               AND amount > 0'
        );
        $rawStmt->execute([$startDate, $endDate]);
        $rawRows = (int)$rawStmt->fetchColumn();
        $inferred = count($sales);
    }

    $revenue = 0;
    $profileMap = [];
    $dailyMap = [];

    foreach ($sales as $sale) {
        $amount = (int)($sale['amount'] ?? 0);
        $profile = trim((string)($sale['profile'] ?? 'Inconnu')) ?: 'Inconnu';
        $day = (string)($sale['sale_date'] ?? '');

        $revenue += $amount;

        if (!isset($profileMap[$profile])) {
            $profileMap[$profile] = ['profile' => $profile, 'nb' => 0, 'ca' => 0];
        }
        $profileMap[$profile]['nb']++;
        $profileMap[$profile]['ca'] += $amount;

        $dailyMap[$day] = ($dailyMap[$day] ?? 0) + $amount;
    }

    usort($profileMap, static fn(array $a, array $b): int => $b['ca'] <=> $a['ca']);
    ksort($dailyMap);

    return [
        'revenue' => $revenue,
        'tickets' => count($sales),
        'raw_rows' => $rawRows,
        'duplicates_removed' => $source === 'transactions' ? 0 : max(0, $rawRows - count($sales)),
        'inferred_count' => $source === 'transactions' ? $inferred : count($sales),
        'source' => $source,
        'source_label' => $sourceLabel,
        'profile_stats' => array_values($profileMap),
        'daily' => array_map(
            static fn(string $day, int $total): array => ['sale_date' => $day, 'total' => $total],
            array_keys($dailyMap),
            array_values($dailyMap)
        ),
        'sales' => array_slice($sales, -200),
    ];
}
