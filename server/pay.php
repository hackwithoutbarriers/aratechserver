<?php
/**
 * pay.php — Déclenche le paiement Mobile Money (PayGate Global)
 * ------------------------------------------------------------------
 * Reçoit le formulaire "Payer en ligne" de login.html (package, phone,
 * network), revalide tout côté serveur, enregistre une transaction
 * "pending" en base, puis appelle l'API PayGate Global pour déclencher
 * le push USSD sur le téléphone du client.
 *
 * Le ticket hotspot n'est PAS généré ici : il l'est uniquement dans
 * callback.php, une fois le paiement confirmé par PayGate (webhook).
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
$phoneRaw    = trim($_POST['phone'] ?? '');
$network     = strtoupper(trim($_POST['network'] ?? ''));

// ---------- Validation ----------
if (!isset($config['packages'][$packageCode])) {
    json_error('Forfait invalide.');
}
if (!in_array($network, ['TMONEY', 'FLOOZ'], true)) {
    json_error('Réseau invalide. Choisissez T-Money ou Flooz.');
}

// Numéros togolais : 8 chiffres locaux, avec ou sans indicatif +228
$digits = preg_replace('/\D+/', '', $phoneRaw);
if (preg_match('/^228(\d{8})$/', $digits, $m)) {
    $localNumber = $m[1];
} elseif (preg_match('/^(\d{8})$/', $digits, $m)) {
    $localNumber = $m[1];
} else {
    json_error('Numéro de téléphone invalide. Utilisez un numéro togolais à 8 chiffres.');
}
$phoneIntl = '228' . $localNumber;

$package = $config['packages'][$packageCode];
$amount  = $package['price']; // JAMAIS le prix envoyé par le client : on relit uniquement le catalogue serveur

try {
    $pdo = ara_db($config);

    // Anti-abus simple : bloque les demandes répétées pour le même numéro
    // dans les 2 dernières minutes (évite de spammer des push USSD).
    $stmtCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM transactions
         WHERE phone = :phone AND status = 'pending' AND created_at > :since"
    );
    $stmtCheck->execute([
        ':phone' => $phoneIntl,
        ':since' => date('c', time() - 120),
    ]);
    if ((int)$stmtCheck->fetchColumn() > 0) {
        json_error('Une demande est déjà en cours pour ce numéro. Vérifiez votre téléphone.', 429);
    }
} catch (Throwable $e) {
    ara_log('pay.php DB check error: ' . $e->getMessage(), $config, 'error');
    json_error('Erreur interne, réessayez dans un instant.', 500);
}

// ---------- Référence unique ----------
$identifier = 'ARA-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));

try {
    $stmt = $pdo->prepare(
        "INSERT INTO transactions
            (identifier, phone, network, package_code, amount, status, created_at)
         VALUES
            (:identifier, :phone, :network, :package_code, :amount, 'pending', :created_at)"
    );
    $stmt->execute([
        ':identifier'   => $identifier,
        ':phone'        => $phoneIntl,
        ':network'      => $network,
        ':package_code' => $packageCode,
        ':amount'       => $amount,
        ':created_at'   => date('c'),
    ]);
} catch (Throwable $e) {
    ara_log('pay.php DB insert error: ' . $e->getMessage(), $config, 'error');
    json_error('Erreur interne, réessayez dans un instant.', 500);
}

// ---------- Appel à l'API PayGate Global ----------
$payload = [
    'auth_token'   => $config['paygate']['auth_token'],
    'phone_number' => '+' . $phoneIntl,
    'amount'       => $amount,
    'identifier'   => $identifier,
    'network'      => $network,
    'description'  => 'ARA Tech Wi-Fi - ' . $package['label'],
    'callback_url' => $config['paygate']['callback_url'],
];

$ch = curl_init(rtrim($config['paygate']['base_url'], '/') . $config['paygate']['pay_endpoint']);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);
$response = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    ara_log('pay.php cURL error: ' . $curlErr, $config, 'error');
    $pdo->prepare("UPDATE transactions SET status='failed', updated_at=:u WHERE identifier=:id")
        ->execute([':u' => date('c'), ':id' => $identifier]);
    json_error('Impossible de contacter la passerelle de paiement. Réessayez.', 502);
}

$result = json_decode($response, true);
$initiationOk = is_array($result) && isset($result['status']) && (string)$result['status'] === '0';

if (!$initiationOk) {
    ara_log('pay.php PayGate rejection: ' . $response, $config, 'error');
    $pdo->prepare("UPDATE transactions SET status='failed', updated_at=:u WHERE identifier=:id")
        ->execute([':u' => date('c'), ':id' => $identifier]);
    json_error($result['message'] ?? "Le paiement n'a pas pu être initié. Vérifiez le numéro et réessayez.");
}

if (!empty($result['tx_reference'])) {
    $pdo->prepare("UPDATE transactions SET tx_reference=:t, updated_at=:u WHERE identifier=:id")
        ->execute([':t' => $result['tx_reference'], ':u' => date('c'), ':id' => $identifier]);
}

echo json_encode([
    'success'    => true,
    'message'    => 'Une demande de paiement a été envoyée sur votre téléphone.',
    'identifier' => $identifier,
]);