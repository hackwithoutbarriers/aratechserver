<?php
declare(strict_types=1);

/**
 * ARA Tech WiFi — WhatsApp notification worker
 *
 * CLI:
 *   php cron/process_notifications.php
 *
 * HTTP (Render Cron / scheduler):
 *   /cron/process_notifications.php?token=...
 *
 * Required environment variables:
 *   CRON_TOKEN
 *   WHATSAPP_TOKEN
 *   WHATSAPP_PHONE_NUMBER_ID
 *   WHATSAPP_GRAPH_API_VERSION   optional, defaults to v23.0
 */

require_once __DIR__ . '/../db.php';

const WHATSAPP_BUSINESS_PHONE = '+22892709708';
const NOTIFICATION_BATCH_LIMIT = 50;

function cron_log(string $message, string $level = 'INFO'): void
{
    error_log(
        '[' . gmdate('Y-m-d H:i:s') . '] [' . $level . '] [whatsapp-worker] ' . $message
    );
}

function cron_authorized(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }

    $expected = trim((string)(getenv('CRON_TOKEN') ?: ''));
    $provided = trim((string)($_GET['token'] ?? ''));

    return $expected !== ''
        && $provided !== ''
        && hash_equals($expected, $provided);
}

function whatsapp_send_text(
    string $accessToken,
    string $phoneNumberId,
    string $recipient,
    string $message
): array {
    $apiVersion = trim((string)(getenv('WHATSAPP_GRAPH_API_VERSION') ?: 'v23.0'));
    $url = 'https://graph.facebook.com/' . rawurlencode($apiVersion)
        . '/' . rawurlencode($phoneNumberId) . '/messages';

    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $recipient,
        'type' => 'text',
        'text' => [
            'preview_url' => false,
            'body' => $message,
        ],
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Impossible d\'initialiser cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ),
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException('Erreur cURL Meta: ' . ($curlError ?: 'inconnue'));
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        $decoded = ['raw' => $responseBody];
    }

    return [
        'http_code' => $httpCode,
        'body' => $decoded,
        'raw_body' => $responseBody,
    ];
}

if (!cron_authorized()) {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

$accessToken = trim((string)(getenv('WHATSAPP_TOKEN') ?: ''));
$phoneNumberId = trim((string)(getenv('WHATSAPP_PHONE_NUMBER_ID') ?: ''));

if ($accessToken === '' || $phoneNumberId === '') {
    cron_log('WHATSAPP_TOKEN ou WHATSAPP_PHONE_NUMBER_ID non configuré.', 'ERROR');
    exit(1);
}

try {
    $pdo = ara_db_supabase();

    $stmt = $pdo->prepare(
        "SELECT id, phone_number, message, status, created_at
         FROM notification_queue
         WHERE status = 'pending'
         ORDER BY created_at ASC, id ASC
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', NOTIFICATION_BATCH_LIMIT, PDO::PARAM_INT);
    $stmt->execute();

    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $markSent = $pdo->prepare(
        "UPDATE notification_queue
         SET status = 'sent', sent_at = now()
         WHERE id = :id AND status = 'pending'"
    );

    $markFailed = $pdo->prepare(
        "UPDATE notification_queue
         SET status = 'failed'
         WHERE id = :id AND status = 'pending'"
    );

    $sent = 0;
    $failed = 0;

    foreach ($notifications as $notification) {
        $id = (int)$notification['id'];
        $recipient = trim((string)($notification['phone_number'] ?? ''));
        $message = trim((string)($notification['message'] ?? ''));

        if ($recipient === '') {
            $recipient = WHATSAPP_BUSINESS_PHONE;
        }

        if ($message === '') {
            $markFailed->execute([':id' => $id]);
            $failed++;
            cron_log("Notification #{$id} refusée: message vide.", 'ERROR');
            continue;
        }

        try {
            $result = whatsapp_send_text(
                $accessToken,
                $phoneNumberId,
                $recipient,
                $message
            );

            $httpCode = $result['http_code'];
            $body = $result['body'];

            if ($httpCode >= 200 && $httpCode < 300) {
                $markSent->execute([':id' => $id]);

                if ($markSent->rowCount() === 1) {
                    $sent++;
                }

                $messageId = $body['messages'][0]['id'] ?? null;
                cron_log(
                    sprintf(
                        'Notification #%d sent to %s%s',
                        $id,
                        $recipient,
                        $messageId ? ' message_id=' . $messageId : ''
                    )
                );
            } else {
                $markFailed->execute([':id' => $id]);
                $failed++;

                $errorMessage = $body['error']['message'] ?? $result['raw_body'];
                cron_log(
                    sprintf(
                        'Notification #%d failed HTTP %d: %s',
                        $id,
                        $httpCode,
                        $errorMessage
                    ),
                    'ERROR'
                );
            }
        } catch (Throwable $e) {
            $markFailed->execute([':id' => $id]);
            $failed++;
            cron_log(
                sprintf('Notification #%d exception: %s', $id, $e->getMessage()),
                'ERROR'
            );
        }
    }

    $result = [
        'success' => true,
        'processed' => count($notifications),
        'sent' => $sent,
        'failed' => $failed,
    ];

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    cron_log($e->getMessage(), 'ERROR');

    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Notification worker failed.',
        ], JSON_UNESCAPED_UNICODE);
    }

    exit(1);
}
