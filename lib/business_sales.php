<?php
declare(strict_types=1);

/**
 * Canonical business-sales aggregation.
 *
 * sales_log is a technical on-login journal, so one voucher can be logged more
 * than once. Business KPIs must therefore never count raw rows directly.
 *
 * A billable sale is an entry with amount > 0. Identical retry events are
 * collapsed by sale_date + username + amount + profile + comment.
 */
function ara_business_sales(PDO $pdo, string $startDate, string $endDate): array
{
    $stmt = $pdo->prepare(
        'SELECT id, sale_date, sale_time, username, amount, profile, comment, received_at
         FROM sales_log
         WHERE sale_date BETWEEN ? AND ?
           AND amount > 0
           AND username <> \'\'
         ORDER BY sale_date ASC, sale_time ASC, received_at ASC, id ASC'
    );
    $stmt->execute([$startDate, $endDate]);

    $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $sales = [];
    $seen = [];
    $duplicates = 0;

    foreach ($rawRows as $row) {
        $key = implode('|', [
            (string)($row['sale_date'] ?? ''),
            trim((string)($row['username'] ?? '')),
            (string)(int)($row['amount'] ?? 0),
            trim((string)($row['profile'] ?? '')),
            trim((string)($row['comment'] ?? '')),
        ]);

        if (isset($seen[$key])) {
            $duplicates++;
            continue;
        }

        $seen[$key] = true;
        $sales[] = $row;
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

        if (!isset($dailyMap[$day])) {
            $dailyMap[$day] = 0;
        }
        $dailyMap[$day] += $amount;
    }

    usort($profileMap, static fn(array $a, array $b): int => $b['ca'] <=> $a['ca']);
    ksort($dailyMap);
    $recent = array_reverse(array_slice(array_reverse($sales), 0, 200));

    return [
        'revenue' => $revenue,
        'tickets' => count($sales),
        'raw_rows' => count($rawRows),
        'duplicates_removed' => $duplicates,
        'profile_stats' => array_values($profileMap),
        'daily' => array_map(
            static fn(string $day, int $total): array => ['sale_date' => $day, 'total' => $total],
            array_keys($dailyMap),
            array_values($dailyMap)
        ),
        'sales' => $recent,
    ];
}
