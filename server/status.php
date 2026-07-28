<?php
/**
 * status.php — Interrogé par login.html (polling) pour savoir si le
 * paiement a été confirmé et récupérer le ticket généré.
 */

declare(strict_types=1);

require __DIR__ . '/db.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . $config['allowed_origin']);
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$identifier = trim($_GET['identifier'] ?? '');
if ($identifier === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètre identifier manquant.']);
    exit;
}

try {
    $pdo = ara_db($config);
    $stmt = $pdo->prepare(
        "SELECT status, hotspot_username, hotspot_password
         FROM transactions WHERE identifier = :id"
    );
    $stmt->execute([':id' => $identifier]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    ara_log('status.php DB error: ' . $e->getMessage(), $config, 'error');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur interne.']);
    exit;
}

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Transaction introuvable.']);
    exit;
}

echo json_encode([
    'success'  => true,
    'status'   => $row['status'], // pending | completed | failed
    'username' => $row['status'] === 'completed' ? $row['hotspot_username'] : null,
    'password' => $row['status'] === 'completed' ? $row['hotspot_password'] : null,
]);