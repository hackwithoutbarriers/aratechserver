<?php
/**
 * callback.php — Webhook PayGate Global
 * Crée le ticket hotspot via RouterOS API (compatible Mikhmon)
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

// Lecture du payload
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

ara_log('Webhook reçu : ' . json_encode($data, JSON_UNESCAPED_UNICODE), $config);

// ---------- Vérification signature ----------
if (empty($config['paygate']['webhook_secret'])) {
    ara_log('ERREUR : webhook_secret non configuré', $config, 'error');
    respond(['success' => false, 'message' => 'Configuration webhook incomplète.'], 500);
}

$providedSignature = $_SERVER['HTTP_X_PAYGATE_SIGNATURE'] ?? ($data['signature'] ?? '');
$expectedSignature = hash_hmac('sha256', $raw !== '' ? $raw : http_build_query($data), $config['paygate']['webhook_secret']);

if (!$providedSignature || !hash_equals($expectedSignature, (string)$providedSignature)) {
    ara_log('Signature webhook invalide', $config, 'error');
    respond(['success' => false, 'message' => 'Signature invalide.'], 401);
}

// ---------- IP Whitelist ----------
if (!empty($config['webhook_allowed_ips'])) {
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remoteIp, $config['webhook_allowed_ips'], true)) {
        ara_log("Webhook rejeté - IP source non autorisée: $remoteIp", $config);
        respond(['success' => false, 'message' => 'IP source non autorisée.'], 403);
    }
}

// ---------- Identification transaction ----------
$identifier = $data['identifier'] ?? null;
$txReference = $data['tx_reference'] ?? ($data['reference'] ?? null);
$status = $data['status'] ?? null;
$amountPaid = isset($data['amount']) ? (int)$data['amount'] : null;

if (!$identifier && !$txReference) {
    ara_log('Webhook sans identifier ni tx_reference', $config);
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
    ara_log("Transaction introuvable (identifier=$identifier, tx_reference=$txReference)", $config);
    respond(['success' => false, 'message' => 'Transaction introuvable.'], 404);
}

// Déjà traité ?
if ($tx['status'] === 'completed') {
    ara_log("Webhook dupliqué pour {$tx['identifier']} — déjà traité", $config);
    respond(['success' => true, 'message' => 'Déjà traité.']);
}

// ---------- Vérification paiement ----------
$paymentSuccess = ((string)$status === '0');
$expectedAmount = (int)$tx['amount'];

if ($amountPaid !== null && $amountPaid !== $expectedAmount) {
    ara_log("Montant incohérent : attendu $expectedAmount, reçu $amountPaid", $config);
    $pdo->prepare("UPDATE transactions SET status='failed', updated_at=:u WHERE identifier=:id")
        ->execute([':u' => date('c'), ':id' => $tx['identifier']]);
    respond(['success' => false, 'message' => 'Montant incohérent.'], 400);
}

if (!$paymentSuccess) {
    ara_log("Paiement non confirmé (status=$status)", $config);
    $pdo->prepare("UPDATE transactions SET status='failed', updated_at=:u WHERE identifier=:id")
        ->execute([':u' => date('c'), ':id' => $tx['identifier']]);
    respond(['success' => true, 'message' => 'Paiement non confirmé.']);
}

// ---------- Détermination du forfait ----------
$package = $config['packages'][$tx['package_code']] ?? null;
if (!$package) {
    ara_log("Code forfait inconnu : {$tx['package_code']}", $config);
    respond(['success' => false, 'message' => 'Forfait inconnu.'], 500);
}

// ---------- Génération identifiants ----------
$username = 'ARA' . strtoupper(ara_random_code(6));
$password = ara_random_code(6);

// ---------- Connexion au routeur et création du ticket ----------
$api = new RouterosAPI();
$api->debug = $config['debug'] ?? false;

$connected = $api->connect(
    $config['mikrotik']['host'],
    $config['mikrotik']['api_user'],
    $config['mikrotik']['api_password'],
    $config['mikrotik']['api_port'] ?? 8728
);

if (!$connected) {
    ara_log("Échec connexion MikroTik pour {$tx['identifier']}", $config, 'error');
    respond(['success' => false, 'message' => 'Connexion au routeur impossible.'], 502);
}

// Construction des arguments pour la création de l'utilisateur
$addArgs = [
    'name' => $username,
    'password' => $password,
    'profile' => $package['profile'],
    'comment' => 'PayGate:' . $tx['identifier'] . ' tel:' . $tx['phone'] . ' ' . date('Y-m-d H:i:s'),
];

// Si vous utilisez un serveur hotspot spécifique
if (!empty($config['mikrotik']['hotspot_server']) && $config['mikrotik']['hotspot_server'] !== 'all') {
    $addArgs['server'] = $config['mikrotik']['hotspot_server'];
}

$addResult = $api->comm('/ip/hotspot/user/add', $addArgs);
$api->disconnect();

$firstReply = $addResult[0] ?? [];
$mikrotikFailed = ($firstReply['__reply'] ?? '') !== '!done';

if ($mikrotikFailed) {
    $errMsg = $firstReply['message'] ?? 'Erreur inconnue';
    ara_log("Erreur MikroTik : $errMsg", $config, 'error');
    respond(['success' => false, 'message' => 'Erreur lors de la création du ticket.'], 502);
}

ara_log("Ticket créé : $username / profil {$package['profile']}", $config);

// ---------- Mise à jour transaction ----------
$pdo->prepare(
    "UPDATE transactions
     SET status='completed', hotspot_username=:u, hotspot_password=:p,
         tx_reference=:t, updated_at=:up
     WHERE identifier=:id"
)->execute([
    ':u' => $username,
    ':p' => $password,
    ':t' => $txReference ?: $tx['tx_reference'],
    ':up' => date('c'),
    ':id' => $tx['identifier'],
]);

// ---------- Mise à jour fidélité ----------
try {
    $stmt = $pdo->prepare(
        "INSERT INTO loyalty (user, points, topups, referral_code, created_at, updated_at)
         VALUES (:user, 0, 1, :referral_code, :created_at, :updated_at)
         ON CONFLICT(user) DO UPDATE SET
             topups = topups + 1,
             updated_at = :updated_at"
    );
    $stmt->execute([
        ':user' => $username,
        ':referral_code' => strtoupper(substr(sha1($username . microtime(true)), 0, 8)),
        ':created_at' => date('c'),
        ':updated_at' => date('c'),
    ]);
    ara_log("Fidélité mise à jour pour $username", $config);
} catch (Throwable $e) {
    ara_log("Erreur mise à jour fidélité : " . $e->getMessage(), $config, 'error');
}

// ---------- Envoi SMS ----------
$smsText = "ARA Tech Wi-Fi Zone\n"
         . "Forfait : {$package['label']}\n"
         . "Identifiant : $username\n"
         . "Mot de passe : $password\n"
         . "Valide : {$package['validity']}\n"
         . "Connectez-vous sur le portail Wi-Fi.";

$smsPayload = [
    'api_key' => $config['sms']['api_key'],
    'sender_id' => $config['sms']['sender_id'],
    'to' => $tx['phone'],
    'message' => $smsText,
];

$smsSent = false;
for ($attempt = 1; $attempt <= 3; $attempt++) {
    $ch = curl_init($config['sms']['api_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($smsPayload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $smsResponse = curl_exec($ch);
    $smsErr = curl_error($ch);
    curl_close($ch);

    if ($smsResponse !== false) {
        ara_log("SMS envoyé (tentative $attempt/3) pour {$tx['identifier']}", $config);
        $smsSent = true;
        break;
    }
    if ($attempt < 3) {
        sleep(pow(2, $attempt - 1));
    } else {
        ara_log("Échec SMS pour {$tx['identifier']} : $smsErr", $config, 'error');
    }
}

if ($smsSent) {
    $pdo->prepare("UPDATE transactions SET sms_sent=1, updated_at=:u WHERE identifier=:id")
        ->execute([':u' => date('c'), ':id' => $tx['identifier']]);
}

// ---------- Maintenance ----------
ara_maintenance($config, 90);

// ---------- Réponse ----------
respond(['success' => true, 'message' => 'Ticket créé et SMS envoyé.']);