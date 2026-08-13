<?php
declare(strict_types=1);

/**
 * ARA Tech WiFi
 * cron/process_notifications.php
 *
 * Usage CLI :
 *   php cron/process_notifications.php
 *
 * Usage HTTP :
 *   /cron/process_notifications.php?token=VOTRE_CRON_TOKEN
 *
 * Variable d'environnement :
 *   CRON_TOKEN
 *
 * L'envoi WhatsApp est volontairement simulé à ce stade.
 */

require_once __DIR__ . '/../db.php';

const WHATSAPP_BUSINESS_PHONE = '+22892709708';
const NOTIFICATION_BATCH_LIMIT = 50;

function cron_log(string $message, string $level = 'INFO'): void
{
    error_log(
        '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' .
        '[notification-worker] ' . $message
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

if (!cron_authorized()) {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

try {
    $pdo = ara_db_supabase();

    /*
     * Récupérer un lot borné de notifications.
     */
    $stmt = $pdo->prepare(
        "SELECT
            id,
            phone_number,
            message,
            status,
            created_at
         FROM notification_queue
         WHERE status = 'pending'
         ORDER BY created_at ASC, id ASC
         LIMIT :limit"
    );

    $stmt->bindValue(
        ':limit',
        NOTIFICATION_BATCH_LIMIT,
        PDO::PARAM_INT
    );

    $stmt->execute();

    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;
    $failed = 0;

    /*
     * -----------------------------------------------------------------------
     * Préparation des transitions d'état
     * -----------------------------------------------------------------------
     */
    $markSent = $pdo->prepare(
        "UPDATE notification_queue
         SET
            status = 'sent',
            sent_at = now()
         WHERE id = :id
           AND status = 'pending'"
    );

    $markFailed = $pdo->prepare(
        "UPDATE notification_queue
         SET
            status = 'failed',
            sent_at = NULL
         WHERE id = :id
           AND status = 'pending'"
    );

    foreach ($notifications as $notification) {
        $id = (int)$notification['id'];

        $phone = trim(
            (string)($notification['phone_number'] ?? '')
        );

        if ($phone === '') {
            $phone = WHATSAPP_BUSINESS_PHONE;
        }

        $message = (string)$notification['message'];

        try {
            /*
             * Payload destiné à la future intégration WhatsApp Business API.
             */
            $whatsappPayload = [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $message,
                ],
            ];

            /*
             * ----------------------------------------------------------------
             * SIMULATION D'ENVOI
             * ----------------------------------------------------------------
             */
            cron_log(
                sprintf(
                    '[WhatsApp Bot %s] Sending: %s',
                    $whatsappPayload['to'],
                    $message
                )
            );

            /*
             * Pour l'instant, si le payload a été correctement préparé,
             * on considère l'envoi comme réussi.
             */
            $markSent->execute([
                ':id' => $id,
            ]);

            if ($markSent->rowCount() === 1) {
                $sent++;
            }
        } catch (Throwable $e) {
            $failed++;

            $markFailed->execute([
                ':id' => $id,
            ]);

            cron_log(
                sprintf(
                    'Notification #%d failed: %s',
                    $id,
                    $e->getMessage()
                ),
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

        echo json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }
} catch (Throwable $e) {
    cron_log($e->getMessage(), 'ERROR');

    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            [
                'success' => false,
                'message' => 'Notification worker failed.',
            ],
            JSON_UNESCAPED_UNICODE
        );
    }

    exit(1);
}
