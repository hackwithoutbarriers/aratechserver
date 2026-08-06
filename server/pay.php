<?php
/**
 * pay.php — Déclenche le paiement Mobile Money via PayGate Global
 * (inchangé par rapport à la version précédente)
 */

declare(strict_types=1);

require __DIR__ . '/db.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . $config['allowed_origin']);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Méthode non autorisée.', 405);
}

$packageCode = strtoupper(trim($_POST['package'] ?? ''));
$phoneRaw = trim($_POST['phone'] ?? '');
$network = strtoupper(trim($_POST['network'] ?? ''));

if (!isset($config['packages'][$packageCode])) {
    json_error('Forfait invalide.');
}
if (!in_array($network, ['TMONEY', 'FLOOZ'], true)) {
    json_error('Réseau invalide.');
}

$digits = preg_replace('/\D+/', '', $phoneRaw);
if (preg_match('/^228(\d{8})$/', $digits, $m)) {
    $localNumber = $m[1];
} elseif (preg_match('/^(\d{8})$/', $digits, $m)) {
    $localNumber = $m[1];
} else {
    json_error('Numéro invalide. Utilisez 8 chiffres.');
}
$phoneIntl = '228' . $localNumber;

$package = $config['packages'][$packageCode];
$amount = $package['price'];

try {
    $pdo = ara_db($config);

    $stmtCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM transactions
         WHERE phone = :phone AND status = 'pending' AND created_at > :since"
    );
    $stmtCheck->execute([
        ':phone' => $phoneIntl,
        ':since' => date('c', time() - 120),
    ]);
    if ((int)$stmtCheck->fetchColumn() > 0) {
        json_error('Une demande est déjà en cours pour ce numéro.', 429);
    }
} catch (Throwable $e) {
    ara_log('pay.php DB check error: ' . $e->getMessage(), $config, 'error');
    json_error('Erreur interne, réessayez dans un instant.', 500);
}

$identifier = 'ARA-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));

try {
    $stmt = $pdo->prepare(
        "INSERT INTO transactions
            (identifier, phone, network, package_code, amount, status, created_at)
         VALUES
            (:identifier, :phone, :network, :package_code, :amount, 'pending', :created_at)"
    );
    $stmt->execute([
        ':identifier' => $identifier,
        ':phone' => $phoneIntl,
        ':network' => $network,
        ':package_code' => $packageCode,
        ':amount' => $amount,
        ':created_at' => date('c'),
    ]);
} catch (Throwable $e) {
    ara_log('pay.php DB insert error: ' . $e->getMessage(), $config, 'error');
    json_error('Erreur interne, réessayez dans un instant.', 500);
}

$payload = [
    'auth_token' => $config['paygate']['auth_token'],
    'phone_number' => '+' . $phoneIntl,
    'amount' => $amount,
    'identifier' => $identifier,
    'network' => $network,
    'description' => 'ARA Tech Wi-Fi - ' . $package['label'],
    'callback_url' => $config['paygate']['callback_url'],
];

$ch = curl_init(rtrim($config['paygate']['base_url'], '/') . $config['paygate']['pay_endpoint']);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$response = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

if ($response === false) {
    ara_log('pay.php cURL error: ' . $curlErr, $config, 'error');
    $pdo->prepare("UPDATE transactions SET status='failed', updated_at=:u WHERE identifier=:id")
        ->execute([':u' => date('c'), ':id' => $identifier]);
    json_error('Impossible de contacter la passerelle de paiement.', 502);
}

$result = json_decode($response, true);
$initiationOk = is_array($result) && isset($result['status']) && (string)$result['status'] === '0';

if (!$initiationOk) {
    ara_log('pay.php PayGate rejection: ' . $response, $config, 'error');
    $pdo->prepare("UPDATE transactions SET status='failed', updated_at=:u WHERE identifier=:id")
        ->execute([':u' => date('c'), ':id' => $identifier]);
    json_error($result['message'] ?? "Le paiement n'a pas pu être initié.");
}

if (!empty($result['tx_reference'])) {
    $pdo->prepare("UPDATE transactions SET tx_reference=:t, updated_at=:u WHERE identifier=:id")
        ->execute([':t' => $result['tx_reference'], ':u' => date('c'), ':id' => $identifier]);
}

echo json_encode([
    'success' => true,
    'message' => 'Demande de paiement envoyée sur votre téléphone.',
    'identifier' => $identifier,
]);