<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../lib/RouterosAPI.php';
$config = require __DIR__ . '/../config.php';

// Interface à surveiller (obligatoire)
$interface = $_GET['iface'] ?? '';
if ($interface === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Paramètre "iface" manquant.']);
    exit;
}

$API = new RouterosAPI();
$connected = $API->connect(
    $config['mikrotik']['host'],
    $config['mikrotik']['api_user'],
    $config['mikrotik']['api_password'],
    (int)$config['mikrotik']['api_port']
);

if (!$connected) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Connexion au routeur impossible.']);
    exit;
}

$traffic = $API->comm('/interface/monitor-traffic', [
    'interface' => $interface,
    'once'      => '',
]);

$API->disconnect();

if (empty($traffic[0])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Interface introuvable ou aucune donnée.']);
    exit;
}

$result = [
    ['name' => 'Tx', 'data' => [$traffic[0]['tx-bits-per-second'] ?? 0]],
    ['name' => 'Rx', 'data' => [$traffic[0]['rx-bits-per-second'] ?? 0]],
];

header('Content-Type: application/json');
echo json_encode($result);
