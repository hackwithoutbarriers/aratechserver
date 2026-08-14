<?php
declare(strict_types=1);

/**
 * Canonical business-sales aggregation.
 *
 * `sales_log` is a technical on-login journal. The supplied MikroTik on-login
 * script calls `log-sale` on every login/re-login, so raw rows are not sales.
 * The stable voucher identity available in the current payload is
 * username + comment (the comment contains the expiry marker generated on
 * first activation). We therefore count the first activation of each pair
 * once across the whole history, then filter those first activations into
 * the requested reporting period.
 *
 * This is an explicit historical ACTIVATION proxy, not a payment transaction
 * ledger. Future payment integrations should write a transaction id into a
 * dedicated sales table.
 */
function ara_business_sales(PDO $pdo, string $startDate, string $endDate): array
{
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
           AND amount > 0
           AND username <> \'\''
    );
    $rawStmt->execute([$startDate, $endDate]);
    $rawRows = (int)$rawStmt->fetchColumn();

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

        if (!isset($dailyMap[$day])) {
            $dailyMap[$day] = 0;
        }
        $dailyMap[$day] += $amount;
    }

    usort($profileMap, static fn(array $a, array $b): int => $b['ca'] <=> $a['ca']);
    ksort($dailyMap);

    $duplicates = max(0, $rawRows - count($sales));

    return [
        'revenue' => $revenue,
        'tickets' => count($sales),
        'raw_rows' => $rawRows,
        'duplicates_removed' => $duplicates,
        'source' => 'activation_proxy',
        'source_label' => 'Activations Hotspot dédupliquées',
        'profile_stats' => array_values($profileMap),
        'daily' => array_map(
            static fn(string $day, int $total): array => ['sale_date' => $day, 'total' => $total],
            array_keys($dailyMap),
            array_values($dailyMap)
        ),
        'sales' => array_slice($sales, -200),
    ];
}
