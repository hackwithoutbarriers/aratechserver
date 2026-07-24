<?php
/**
 * callback.php — Webhook PayGate Global (asynchrone)
 * ------------------------------------------------------------------
 * Appelé automatiquement par PayGate Global dès qu'un paiement Mobile
 * Money (T-Money/Flooz) est confirmé (ou échoue).
 *
 * Étapes :
 *   1. Valider le paiement (signature + montant + statut).
 *   2. Identifier le forfait payé (100F / 200F / 500F / 1500F).
 *   3. Créer le ticket hotspot sur le MikroTik (RouterosAPI.php).
 *   4. Envoyer les identifiants par SMS au client.
 *   5. Journaliser l'événement pour le débogage.
 *
 * Répond toujours par un JSON + code HTTP à PayGate pour accuser
 * réception (évite les tentatives de renvoi inutiles de leur côté).
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

// PayGate peut envoyer le webhook en JSON ou en POST classique (form-encoded)
// selon la configuration choisie dans votre tableau de bord — on gère les deux.
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

ara_log('Webhook reçu : ' . json_encode($data, JSON_UNESCAPED_UNICODE) . ' IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $config);

// ---------- 1a) Vérification de signature (OBLIGATOIRE - webhook_secret doit être configuré) ----------
// Mécanisme HMAC-SHA256 sur le corps brut de la requête.
// Adaptez précisément selon ce que documente PayGate dans votre tableau de bord
// (nom d'en-tête, algorithme) si différent.
if (empty($config['paygate']['webhook_secret'])) {
    ara_log('ERREUR CONFIG : webhook_secret n\'est pas configuré. Webhook rejeté par mesure de sécurité.', $config);
    respond(['success' => false, 'message' => 'Configuration webhook incomplète.'], 500);
}

$providedSignature = $_SERVER['HTTP_X_PAYGATE_SIGNATURE'] ?? ($data['signature'] ?? '');
$expectedSignature = hash_hmac('sha256', $raw !== '' ? $raw : http_build_query($data), $config['paygate']['webhook_secret']);

if (!$providedSignature || !hash_equals($expectedSignature, (string)$providedSignature)) {
    ara_log('Signature webhook invalide — requête rejetée. IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $config);
    respond(['success' => false, 'message' => 'Signature invalide.'], 401);
}

// ---------- 1b) IP Whitelist (protection supplémentaire) ----------
if (!empty($config['webhook_allowed_ips'])) {
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remoteIp, $config['webhook_allowed_ips'], true)) {
        ara_log("Webhook rejeté - IP source non autorisée: $remoteIp", $config);
        respond(['success' => false, 'message' => 'IP source non autorisée.'], 403);
    }
}

// ---------- 1b) Identification de la transaction ----------
$identifier  = $data['identifier']   ?? null;
$txReference = $data['tx_reference'] ?? ($data['reference'] ?? null);
$status      = $data['status']       ?? null; // 0 = paiement réussi (à confirmer selon la doc PayGate)
$amountPaid  = isset($data['amount']) ? (int)$data['amount'] : null;

if (!$identifier && !$txReference) {
    ara_log('Webhook sans identifier ni tx_reference — ignoré.', $config);
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

// Webhook déjà traité ? (PayGate peut renvoyer plusieurs fois la même notification)
if ($tx['status'] === 'completed') {
    ara_log("Webhook dupliqué pour {$tx['identifier']} — déjà traité, on répond OK.", $config);
    respond(['success' => true, 'message' => 'Déjà traité.']);
}

// ---------- 1c) Vérification du montant (défense en profondeur) ----------
// ---------- 2) Vérification du paiement ----------
$paymentSuccess = ((string)$status === '0');
$expectedAmount = (int)$tx['amount'];

// Défense en profondeur : le montant confirmé par PayGate doit correspondre
// exactement au forfait demandé au départ.
if ($amountPaid !== null && $amountPaid !== $expectedAmount) {
    ara_log("Montant incohérent pour {$tx['identifier']} : attendu $expectedAmount, reçu $amountPaid — rejeté.", $config);
    $pdo->prepare("UPDATE transactions SET status='failed', updated_at=:u WHERE identifier=:id")
        ->execute([':u' => date('c'), ':id' => $tx['identifier']]);
    respond(['success' => false, 'message' => 'Montant incohérent.'], 400);
}

if (!$paymentSuccess) {
    ara_log("Paiement non confirmé pour {$tx['identifier']} (status=$status).", $config);
    $pdo->prepare("UPDATE transactions SET status='failed', updated_at=:u WHERE identifier=:id")
        ->execute([':u' => date('c'), ':id' => $tx['identifier']]);
    respond(['success' => true, 'message' => 'Paiement non confirmé, transaction close.']);
}

// ---------- 3) Détermine le profil du forfait payé ----------
$package = $config['packages'][$tx['package_code']] ?? null;
if (!$package) {
    ara_log("Code forfait inconnu pour {$tx['identifier']} : {$tx['package_code']}", $config);
    respond(['success' => false, 'message' => 'Forfait inconnu.'], 500);
}

// ---------- 4) Génération des identifiants + injection MikroTik ----------
$username = 'ara' . strtolower(ara_random_code(6));
$password = ara_random_code(6);

$api = new RouterosAPI();
$api->debug = false;

if (!$api->connect($config['mikrotik']['host'], $config['mikrotik']['api_user'], $config['mikrotik']['api_password'])) {
    ara_log("Échec de connexion au MikroTik ({$config['mikrotik']['host']}) pour {$tx['identifier']}", $config);
    respond(['success' => false, 'message' => 'Connexion au routeur impossible.'], 502);
}

$addArgs = [
    'name'     => $username,
    'password' => $password,
    'profile'  => $package['profile'],
    'comment'  => 'PayGate:' . $tx['identifier'] . ' tel:' . $tx['phone'],
    'expires-in' => $package['validity'],  // Expiration automatique (ex: '24h', '7j')
];
if (!empty($config['mikrotik']['hotspot_server']) && $config['mikrotik']['hotspot_server'] !== 'all') {
    $addArgs['server'] = $config['mikrotik']['hotspot_server'];
}

$addResult = $api->comm('/ip/hotspot/user/add', $addArgs);
$api->disconnect();

// La classe RouterosAPI tague chaque bloc de réponse avec '__reply'
// ('!done' = succès, '!trap'/'!fatal' = erreur RouterOS).
$firstReply = $addResult[0] ?? [];
$mikrotikFailed = ($firstReply['__reply'] ?? '') !== '!done';

if ($mikrotikFailed) {
    $errMsg = $firstReply['message'] ?? 'Erreur inconnue';
    ara_log("Erreur MikroTik pour {$tx['identifier']} : $errMsg", $config);
    respond(['success' => false, 'message' => 'Erreur lors de la création du ticket sur le routeur.'], 502);
}

ara_log("Ticket créé sur le MikroTik : $username / profil {$package['profile']} pour {$tx['identifier']}", $config);

// ---------- 5) Mise à jour de la transaction ----------
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

// ---------- 6) Envoi du SMS (avec retry) ----------
$smsText = "ARA Tech Wi-Fi Zone\n"
         . "Forfait : {$package['label']}\n"
         . "Identifiant : $username\n"
         . "Mot de passe : $password\n"
         . "Validite : {$package['validity']}\n"
         . "Connectez-vous sur le portail Wi-Fi avec ces identifiants. Merci !";

// Format des champs à adapter selon la passerelle SMS réellement utilisée
// (Africa's Talking, Orange SMS API, etc.) — ceci est un gabarit générique.
$smsPayload = [
    'api_key'   => $config['sms']['api_key'],
    'sender_id' => $config['sms']['sender_id'],
    'to'        => $tx['phone'],   // ex: 228XXXXXXXX — vérifiez le format exigé par votre passerelle
    'message'   => $smsText,
];

// Tentatives d'envoi avec backoff exponentiel (1s, 2s, 4s)
$smsSent = false;
for ($attempt = 1; $attempt <= 3; $attempt++) {
    $chs = curl_init($config['sms']['api_url']);
    curl_setopt_array($chs, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($smsPayload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $smsResponse = curl_exec($chs);
    $smsErr = curl_error($chs);
    curl_close($chs);

    if ($smsResponse !== false) {
        ara_log("SMS envoyé (tentative $attempt/3) pour {$tx['identifier']} : " . substr((string)$smsResponse, 0, 300), $config);
        $smsSent = true;
        break;  // Succès, sortir de la boucle
    }

    if ($attempt < 3) {
        $backoffSeconds = pow(2, $attempt - 1);  // 1s, 2s, 4s
        ara_log("Échec SMS tentative $attempt/3 pour {$tx['identifier']} : $smsErr. Nouvelle tentative dans {$backoffSeconds}s...", $config);
        sleep($backoffSeconds);
    } else {
        ara_log("Tous les envois SMS ont échoué (3/3) pour {$tx['identifier']} : $smsErr", $config);
    }
}

if ($smsSent) {
    $pdo->prepare("UPDATE transactions SET sms_sent=1, updated_at=:u WHERE identifier=:id")
        ->execute([':u' => date('c'), ':id' => $tx['identifier']]);
}

// ---------- 7) Maintenance (GDPR: purge des old transactions + log rotation) ----------
ara_maintenance($config, 90);  // Garder les transactions 90 jours, puis supprimer

// ---------- 8) Réponse finale au webhook PayGate ----------
respond(['success' => true, 'message' => 'Ticket créé et SMS envoyé.']);
