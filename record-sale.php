<?php
declare(strict_types=1);

/**
 * Canonical sale recording endpoint used by MikroTik/Mikhmon.
 *
 * This endpoint intentionally does NOT write to sales_log. sales_log is the
 * legacy technical journal. All business reporting is based on
 * sales_transactions.
 */

require __DIR__ . '/db.php';

$config = require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function record_sale_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function record_sale_error(string $message, int $status = 400, string $code = 'SALE_ERROR'): never
{
    record_sale_json([
        'success' => false,
        'error' => ['code' => $code, 'message' => $message],
        'message' => $message,
    ], $status);
}

function require_record_sale_key(array $config): void
{
    $expected = trim((string)($config['hotspot']['sync_key'] ?? ''));
    $provided = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));

    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        record_sale_error('Non autorisé.', 401, 'UNAUTHORIZED');
    }
}

function normalize_record_sale_date(string $raw): string
{
    $raw = trim($raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
        return $raw;
    }

    $parsed = DateTimeImmutable::createFromFormat('M/d/Y', ucfirst(strtolower($raw)), new DateTimeZone('UTC'));
    if ($parsed !== false) {
        return $parsed->format('Y-m-d');
    }

    $parsed = DateTimeImmutable::createFromFormat('M/d/Y', $raw, new DateTimeZone('UTC'));
    if ($parsed !== false) {
        return $parsed->format('Y-m-d');
    }

    record_sale_error('Date de vente invalide.', 422, 'INVALID_DATE');
}

function request_payload(): array
{
    $body = file_get_contents('php://input');
    if ($body === false || trim($body) === '') {
        return is_array($_POST) ? $_POST : [];
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        record_sale_error('JSON invalide.', 400, 'INVALID_JSON');
    }
    return $payload;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    record_sale_error('Méthode POST requise.', 405, 'METHOD_NOT_ALLOWED');
}

require_record_sale_key($config);
$payload = request_payload();

$user = trim((string)($payload['user'] ?? ''));
$profile = trim((string)($payload['profile'] ?? ''));
$amount = filter_var($payload['amount'] ?? null, FILTER_VALIDATE_INT);
$ip = trim((string)($payload['ip'] ?? ''));
$mac = trim((string)($payload['mac'] ?? ''));
$comment = trim((string)($payload['comment'] ?? ''));
$currency = strtoupper(trim((string)($payload['currency'] ?? 'XOF')));
$status = strtoupper(trim((string)($payload['status'] ?? 'PAID')));
$isBusinessSale = array_key_exists('is_business_sale', $payload)
    ? filter_var($payload['is_business_sale'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
    : true;
$voucherExpiry = trim((string)($payload['voucher_expires_at'] ?? $comment));
$saleDate = trim((string)($payload['date'] ?? ''));
$saleTime = trim((string)($payload['time'] ?? ''));

if ($user === '' || $profile === '') {
    record_sale_error('user et profile sont obligatoires.', 422, 'INVALID_SALE');
}
if ($amount === false || $amount === null || $amount <= 0) {
    record_sale_error('Le montant doit être un entier positif.', 422, 'INVALID_AMOUNT');
}
if (!in_array($status, ['PAID', 'VOID', 'REFUNDED'], true)) {
    record_sale_error('Statut de vente invalide.', 422, 'INVALID_STATUS');
}
if ($currency === '') {
    $currency = 'XOF';
}

$saleDate = normalize_record_sale_date($saleDate !== '' ? $saleDate : gmdate('Y-m-d'));
$saleTime = $saleTime !== '' ? $saleTime : gmdate('H:i:s');

$clientTransactionId = trim((string)($payload['transaction_id'] ?? ''));
if ($clientTransactionId !== '') {
    if (strlen($clientTransactionId) > 180) {
        record_sale_error('transaction_id trop long.', 422, 'INVALID_TRANSACTION_ID');
    }
    $transactionId = $clientTransactionId;
} else {
    // Deterministic fallback makes HTTP retries idempotent while allowing two
    // legitimate sales of the same voucher at different times to coexist.
    $fingerprint = implode("\x1f", [
        $saleDate,
        $saleTime,
        $user,
        (string)$amount,
        $ip,
        $mac,
        $profile,
        $comment,
    ]);
    $transactionId = 'TX-' . hash('sha256', $fingerprint);
}

try {
    $pdo = ara_db_supabase();

    $stmt = $pdo->prepare(
        'INSERT INTO sales_transactions (
            transaction_id, sale_date, sale_time, username, amount, currency,
            ip, mac, profile, comment, voucher_expires_at, status,
            is_business_sale, source, inferred, metadata, created_at, updated_at
         ) VALUES (
            :transaction_id, :sale_date, :sale_time, :username, :amount, :currency,
            :ip, :mac, :profile, :comment, :voucher_expires_at, :status,
            :is_business_sale, :source, FALSE, CAST(:metadata AS jsonb), now(), now()
         )
         ON CONFLICT (transaction_id) DO NOTHING'
    );

    $metadata = json_encode([
        'api_version' => 1,
        'received_at' => gmdate('c'),
        'client_transaction_id' => $clientTransactionId !== '' ? $clientTransactionId : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt->execute([
        ':transaction_id' => $transactionId,
        ':sale_date' => $saleDate,
        ':sale_time' => $saleTime,
        ':username' => $user,
        ':amount' => (int)$amount,
        ':currency' => $currency,
        ':ip' => $ip !== '' ? $ip : null,
        ':mac' => $mac !== '' ? $mac : null,
        ':profile' => $profile,
        ':comment' => $comment !== '' ? $comment : null,
        ':voucher_expires_at' => $voucherExpiry !== '' ? $voucherExpiry : null,
        ':status' => $status,
        ':is_business_sale' => $isBusinessSale === null ? true : $isBusinessSale,
        ':source' => 'MIKROTIK_MIKHMON',
        ':metadata' => $metadata ?: '{}',
    ]);

    if ($stmt->rowCount() === 1) {
        record_sale_json([
            'success' => true,
            'recorded' => true,
            'duplicate' => false,
            'transaction_id' => $transactionId,
        ]);
    }

    $existing = $pdo->prepare(
        'SELECT transaction_id, username, amount, profile, status
         FROM sales_transactions
         WHERE transaction_id = :transaction_id'
    );
    $existing->execute([':transaction_id' => $transactionId]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        record_sale_error('Impossible de confirmer la transaction.', 500, 'TRANSACTION_CONFIRMATION_FAILED');
    }

    if ((string)$row['username'] !== $user || (int)$row['amount'] !== (int)$amount || (string)$row['profile'] !== $profile) {
        record_sale_error(
            'transaction_id déjà utilisé avec des données différentes.',
            409,
            'TRANSACTION_CONFLICT'
        );
    }

    record_sale_json([
        'success' => true,
        'recorded' => false,
        'duplicate' => true,
        'transaction_id' => $transactionId,
    ]);
} catch (Throwable $e) {
    error_log('[record-sale] ' . $e->getMessage());
    record_sale_error('Impossible d’enregistrer la vente.', 500, 'DATABASE_ERROR');
}
