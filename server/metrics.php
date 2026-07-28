<?php
/**
 * metrics.php — Endpoint de reporting / analytics
 * ------------------------------------------------------------------
 * Fournit des statistiques sur les paiements, revenus, taux de succès.
 * Protégé par une liste d'IPs autorisées (management network).
 *
 * Exemples :
 *   GET /metrics.php?period=today
 *   GET /metrics.php?period=week
 *   GET /metrics.php?period=month
 *   GET /metrics.php?period=all
 */

declare(strict_types=1);

require __DIR__ . '/db.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// ---------- 1) Authentification par IP (restriction management network) ----------
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$managementSubnets = [
    '192.168.88.0/24',   // Management LAN (défaut RouterOS)
    '127.0.0.1',         // Localhost
    '::1',               // IPv6 localhost
];

function ip_in_cidr(string $ip, string $cidr): bool
{
    [$subnet, $bits] = explode('/', $cidr);
    $ip = ip2long($ip);
    $subnet = ip2long($subnet);
    $mask = -1 << (32 - $bits);
    $subnet &= $mask;
    return ($ip & $mask) === $subnet;
}

$authorized = false;
foreach ($managementSubnets as $subnet) {
    if (ip_in_cidr($clientIp, $subnet)) {
        $authorized = true;
        break;
    }
}

if (!$authorized) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => "Accès refusé. IP $clientIp non autorisée.",
    ]);
    exit;
}

// ---------- 2) Récupération des métriques ----------
$period = $_GET['period'] ?? 'today';
if (!in_array($period, ['today', 'week', 'month', 'all'], true)) {
    $period = 'today';
}

$metrics = ara_metrics($config, $period);

if (empty($metrics)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur lors de la récupération des métriques.',
    ]);
    exit;
}

$metrics['client_ip'] = $clientIp;
$metrics['generated_at'] = date('c');

echo json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);