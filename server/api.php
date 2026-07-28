<?php
/**
 * api.php — Minimal backend stub for portal loyalty, ads, and tracking.
 *
 * Routes:
 *   GET  /api.php?route=ads                   -> active ads + products
 *   GET  /api.php?route=loyalty&user=         -> loyalty info for a user
 *   POST /api.php?route=track                 -> record click/view event
 *   GET  /api.php?route=admin&token=...       -> admin overview (ads + loyalty)
 *   POST /api.php?route=admin_save_ad         -> add or update an ad (requires token)
 *   POST /api.php?route=admin_delete_ad       -> delete an ad (requires token)
 *   POST /api.php?route=admin_reseed_ads      -> reseed ads from ads.json (requires token)
 *   GET  /api.php?route=expiry&user=          -> get expiry date for a user (connects to MikroTik)
 */

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
        if (is_array($data)) {
            return $data;
        }
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

function upsert_ad(PDO $pdo, array $item): void
{
    $now = date('c');
    $id = trim((string)($item['id'] ?? uniqid('ad-')));
    $stmt = $pdo->prepare(
        'INSERT INTO ads (id,type,title,description,image,url,start,end,active,price,views,clicks,created_at,updated_at)
         VALUES (:id,:type,:title,:description,:image,:url,:start,:end,:active,:price,COALESCE(:views,0),COALESCE(:clicks,0),:created_at,:updated_at)
         ON CONFLICT(id) DO UPDATE SET
             type=excluded.type,
             title=excluded.title,
             description=excluded.description,
             image=excluded.image,
             url=excluded.url,
             start=excluded.start,
             end=excluded.end,
             active=excluded.active,
             price=excluded.price,
             updated_at=excluded.updated_at'
    );
    $stmt->execute([
        ':id' => $id,
        ':type' => $item['type'] ?? 'sponsored',
        ':title' => $item['title'] ?? 'Annonce',
        ':description' => $item['description'] ?? '',
        ':image' => $item['image'] ?? '',
        ':url' => $item['url'] ?? '',
        ':start' => $item['start'] ?? null,
        ':end' => $item['end'] ?? null,
        ':active' => !empty($item['active']) && $item['active'] !== 'false' ? 1 : 0,
        ':price' => isset($item['price']) ? (int)$item['price'] : null,
        ':views' => $item['views'] ?? 0,
        ':clicks' => $item['clicks'] ?? 0,
        ':created_at' => $item['created_at'] ?? $now,
        ':updated_at' => $now,
    ]);
}

function delete_ad(PDO $pdo, string $id): void
{
    $pdo->prepare('DELETE FROM ads WHERE id = :id')->execute([':id' => $id]);
}

function load_ads_from_file(): array
{
    $path = __DIR__ . '/ads.json';
    if (!is_file($path)) {
        return [];
    }
    $content = file_get_contents($path);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function seed_ads(PDO $pdo): void
{
    $items = load_ads_from_file();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ads');
    $stmt->execute();
    $count = (int)$stmt->fetchColumn();
    if ($count > 0) {
        return;
    }
    $insert = $pdo->prepare(
        'INSERT INTO ads (id,type,title,description,image,url,start,end,active,price,views,clicks,created_at,updated_at)
         VALUES (:id,:type,:title,:description,:image,:url,:start,:end,:active,:price,0,0,:created_at,:updated_at)'
    );
    $now = date('c');
    foreach ($items as $item) {
        $insert->execute([
            ':id' => $item['id'] ?? uniqid('ad-'),
            ':type' => $item['type'] ?? 'sponsored',
            ':title' => $item['title'] ?? 'Annonce',
            ':description' => $item['description'] ?? '',
            ':image' => $item['image'] ?? '',
            ':url' => $item['url'] ?? '',
            ':start' => $item['start'] ?? null,
            ':end' => $item['end'] ?? null,
            ':active' => !isset($item['active']) || $item['active'] ? 1 : 0,
            ':price' => isset($item['price']) ? (int)$item['price'] : null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

function active_items(array $items): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return array_values(array_filter($items, function ($item) use ($now) {
        if (isset($item['active']) && $item['active'] === false) {
            return false;
        }
        if (!empty($item['start']) && !empty($item['end'])) {
            $start = DateTimeImmutable::createFromFormat('Y-m-d', $item['start'], new DateTimeZone('UTC'));
            $end = DateTimeImmutable::createFromFormat('Y-m-d', $item['end'], new DateTimeZone('UTC'));
            if (!$start || !$end) {
                return false;
            }
            $end = $end->setTime(23, 59, 59);
            return $start <= $now && $now <= $end;
        }
        return true;
    }));
}

function get_loyalty(PDO $pdo, string $user): array
{
    $stmt = $pdo->prepare('SELECT * FROM loyalty WHERE user = :user');
    $stmt->execute([':user' => $user]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    $referral_code = strtoupper(substr(sha1($user . microtime(true)), 0, 8));
    $now = date('c');
    $stmt = $pdo->prepare(
        'INSERT INTO loyalty (user, points, topups, referral_code, created_at, updated_at)
         VALUES (:user, 0, 0, :referral_code, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':user' => $user,
        ':referral_code' => $referral_code,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    return [
        'user' => $user,
        'points' => 0,
        'topups' => 0,
        'referral_code' => $referral_code,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

function record_track_event(PDO $pdo, string $itemId, string $eventType, ?string $user): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO track_events (item_id, event_type, user, created_at)
         VALUES (:item_id, :event_type, :user, :created_at)'
    );
    $stmt->execute([
        ':item_id' => $itemId,
        ':event_type' => $eventType,
        ':user' => $user,
        ':created_at' => date('c'),
    ]);
    if ($eventType === 'view') {
        $pdo->prepare('UPDATE ads SET views = views + 1 WHERE id = :id')->execute([':id' => $itemId]);
    } elseif ($eventType === 'click') {
        $pdo->prepare('UPDATE ads SET clicks = clicks + 1 WHERE id = :id')->execute([':id' => $itemId]);
    }
}

switch ($route) {
    case 'ads':
        try {
            $pdo = ara_db($config);
            seed_ads($pdo);
            $stmt = $pdo->query('SELECT * FROM ads');
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            ara_log('api.php Ads DB error: ' . $e->getMessage(), $config, 'error');
            json_error('Impossible de charger les annonces.', 500);
        }
        json_response(['success' => true, 'items' => active_items($items)]);
        break;

    case 'loyalty':
        $user = trim((string)($_GET['user'] ?? ''));
        if ($user === '') {
            json_error('Paramètre user manquant.');
        }
        try {
            $pdo = ara_db($config);
            $loyalty = get_loyalty($pdo, $user);
            $points = (int)$loyalty['points'];
            $topups = (int)$loyalty['topups'];
            $nextReward = $topups >= 5
                ? 'Vous avez atteint le nombre de recharges pour un avantage spécial. Contactez le support.'
                : 'Rechargez ' . (5 - $topups) . ' fois de plus pour un bonus de 50%.';
        } catch (Throwable $e) {
            ara_log('api.php Loyalty DB error: ' . $e->getMessage(), $config, 'error');
            json_error('Impossible de récupérer le programme fidélité.', 500);
        }
        json_response([
            'success' => true,
            'user' => $user,
            'points' => $points,
            'topups' => $topups,
            'nextReward' => $nextReward,
            'referral_code' => $loyalty['referral_code'],
        ]);
        break;

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
            record_track_event($pdo, $id, $type, $user);
        } catch (Throwable $e) {
            ara_log('api.php Track DB error: ' . $e->getMessage(), $config, 'error');
            json_error('Impossible d\'enregistrer la statistique.', 500);
        }
        json_response(['success' => true]);
        break;

    case 'admin':
        require_admin_token($config);
        try {
            $pdo = ara_db($config);
            $summary = [
                'total_ads' => (int)$pdo->query('SELECT COUNT(*) FROM ads')->fetchColumn(),
                'active_ads' => (int)$pdo->query('SELECT COUNT(*) FROM ads WHERE active = 1')->fetchColumn(),
                'total_loyalty_users' => (int)$pdo->query('SELECT COUNT(*) FROM loyalty')->fetchColumn(),
                'total_track_events' => (int)$pdo->query('SELECT COUNT(*) FROM track_events')->fetchColumn(),
            ];
            $stmt = $pdo->query('SELECT id,type,title,active,price,views,clicks,created_at FROM ads ORDER BY created_at DESC LIMIT 50');
            $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->query('SELECT user,points,topups,referral_code,updated_at FROM loyalty ORDER BY updated_at DESC LIMIT 20');
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            ara_log('api.php Admin DB error: ' . $e->getMessage(), $config, 'error');
            json_error('Impossible de charger les données d\'administration.', 500);
        }
        json_response(['success' => true, 'summary' => $summary, 'ads' => $ads, 'loyalty' => $users]);
        break;

    case 'admin_save_ad':
        require_admin_token($config);
        $payload = get_request_payload();
        if (!is_array($payload)) {
            json_error('Payload invalide.');
        }
        if (trim((string)($payload['id'] ?? '')) === '') {
            $payload['id'] = uniqid('ad-');
        }
        try {
            $pdo = ara_db($config);
            upsert_ad($pdo, $payload);
        } catch (Throwable $e) {
            ara_log('api.php Admin save ad error: ' . $e->getMessage(), $config, 'error');
            json_error('Impossible d\'enregistrer l\'annonce.', 500);
        }
        json_response(['success' => true, 'id' => $payload['id']]);
        break;

    case 'admin_delete_ad':
        require_admin_token($config);
        $payload = get_request_payload();
        $id = trim((string)($payload['id'] ?? ''));
        if ($id === '') {
            json_error('ID d\'annonce manquant.');
        }
        try {
            $pdo = ara_db($config);
            delete_ad($pdo, $id);
        } catch (Throwable $e) {
            ara_log('api.php Admin delete ad error: ' . $e->getMessage(), $config, 'error');
            json_error('Impossible de supprimer l\'annonce.', 500);
        }
        json_response(['success' => true]);
        break;

    case 'admin_reseed_ads':
        require_admin_token($config);
        try {
            $pdo = ara_db($config);
            $pdo->exec('DELETE FROM ads');
            seed_ads($pdo);
            $stmt = $pdo->query('SELECT * FROM ads');
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            ara_log('api.php Admin reseed ads error: ' . $e->getMessage(), $config, 'error');
            json_error('Impossible de recharger les annonces.', 500);
        }
        json_response(['success' => true, 'items' => active_items($items)]);
        break;

    case 'expiry':
        $user = trim((string)($_GET['user'] ?? ''));
        if ($user === '') {
            json_error('Paramètre user manquant.');
        }
        try {
            // Connexion à RouterOS via la classe améliorée
            $api = new RouterosAPI();
            $api->timeout = $config['mikrotik']['connect_timeout'] ?? 10;
            $api->attempts = $config['mikrotik']['connect_retries'] ?? 3;
            $port = $config['mikrotik']['api_port'] ?? 8728;
            $connected = $api->connect(
                $config['mikrotik']['host'],
                $config['mikrotik']['api_user'],
                $config['mikrotik']['api_password'],
                $port
            );
            if (!$connected) {
                json_error('Connexion au routeur impossible.', 502);
            }
            $getUser = $api->comm('/ip/hotspot/user/print', ['?name' => $user]);
            $api->disconnect();

            $expiry = '';
            if (isset($getUser[0]) && isset($getUser[0]['comment'])) {
                $comment = $getUser[0]['comment'];
                if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $comment, $matches)) {
                    $expiry = $matches[1];
                }
            }
            json_response(['success' => true, 'expiry' => $expiry]);
        } catch (Throwable $e) {
            ara_log('api.php Expiry error: ' . $e->getMessage(), $config, 'error');
            json_error('Erreur interne.', 500);
        }
        break;

    default:
        json_error('Route inconnue.', 404);
}