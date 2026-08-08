<?php
/**
 * api.php — API backend avec routes adaptées pour Mikhmon
 * (fonctions Turso déplacées dans db.php)
 */

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/lib/RouterosAPI.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

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

function get_user_expiry_from_router(array $config, string $username): string
{
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
        return '';
    }

    $result = $api->comm('/ip/hotspot/user/print', [
        '?name' => $username
    ]);
    $api->disconnect();

    if (isset($result[0]) && isset($result[0]['comment'])) {
        $comment = trim($result[0]['comment']);

        if (preg_match('/^([a-z]{3}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2})$/i', $comment, $matches)) {
            $dt = DateTime::createFromFormat('M/d/Y H:i:s', $matches[1]);
            if ($dt !== false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $comment, $matches)) {
            return $matches[1];
        }

        if (preg_match('/expires-in:(\d+)([hdwmy])/', $comment, $matches)) {
            $value = (int)$matches[1];
            $unit = $matches[2];
            $now = time();
            switch ($unit) {
                case 'h': $seconds = $value * 3600; break;
                case 'd': $seconds = $value * 86400; break;
                case 'w': $seconds = $value * 604800; break;
                case 'm': $seconds = $value * 2592000; break;
                case 'y': $seconds = $value * 31536000; break;
                default: $seconds = $value * 86400;
            }
            return date('Y-m-d H:i:s', $now + $seconds);
        }

        if ($comment !== '') {
            return $comment;
        }
    }

    return '';
}

function ensure_hotspot_expiry_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS hotspot_expiry (
        user TEXT PRIMARY KEY,
        expiry TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
}

function ensure_hotspot_expiry_turso(array $config): void
{
    turso_pipeline($config, [[
        'sql'  => 'CREATE TABLE IF NOT EXISTS hotspot_expiry (
            user       TEXT PRIMARY KEY,
            expiry     TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )',
        'args' => [],
    ]]);
}

function require_sync_key(array $config): void
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $expected = $config['hotspot']['sync_key'] ?? '';
    if ($expected === '' || !hash_equals($expected, $key)) {
        json_error('Non autorisé.', 403);
    }
}

function ensure_router_logs_table(array $config): void
{
    turso_pipeline($config, [
        [
            'sql'  => "CREATE TABLE IF NOT EXISTS router_logs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                log_date    TEXT NOT NULL,
                log_time    TEXT NOT NULL,
                topics      TEXT NOT NULL DEFAULT '',
                message     TEXT NOT NULL,
                received_at TEXT NOT NULL,
                UNIQUE(log_date, log_time, message)
            )",
            'args' => [],
        ],
        [
            'sql'  => 'CREATE INDEX IF NOT EXISTS idx_router_logs_date ON router_logs(log_date)',
            'args' => [],
        ],
    ]);
}

function normalize_router_date(string $raw): string
{
    $raw = trim($raw);
    if (preg_match('/^([a-z]{3})\/(\d{2})\/(\d{4})$/i', $raw)) {
        $dt = DateTime::createFromFormat('M/d/Y', $raw);
        if ($dt !== false) return $dt->format('Y-m-d');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
    return date('Y-m-d');
}

// ================= ROUTES =================

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

    case 'set-expiry':
        require_sync_key($config);
        $payload = get_request_payload();
        $user   = trim((string)($payload['user']   ?? ''));
        $expiry = trim((string)($payload['expiry'] ?? ''));
        if ($user === '' || $expiry === '') {
            json_error('user et expiry requis.');
        }
        try {
            // 1) Base locale (reste pour la rapidité)
            $pdo = ara_db($config);
            ensure_hotspot_expiry_table($pdo);
            $stmt = $pdo->prepare(
                'INSERT INTO hotspot_expiry (user, expiry, updated_at)
                 VALUES (:user, :expiry, :updated_at)
                 ON CONFLICT(user) DO UPDATE SET expiry = excluded.expiry, updated_at = excluded.updated_at'
            );
            $stmt->execute([
                ':user'       => $user,
                ':expiry'     => $expiry,
                ':updated_at' => date('c'),
            ]);

            // 2) Supabase (persistance)
            $pdoSup = ara_db_supabase();
            $stmt2 = $pdoSup->prepare(
                "INSERT INTO hotspot_expiry (user_id, expiry, updated_at)
                 VALUES (?, ?, ?)
                 ON CONFLICT (user_id) DO UPDATE SET expiry = excluded.expiry, updated_at = excluded.updated_at"
            );
            $stmt2->execute([$user, $expiry, date('c')]);

            json_response(['success' => true]);
        } catch (Throwable $e) {
            ara_log('api.php Set-expiry error: ' . $e->getMessage(), $config, 'error');
            $message = 'Impossible d\'enregistrer l\'expiration.';
            if (!empty($config['debug'])) $message .= ' [debug] ' . $e->getMessage();
            json_error($message, 500);
        }
        break;

    case 'expiry':
        $user = trim((string)($_GET['user'] ?? ''));
        if ($user === '') {
            json_error('Paramètre user manquant.');
        }

        try {
            // 1) Base locale
            $pdo = ara_db($config);
            ensure_hotspot_expiry_table($pdo);
            $stmt = $pdo->prepare('SELECT expiry FROM hotspot_expiry WHERE user = :user');
            $stmt->execute([':user' => $user]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['expiry'])) {
                json_response(['success' => true, 'expiry' => $row['expiry']]);
            }

            // 2) Supabase
            try {
                $pdoSup = ara_db_supabase();
                $stmt2 = $pdoSup->prepare('SELECT expiry FROM hotspot_expiry WHERE user_id = ?');
                $stmt2->execute([$user]);
                $row2 = $stmt2->fetch();
                if ($row2 && !empty($row2['expiry'])) {
                    // Restaurer la locale si nécessaire
                    try {
                        $pdo = ara_db($config);
                        ensure_hotspot_expiry_table($pdo);
                        $stmt = $pdo->prepare(
                            'INSERT INTO hotspot_expiry (user, expiry, updated_at)
                             VALUES (:user, :expiry, :updated_at)
                             ON CONFLICT(user) DO UPDATE SET expiry = excluded.expiry, updated_at = excluded.updated_at'
                        );
                        $stmt->execute([':user' => $user, ':expiry' => $row2['expiry'], ':updated_at' => date('c')]);
                    } catch (Throwable $e) {}

                    json_response(['success' => true, 'expiry' => $row2['expiry']]);
                }
            } catch (Throwable $e) {}

            // 3) Routeur en direct (inchangé)
            $expiry = get_user_expiry_from_router($config, $user);
            if ($expiry !== '') {
                // Mettre à jour locale + Supabase
                try {
                    $pdo = ara_db($config);
                    ensure_hotspot_expiry_table($pdo);
                    $stmt = $pdo->prepare(
                        'INSERT INTO hotspot_expiry (user, expiry, updated_at)
                         VALUES (:user, :expiry, :updated_at)
                         ON CONFLICT(user) DO UPDATE SET expiry = excluded.expiry, updated_at = excluded.updated_at'
                    );
                    $stmt->execute([':user' => $user, ':expiry' => $expiry, ':updated_at' => date('c')]);

                    $pdoSup = ara_db_supabase();
                    $stmt2 = $pdoSup->prepare(
                        "INSERT INTO hotspot_expiry (user_id, expiry, updated_at)
                         VALUES (?, ?, ?)
                         ON CONFLICT (user_id) DO UPDATE SET expiry = excluded.expiry, updated_at = excluded.updated_at"
                    );
                    $stmt2->execute([$user, $expiry, date('c')]);
                } catch (Throwable $e) {}

                json_response(['success' => true, 'expiry' => $expiry]);
            }

            // 4) Rien trouvé
            json_error('Expiration non disponible.', 404);

        } catch (Throwable $e) {
            ara_log('api.php Expiry error: ' . $e->getMessage(), $config, 'error');
            $message = 'Erreur lors de la récupération de l\'expiration.';
            if (!empty($config['debug'])) $message .= ' [debug] ' . $e->getMessage();
            json_error($message, 500);
        }
        break;

    case 'push-logs':
        require_sync_key($config);
        $date = normalize_router_date($_GET['date'] ?? '');
        $body = file_get_contents('php://input');
        if (trim($body) === '') {
            json_error('Corps vide : aucun log à enregistrer.');
        }
        try {
            $pdo = ara_db_supabase();
            $now      = date('c');
            $inserted = 0;
            $skipped  = 0;
            $stmt = $pdo->prepare(
                "INSERT INTO router_logs (log_date, log_time, topics, message, received_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT (log_date, log_time, message) DO NOTHING"
            );
            foreach (explode("\n", $body) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $parts = explode("\t", $line, 3);
                if (count($parts) < 3) continue;
                [$log_time, $topics, $message] = $parts;
                $log_time = trim($log_time);
                $topics   = trim($topics);
                $message  = trim($message);
                if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $log_time)) continue;
                try {
                    $stmt->execute([$date, $log_time, $topics, $message, $now]);
                    $inserted++;
                } catch (Throwable $e) {
                    $skipped++;
                }
            }
            json_response(['success' => true, 'date' => $date, 'inserted' => $inserted, 'skipped' => $skipped]);
        } catch (Throwable $e) {
            ara_log('api.php Push-logs error: ' . $e->getMessage(), $config, 'error');
            $msg = 'Erreur lors de l\'enregistrement des logs.';
            if (!empty($config['debug'])) $msg .= ' [debug] ' . $e->getMessage();
            json_error($msg, 500);
        }
        break;

    case 'get-logs':
        require_admin_token($config);
        $date  = normalize_router_date($_GET['date'] ?? date('Y-m-d'));
        $topic = trim($_GET['topic'] ?? '');
        try {
            $pdo = ara_db_supabase();
            if ($topic !== '') {
                $stmt = $pdo->prepare("SELECT log_time, topics, message FROM router_logs WHERE log_date = ? AND topics LIKE ? ORDER BY log_time ASC");
                $stmt->execute([$date, '%' . $topic . '%']);
            } else {
                $stmt = $pdo->prepare("SELECT log_time, topics, message FROM router_logs WHERE log_date = ? ORDER BY log_time ASC");
                $stmt->execute([$date]);
            }
            $rows = $stmt->fetchAll();
            json_response(['success' => true, 'date' => $date, 'count' => count($rows), 'logs' => $rows]);
        } catch (Throwable $e) {
            ara_log('api.php Get-logs error: ' . $e->getMessage(), $config, 'error');
            $msg = 'Erreur lors de la récupération des logs.';
            if (!empty($config['debug'])) $msg .= ' [debug] ' . $e->getMessage();
            json_error($msg, 500);
        }
        break;
    case 'get-sales':
        require_admin_token($config);
        $startDate = $_GET['start'] ?? date('Y-m-01');
        $endDate   = $_GET['end']   ?? date('Y-m-d');

        try {
            $pdo = ara_db_supabase();

            // CA total
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS total_ca FROM sales_log WHERE sale_date BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate]);
            $totalCA = (int)$stmt->fetchColumn();

            // Nombre de tickets
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_log WHERE sale_date BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate]);
            $totalTickets = (int)$stmt->fetchColumn();

            // Par profil
            $stmt = $pdo->prepare("SELECT profile, COUNT(*) AS nb, COALESCE(SUM(amount),0) AS ca FROM sales_log WHERE sale_date BETWEEN ? AND ? GROUP BY profile ORDER BY ca DESC");
            $stmt->execute([$startDate, $endDate]);
            $profileStats = $stmt->fetchAll();

            // Dernières ventes
            $stmt = $pdo->prepare("SELECT sale_date, sale_time, username, profile, amount FROM sales_log WHERE sale_date BETWEEN ? AND ? ORDER BY sale_date DESC, sale_time DESC LIMIT 200");
            $stmt->execute([$startDate, $endDate]);
            $salesDetails = $stmt->fetchAll();

            json_response([
                'success'       => true,
                'total_ca'      => $totalCA,
                'total_tickets' => $totalTickets,
                'profile_stats' => $profileStats,
                'sales'         => $salesDetails,
            ]);
        } catch (Throwable $e) {
            ara_log('api.php Get-sales error: ' . $e->getMessage(), $config, 'error');
            json_error('Erreur lors de la récupération des ventes.', 500);
        }
        break;

    case 'log-sale':
        require_sync_key($config);
        $payload = get_request_payload();
        $date    = $payload['date']    ?? '';
        $time    = $payload['time']    ?? '';
        $user    = $payload['user']    ?? '';
        $amount  = $payload['amount']  ?? '';
        $ip      = $payload['ip']      ?? '';
        $mac     = $payload['mac']     ?? '';
        $profile = $payload['profile'] ?? '';
        $comment = $payload['comment'] ?? '';
        if ($user === '') {
            json_error('Données de vente incomplètes.');
        }
        try {
            $pdo = ara_db_supabase();
            $stmt = $pdo->prepare("INSERT INTO sales_log (sale_date, sale_time, username, amount, ip, mac, profile, comment, received_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$date, $time, $user, $amount, $ip, $mac, $profile, $comment, date('c')]);
            json_response(['success' => true]);
        } catch (Throwable $e) {
            ara_log('api.php Log-sale error: ' . $e->getMessage(), $config, 'error');
            json_error('Erreur lors de l\'enregistrement de la vente.', 500);
        }
        break;

    case 'get-sales-daily':
        require_admin_token($config);
        $startDate = $_GET['start'] ?? date('Y-m-01');
        $endDate   = $_GET['end']   ?? date('Y-m-d');
        try {
            $pdo = ara_db_supabase();
            $stmt = $pdo->prepare("SELECT sale_date, SUM(amount) AS total FROM sales_log WHERE sale_date BETWEEN ? AND ? GROUP BY sale_date ORDER BY sale_date ASC");
            $stmt->execute([$startDate, $endDate]);
            $daily = $stmt->fetchAll();
            json_response(['success' => true, 'daily' => $daily]);
        } catch (Throwable $e) {
            json_error('Erreur get-sales-daily: ' . $e->getMessage(), 500);
        }
        break;

        case 'push-status':
        require_sync_key($config);
        $payload = get_request_payload();
        $activeCount = (int)($payload['active'] ?? 0);
        $usersRaw = (string)($payload['users'] ?? '');
        $now = date('c');
        $date = date('Y-m-d');
        $time = date('H:i:s');
        try {
            // 1) Locale (rapide)
            $pdo = ara_db($config);
            $pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                snapshot_date TEXT NOT NULL,
                snapshot_time TEXT NOT NULL,
                active_count INTEGER NOT NULL,
                users_blob TEXT,
                received_at TEXT NOT NULL
            )");
            $stmt = $pdo->prepare("INSERT INTO hotspot_snapshots 
                (snapshot_date, snapshot_time, active_count, users_blob, received_at)
                VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$date, $time, $activeCount, $usersRaw, $now]);

            // 2) Supabase (persistance)
            $pdoSup = ara_db_supabase();
            $stmt2 = $pdoSup->prepare("INSERT INTO hotspot_snapshots 
                (snapshot_date, snapshot_time, active_count, users_blob, received_at)
                VALUES (?, ?, ?, ?, ?)");
            $stmt2->execute([$date, $time, $activeCount, $usersRaw, $now]);

            json_response(['success' => true, 'active' => $activeCount]);
        } catch (Throwable $e) {
            ara_log('api.php Push-status error: ' . $e->getMessage(), $config, 'error');
            json_error('Erreur lors de l\'enregistrement du statut.', 500);
        }
        break;
    
    default:
        json_error('Route inconnue.', 404);
}
