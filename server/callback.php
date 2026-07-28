<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/RouterosAPI.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function respond(array $body, int $code = 200): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Vérification de la signature et IP (comme dans votre code) ---
// ... (je reprends votre code de vérification, qui est déjà bon)

// --- Traitement du webhook ---
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

ara_log('Webhook reçu : ' . json_encode($data), $config);

// Vérification signature...
// (recopiez votre code existant, il est correct)

$pdo = ara_db($config);
$identifier = $data['identifier'] ?? $data['tx_reference'] ?? null;
if (!$identifier) respond(['success'=>false,'message'=>'Identifiant manquant.'],400);

$stmt = $pdo->prepare("SELECT * FROM transactions WHERE identifier = :id OR tx_reference = :id");
$stmt->execute([':id' => $identifier]);
$tx = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tx) respond(['success'=>false,'message'=>'Transaction introuvable.'],404);

if ($tx['status'] === 'completed') {
    ara_log("Webhook dupliqué pour {$tx['identifier']} — déjà traité.", $config);
    respond(['success'=>true,'message'=>'Déjà traité.']);
}

// Vérifier le statut et montant
$paymentSuccess = ((string)($data['status'] ?? '') === '0');
$expectedAmount = (int)$tx['amount'];
$receivedAmount = isset($data['amount']) ? (int)$data['amount'] : $expectedAmount;
if (!$paymentSuccess || $receivedAmount !== $expectedAmount) {
    ara_log("Paiement échoué ou montant incohérent pour {$tx['identifier']}.", $config);
    $pdo->prepare("UPDATE transactions SET status='failed', updated_at=:u WHERE identifier=:id")
        ->execute([':u'=>date('c'), ':id'=>$tx['identifier']]);
    respond(['success'=>true,'message'=>'Paiement non confirmé.']);
}

// Déterminer le forfait
$package = $config['packages'][$tx['package_code']] ?? null;
if (!$package) {
    ara_log("Forfait inconnu pour {$tx['identifier']} : {$tx['package_code']}", $config);
    respond(['success'=>false,'message'=>'Forfait inconnu.'],500);
}

// --- Connexion MikroTik avec retry ---
$api = new RouterosAPI();
$connectOk = false;
for ($i=1; $i<=$config['mikrotik']['connect_retries']; $i++) {
    if ($api->connect(
        $config['mikrotik']['host'],
        $config['mikrotik']['api_user'],
        $config['mikrotik']['api_password'],
        $config['mikrotik']['api_port']
    )) { $connectOk = true; break; }
    sleep($i);
}
if (!$connectOk) {
    ara_log("Connexion MikroTik échouée pour {$tx['identifier']}.", $config);
    respond(['success'=>false,'message'=>'Connexion au routeur impossible.'],502);
}

// Vérifier que le profil existe
$profiles = $api->comm('/ip/hotspot/user/profile/print', ['?name' => $package['profile']]);
$profileExists = !empty($profiles) && isset($profiles[0]['__reply']) && $profiles[0]['__reply'] === '!done';
if (!$profileExists) {
    ara_log("Profil '{$package['profile']}' introuvable sur le routeur.", $config);
    $api->disconnect();
    respond(['success'=>false,'message'=>'Profil hotspot invalide.'],500);
}

// Génération des identifiants
$username = 'ara' . strtolower(ara_random_code(6));
$password = ara_random_code(6);

$addArgs = [
    'name'     => $username,
    'password' => $password,
    'profile'  => $package['profile'],
    'comment'  => 'PayGate:' . $tx['identifier'] . ' tel:' . $tx['phone'],
];
if (!empty($config['mikrotik']['hotspot_server']) && $config['mikrotik']['hotspot_server'] !== 'all') {
    $addArgs['server'] = $config['mikrotik']['hotspot_server'];
}

$created = false;
for ($attempt=1; $attempt<=3; $attempt++) {
    $addResult = $api->comm('/ip/hotspot/user/add', $addArgs);
    if (!empty($addResult[0]['__reply']) && $addResult[0]['__reply'] === '!done') {
        $created = true;
        break;
    }
    ara_log("Tentative $attempt d'ajout de l'utilisateur $username échouée.", $config);
    sleep($attempt);
}
$api->disconnect();

if (!$created) {
    ara_log("Échec définitif de création de l'utilisateur $username.", $config);
    $pdo->prepare("UPDATE transactions SET status='failed', updated_at=:u WHERE identifier=:id")
        ->execute([':u'=>date('c'), ':id'=>$tx['identifier']]);
    respond(['success'=>false,'message'=>'Erreur lors de la création du ticket.'],502);
}

// Mise à jour transaction
$pdo->prepare(
    "UPDATE transactions
     SET status='completed', hotspot_username=:u, hotspot_password=:p,
         tx_reference=COALESCE(:t, tx_reference), updated_at=:up
     WHERE identifier=:id"
)->execute([
    ':u'  => $username,
    ':p'  => $password,
    ':t'  => $data['tx_reference'] ?? null,
    ':up' => date('c'),
    ':id' => $tx['identifier'],
]);

// --- Envoi SMS avec retry exponentiel ---
$smsText = "ARA Tech Wi-Fi Zone\nForfait : {$package['label']}\nIdentifiant : $username\nMot de passe : $password\nValidité : {$package['validity']}\nConnectez-vous sur le portail Wi-Fi.";
$smsPayload = [
    'api_key'   => $config['sms']['api_key'],
    'sender_id' => $config['sms']['sender_id'],
    'to'        => $tx['phone'],
    'message'   => $smsText,
];
$smsSent = false;
$maxSmsRetries = $config['sms']['retry_attempts'] ?? 3;
for ($attempt=1; $attempt<=$maxSmsRetries; $attempt++) {
    $ch = curl_init($config['sms']['api_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($smsPayload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp !== false) {
        ara_log("SMS envoyé (tentative $attempt) pour {$tx['identifier']}.", $config);
        $smsSent = true;
        break;
    }
    $backoff = pow(2, $attempt-1);
    ara_log("Échec SMS tentative $attempt: $err. Backoff {$backoff}s.", $config);
    sleep($backoff);
}
if ($smsSent) {
    $pdo->prepare("UPDATE transactions SET sms_sent=1, updated_at=:u WHERE identifier=:id")
        ->execute([':u'=>date('c'), ':id'=>$tx['identifier']]);
} else {
    ara_log("Tous les envois SMS ont échoué pour {$tx['identifier']}.", $config);
}

// Maintenance
ara_maintenance($config, $config['maintenance']['retention_days'] ?? 90);

respond(['success'=>true, 'message'=>'Ticket créé et SMS envoyé.']);
