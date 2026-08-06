<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/RouterosAPI.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . $config['allowed_origin']);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$route = trim((string)($_GET['route'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_response(['success' => false, 'message' => $message], $status);
}

function get_request_payload(): array
{
    $body = file_get_contents('php://input');
    if ($body !== '') {
        $data = json_decode($body, true);
        if (is_array($data)) return $data;
    }
    return $_POST;
}

function require_admin_token(array $config): void
{
    $token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
    if ($token === '' || !isset($config['admin']['token']) || !hash_equals($config['admin']['token'], $token)) {
        json_error('Administrateur non autorisé.', 401);
    }
}

function active_items(array $items): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return array_values(array_filter($items, function ($item) use ($now) {
        if (isset($item['active']) && $item['active'] === false) return false;
        if (!empty($item['start']) && !empty($item['end'])) {
            $start = DateTimeImmutable::createFromFormat('Y-m-d', $item['start'], new DateTimeZone('UTC'));
            $end = DateTimeImmutable::createFromFormat('Y-m-d', $item['end'], new DateTimeZone('UTC'));
            if (!$start || !$end) return false;
            $end = $end->setTime(23, 59, 59);
            return $start <= $now && $now <= $end;
        }
        return true;
    }));
}

function get_user_expiry_from_router(array $config, string $username): string
{
    $api = new RouterosAPI();
    $api->timeout = $config['mikrotik']['connect_timeout'] ?? 10;
    $api->attempts = $config['mikrotik']['connect_retries'] ?? 3;

    $connected = $api->connect(
        $config['mikrotik']['host'],
        $config['mikrotik']['api_user'],
        $config['mikrotik']['api_password'],
        $config['mikrotik']['api_port'] ?? 8728
    );

    if (!$connected) return '';

    $result = $api->comm('/ip/hotspot/user/print', ['?name' => $username]);
    $api->disconnect();

    if (isset($result[0]) && isset($result[0]['comment'])) {
        $comment = $result[0]['comment'];
        if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $comment, $matches)) {
            return $matches[1];
        }
    }
    return '';
}

switch ($route) {
    // -------------------- ADS --------------------
    case 'ads':
        try {
            $pdo = ara_db($config);
            $stmt = $pdo->query('SELECT * FROM ads');
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_response(['success' => true, 'items' => active_items($items)]);
        } catch (Throwable $e) {
            ara_log('api.php Ads error: ' . $e->getMessage(), $config, 'error');
            json_error('Impossible de charger les annonces.', 500);
        }
        break;

    // -------------------- EXPIRY --------------------
    case 'expiry':
        $user = trim((string)($_GET['user'] ?? ''));
        if ($user === '') json_error('Paramètre user manquant.');

        try {
            $expiry = get_user_expiry_from_router($config, $user);
            if ($expiry !== '') {
                json_response(['success' => true, 'expiry' => $expiry]);
                break;
            }
            json_response(['success' => true, 'expiry' => 'Non définie']);
        } catch (Throwable $e) {
            ara_log('api.php Expiry error: ' . $e->getMessage(), $config, 'error');
            json_error('Erreur lors de la récupération de l\'expiration.', 500);
        }
        break;

    // -------------------- TRACK --------------------
    case 'track':
        $payload = get_request_payload();
        $id = trim((string)($payload['id'] ?? ''));
        $type = trim((string)($payload['type'] ?? ''));
        $user = trim((string)($payload['user'] ?? 'anonymous'));
        if ($id === '' || !in_array($type, ['view', 'click'], true)) {
            json_error('Payload invalide.');
        }
        try {
            $pdo = ara_db($config);
            $stmt = $pdo->prepare(
                'INSERT INTO track_events (item_id, event_type, user, created_at)
                 VALUES (:item_id, :event_type, :user, :created_at)'
            );
            $stmt->execute([
                ':item_id' => $id,
                ':event_type' => $type,
                ':user' => $user,
                ':created_at' => date('c'),
            ]);
            if ($type === 'view') {
                $pdo->prepare('UPDATE ads SET views = views + 1 WHERE id = :id')->execute([':id' => $id]);
            } elseif ($type === 'click') {
                $pdo->prepare('UPDATE ads SET clicks = clicks + 1 WHERE id = :id')->execute([':id' => $id]);
            }
            json_response(['success' => true]);
        } catch (Throwable $e) {
            ara_log('api.php Track error: ' . $e->getMessage(), $config, 'error');
            json_error('Impossible d\'enregistrer le tracking.', 500);
        }
        break;

    // -------------------- ADMIN (simple) --------------------
    case 'admin':
        require_admin_token($config);
        try {
            $pdo = ara_db($config);
            $stmt = $pdo->query('SELECT id,type,title,active,price,views,clicks,created_at FROM ads ORDER BY created_at DESC');
            $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_response(['success' => true, 'ads' => $ads]);
        } catch (Throwable $e) {
            ara_log('api.php Admin error: ' . $e->getMessage(), $config, 'error');
            json_error('Erreur admin.', 500);
        }
        break;

    default:
        json_error('Route inconnue.', 404);
}
