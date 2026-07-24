<?php
/**
 * callback.php — Webhook PayGate Global (asynchrone) sans SMS
 */

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/RouterosAPI.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function respond(array $body, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

ara_log('Webhook reçu : ' . json_encode($data, JSON_UNESCAPED_UNICODE) . ' IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $config);

if (empty($config['paygate']['webhook_secret'])) {
    ara_log('ERREUR CONFIG : webhook_secret manquant.', $config);
    respond(['success' => false, 'message' => 'Configuration webhook incomplète.'], 500);
}

$providedSignature = $_SERVER['HTTP_X_PAYGATE_SIGNATURE'] ?? ($data['signature'] ?? '');
$expectedSignature = hash_hmac('sha256', $raw !== '' ? $raw : http_build_query($data), $config['paygate']['webhook_secret']);

if (!$providedSignature || !hash_equals($expectedSignature, (string)$providedSignature)) {
    ara_log('Signature webhook invalide.', $config);
    respond(['success' => false, 'message' => 'Signature invalide.'], 401);
}

$identifier  = $data['identifier']   ?? null;
$txReference = $data['tx_reference'] ?? ($data['reference'] ?? null);
$status      = $data['status']       ?? null;
$amountPaid  = isset($data['amount']) ? (int)$data['amount'] : null;

if (!$identifier && !$txReference) {
    respond(['success' => false, 'message' => 'Identifiant de transaction manquant.'], 400);
}

$pdo = ara_db($config);

if ($identifier) {
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE identifier = :id");
    $stmt->execute([':id' => $identifier]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE tx_reference = :t");
    $stmt->execute([':t' => $txReference]);
}
$tx = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tx) {
    respond(['success' => false, 'message' => 'Transaction introuvable.'], 404);
}

if ($tx['status'] === 'completed') {
    respond(['success' => true, 'message' => 'Déjà traité.']);
}

$paymentSuccess = ((string)$status === '0');
$expectedAmount = (int)$tx['amount'];

if ($amountPaid !== null && $amountPaid !== $expectedAmount) {
    $pdo->prepare("UPDATE transactions SET status='failed', updated_at=:u WHERE identifier=:id")
        ->execute([':u' => date('c'), ':id' => $tx['identifier']]);
    respond(['success' => false, 'message' => 'Montant incohérent.'], 400);
}

if (!$paymentSuccess) {
    $pdo->prepare("UPDATE transactions SET status='failed', updated_at=:u WHERE identifier=:id")
        ->execute([':u' => date('c'), ':id' => $tx['identifier']]);
    respond(['success' => true, 'message' => 'Paiement non confirmé.']);
}

$package = $config['packages'][$tx['package_code']] ?? null;
if (!$package) {
    respond(['success' => false, 'message' => 'Forfait inconnu.'], 500);
}

// Génération des identifiants MikroTik
$username = 'ara' . strtolower(ara_random_code(6));
$password = ara_random_code(6);

$api = new RouterosAPI();
$api->debug = false;

if (!$api->connect($config['mikrotik']['host'], $config['mikrotik']['api_user'], $config['mikrotik']['api_password'])) {
    ara_log("Échec de connexion au MikroTik pour {$tx['identifier']}", $config);
    respond(['success' => false, 'message' => 'Connexion au routeur impossible.'], 502);
}

$addArgs = [
    'name'       => $username,
    'password'   => $password,
    'profile'    => $package['profile'],
    'comment'    => 'PayGate:' . $tx['identifier'] . ' tel:' . $tx['phone'],
    'expires-in' => $package['validity'],
];
if (!empty($config['mikrotik']['hotspot_server']) && $config['mikrotik']['hotspot_server'] !== 'all') {
    $addArgs['server'] = $config['mikrotik']['hotspot_server'];
}

$addResult = $api->comm('/ip/hotspot/user/add', $addArgs);
$api->disconnect();

$firstReply = $addResult[0] ?? [];
if (($firstReply['__reply'] ?? '') !== '!done') {
    respond(['success' => false, 'message' => 'Erreur création ticket sur le routeur.'], 502);
}

// Mise à jour de la transaction (sans sms_sent)
$pdo->prepare(
    "UPDATE transactions
     SET status='completed', hotspot_username=:u, hotspot_password=:p, tx_reference=:t, updated_at=:up
     WHERE identifier=:id"
)->execute([
    ':u'  => $username,
    ':p'  => $password,
    ':t'  => $txReference ?: $tx['tx_reference'],
    ':up' => date('c'),
    ':id' => $tx['identifier'],
]);

ara_maintenance($config, 90);

respond(['success' => true, 'message' => 'Ticket créé avec succès.']);
