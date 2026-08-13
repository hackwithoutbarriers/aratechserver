<?php
declare(strict_types=1);

/**
 * ARA Tech WiFi — Meta WhatsApp Cloud API webhook
 *
 * GET  : Meta webhook verification
 * POST : incoming WhatsApp messages
 *
 * Required environment variables:
 *   WHATSAPP_VERIFY_TOKEN
 *   WHATSAPP_TOKEN
 *   WHATSAPP_PHONE_NUMBER_ID
 *   WHATSAPP_GRAPH_API_VERSION optional, defaults to v23.0
 */

require_once __DIR__ . '/../db.php';

const WHATSAPP_ADMIN_PHONE = '22892709708';
const WHATSAPP_BUSINESS_PHONE = '+22892709708';

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function whatsapp_graph_send_text(
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

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Erreur cURL Meta: ' . ($curlError ?: 'inconnue'));
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = ['raw' => $raw];
    }

    return [
        'http_code' => $httpCode,
        'body' => $body,
    ];
}

function send_bot_message(string $recipient, string $message): void
{
    $token = trim((string)(getenv('WHATSAPP_TOKEN') ?: ''));
    $phoneNumberId = trim((string)(getenv('WHATSAPP_PHONE_NUMBER_ID') ?: ''));

    if ($token === '' || $phoneNumberId === '') {
        throw new RuntimeException(
            'WHATSAPP_TOKEN ou WHATSAPP_PHONE_NUMBER_ID non configuré.'
        );
    }

    $result = whatsapp_graph_send_text(
        $token,
        $phoneNumberId,
        $recipient,
        $message
    );

    if ($result['http_code'] < 200 || $result['http_code'] >= 300) {
        $error = $result['body']['error']['message'] ?? 'Erreur Meta inconnue.';
        throw new RuntimeException(
            'WhatsApp API HTTP ' . $result['http_code'] . ': ' . $error
        );
    }
}

function latest_router_status(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT
            router_identity,
            router_uptime,
            router_version,
            cpu_load,
            memory_total,
            memory_free,
            received_at
         FROM hotspot_snapshots
         ORDER BY id DESC
         LIMIT 1'
    );

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [
            'online' => false,
            'identity' => null,
            'uptime' => null,
            'cpu' => null,
            'received_at' => null,
        ];
    }

    $online = false;
    if (!empty($row['received_at'])) {
        try {
            $received = new DateTimeImmutable((string)$row['received_at']);
            $age = time() - $received->getTimestamp();
            $online = $age < 360;
        } catch (Throwable $e) {
            $online = false;
        }
    }

    return [
        'online' => $online,
        'identity' => $row['router_identity'] ?? null,
        'uptime' => $row['router_uptime'] ?? null,
        'cpu' => $row['cpu_load'] ?? null,
        'received_at' => $row['received_at'] ?? null,
    ];
}

function today_finances(PDO $pdo): array
{
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');

    $salesStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0)
         FROM sales_log
         WHERE sale_date = :today'
    );
    $salesStmt->execute([':today' => $today]);

    $expensesStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0)
         FROM expenses
         WHERE expense_date = :today'
    );
    $expensesStmt->execute([':today' => $today]);

    $sales = (int)$salesStmt->fetchColumn();
    $expenses = (int)$expensesStmt->fetchColumn();

    return [
        'date' => $today,
        'sales' => $sales,
        'expenses' => $expenses,
        'profit' => $sales - $expenses,
    ];
}

function format_money(int $amount): string
{
    return number_format($amount, 0, ',', ' ') . ' FCFA';
}

/* -------------------------------------------------------------------------
 * GET — vérification Meta
 * ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = (string)($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $verifyToken = (string)(
        $_GET['hub_verify_token']
        ?? $_GET['hub.verify_token']
        ?? ''
    );
    $challenge = (string)(
        $_GET['hub_challenge']
        ?? $_GET['hub.challenge']
        ?? ''
    );

    $expectedToken = trim((string)(getenv('WHATSAPP_VERIFY_TOKEN') ?: ''));

    if (
        $mode === 'subscribe'
        && $expectedToken !== ''
        && hash_equals($expectedToken, $verifyToken)
    ) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit;
    }

    http_response_code(403);
    echo 'Forbidden';
    exit;
}

/* -------------------------------------------------------------------------
 * POST — réception des événements Meta
 * ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo 'Method Not Allowed';
    exit;
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || $rawBody === '') {
    json_response(['success' => true]);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    json_response(['success' => true]);
}

/*
 * Répondre immédiatement à Meta.
 * Le traitement reste volontairement léger pour éviter les timeouts webhook.
 */
try {
    $pdo = ara_db_supabase();

    if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
        json_response(['success' => true]);
    }

    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $change) {
            if (($change['field'] ?? '') !== 'messages') {
                continue;
            }

            $value = $change['value'] ?? [];
            if (!is_array($value)) {
                continue;
            }

            foreach (($value['messages'] ?? []) as $message) {
                $from = normalize_phone((string)($message['from'] ?? ''));
                $messageType = (string)($message['type'] ?? '');

                if ($from === '' || $messageType !== 'text') {
                    continue;
                }

                $text = trim((string)(
                    $message['text']['body']
                    ?? ''
                ));

                if ($text === '') {
                    continue;
                }

                /* ---------------------------------------------------------
                 * Sécurité : seules les commandes du numéro admin sont
                 * autorisées à interroger les données sensibles.
                 * --------------------------------------------------------- */
                $isAdmin = hash_equals(
                    WHATSAPP_ADMIN_PHONE,
                    $from
                );

                if (!$isAdmin) {
                    send_bot_message(
                        $from,
                        "Bonjour 👋\n\nCe numéro est réservé à l'administration ARA Tech WiFi. Votre message a bien été reçu."
                    );
                    continue;
                }

                $command = mb_strtolower(
                    trim($text),
                    'UTF-8'
                );

                if ($command === 'statut') {
                    $router = latest_router_status($pdo);

                    $status = $router['online']
                        ? '🟢 EN LIGNE'
                        : '🔴 HORS LIGNE / SNAPSHOT PÉRIMÉ';

                    $cpu = $router['cpu'] === null
                        ? 'N/D'
                        : ((int)$router['cpu'] . ' %');

                    $identity = $router['identity'] ?: 'N/D';
                    $uptime = $router['uptime'] ?: 'N/D';

                    $reply =
                        "📡 *ARA Tech WiFi — Statut*\n\n"
                        . "État : {$status}\n"
                        . "Routeur : {$identity}\n"
                        . "Uptime : {$uptime}\n"
                        . "CPU : {$cpu}";

                    send_bot_message($from, $reply);
                    continue;
                }

                if ($command === 'finances') {
                    $finance = today_finances($pdo);

                    $reply =
                        "💰 *ARA Tech WiFi — Finances du jour*\n\n"
                        . "Date : {$finance['date']}\n"
                        . "Recettes : " . format_money($finance['sales']) . "\n"
                        . "Dépenses : " . format_money($finance['expenses']) . "\n"
                        . "Bénéfice : " . format_money($finance['profit']);

                    send_bot_message($from, $reply);
                    continue;
                }

                send_bot_message(
                    $from,
                    "🤖 *Commandes ARA Tech WiFi*\n\n"
                    . "• Statut — état du routeur\n"
                    . "• Finances — recettes, dépenses et bénéfice du jour"
                );
            }
        }
    }

    json_response(['success' => true]);
} catch (Throwable $e) {
    error_log('[whatsapp-webhook] ' . $e->getMessage());

    /* Meta doit recevoir 200 autant que possible pour éviter les retries
       agressifs sur une erreur interne qui n'est pas liée au payload. */
    json_response(['success' => true]);
}
