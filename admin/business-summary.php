<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/business_sales.php';

$config = require __DIR__ . '/../config.php';
$period = (string)($_GET['period'] ?? 'today');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

switch ($period) {
    case 'today':
        $start = $now->format('Y-m-d');
        $end = $start;
        break;
    case 'yesterday':
        $day = $now->modify('-1 day')->format('Y-m-d');
        $start = $day;
        $end = $day;
        break;
    case '7days':
        $start = $now->modify('-6 days')->format('Y-m-d');
        $end = $now->format('Y-m-d');
        break;
    case 'thismonth':
        $start = $now->modify('first day of this month')->format('Y-m-d');
        $end = $now->format('Y-m-d');
        break;
    case 'lastmonth':
        $start = $now->modify('first day of last month')->format('Y-m-d');
        $end = $now->modify('last day of last month')->format('Y-m-d');
        break;
    default:
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Période invalide.']);
        exit;
}

try {
    $pdo = ara_db_supabase();
    $sales = ara_business_sales($pdo, $start, $end);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'success' => true,
        'period' => ['start' => $start, 'end' => $end],
        'revenue' => $sales['revenue'],
        'tickets_sold' => $sales['tickets'],
        'duplicates_removed' => $sales['duplicates_removed'],
        'revenue_chart' => $sales['daily'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[Business Summary] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Impossible de calculer les indicateurs Business.']);
}
