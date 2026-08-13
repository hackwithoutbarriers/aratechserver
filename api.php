<?php
/**
 * api.php — API backend avec routes adaptées pour Mikhmon
 * (fonctions Turso déplacées dans db.php)
 */

declare(strict_types=1);

require __DIR__ . '/db.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if (function_exists('header_remove')) {
    header_remove('X-Powered-By');
}

$allowedOrigin = trim((string)($config['allowed_origin'] ?? ''));
$requestOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($allowedOrigin !== '' && $requestOrigin !== '' && hash_equals($allowedOrigin, $requestOrigin)) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Admin-Token, Authorization, X-CSRF-Token');

$route = trim((string)($_GET['route'] ?? ''));
if ($route !== '' && strlen($route) > 80) {
    json_error('Route invalide.', 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    try {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(500);
        $json = '{"success":false,"error":{"code":"INTERNAL_ERROR","message":"Une erreur interne est survenue."}}';
    }
    echo $json;
    exit;
}

function json_error(string $message, int $status = 400, string $code = 'API_ERROR'): void
{
    // Keep the legacy `message` key for existing consumers while exposing the
    // normalized Phase 5 error contract.
    json_response([
        'success' => false,
        'error' => ['code' => $code, 'message' => $message],
        'message' => $message,
    ], $status);
}

function get_request_payload(): array
{
    $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    $body = file_get_contents('php://input');
    if ($body !== false && trim($body) !== '') {
        $isJson = str_contains($contentType, 'application/json') || str_starts_with(ltrim($body), '{') || str_starts_with(ltrim($body), '[');
        if ($isJson) {
            try {
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                json_error('JSON invalide.', 400, 'INVALID_JSON');
            }
            if (!is_array($data)) {
                json_error('Le corps JSON doit être un objet ou un tableau.', 400, 'INVALID_JSON');
            }
            return $data;
        }
    }
    return is_array($_POST) ? $_POST : [];
}

function request_bearer_token(): string
{
    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $m)) {
        return trim($m[1]);
    }
    return '';
}

function require_admin_token(array $config): void
{
    $expected = trim((string)($config['admin']['token'] ?? ''));
    $token = trim((string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''));
    if ($token === '') {
        $token = request_bearer_token();
    }
    // Legacy query/body token remains accepted for compatibility with the
    // existing frontend, but new clients should use X-Admin-Token/Bearer.
    if ($token === '') {
        $token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
    }
    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        json_api_error('UNAUTHORIZED', 'Administrateur non autorisé.', 401);
    }
}

function validate_iso_date(string $value): bool
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
}

function validate_date_range(string $start, string $end): void
{
    if (!validate_iso_date($start) || !validate_iso_date($end) || $start > $end) {
        json_api_error('INVALID_REQUEST', 'Période de dates invalide.', 422);
    }
}

function validate_hotspot_time_limit(?string $value): bool
{
    if ($value === null || trim($value) === '') return true;
    return preg_match('/^(?:(?:\d+(?:\.\d+)?)(?:w|d|h|m|s))(?:\s+(?:\d+(?:\.\d+)?)(?:w|d|h|m|s))*$/i', trim($value)) === 1;
}

function validate_hotspot_data_limit($value): bool
{
    if ($value === null || $value === '') return true;
    if (is_int($value)) return $value >= 0;
    if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
        return strlen(ltrim($value, '0')) <= strlen((string)PHP_INT_MAX)
            && (strlen(ltrim($value, '0')) < strlen((string)PHP_INT_MAX) || ltrim($value, '0') <= (string)PHP_INT_MAX);
    }
    return is_float($value) && $value >= 0 && floor($value) === $value && $value <= PHP_INT_MAX;
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

function ensure_hotspot_expiry_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS hotspot_expiry (
        user TEXT PRIMARY KEY,
        expiry TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
}

// ---------------------------------------------------------------------
// HOTSPOT V2.1 — PHASE H2 : UTILISATEURS
// ---------------------------------------------------------------------

/**
 * Réponse de succès au contrat H2 : { success: true, data: {...} }
 */
function json_api_success($data, int $status = 200): void
{
    json_response(['success' => true, 'data' => $data], $status);
}

/**
 * Réponse d'erreur au contrat H2 : { success: false, error: { code, message } }
 * (distinct du format {success:false,message:...} utilisé par les routes
 * historiques de ce fichier — conservé tel quel pour H2 uniquement, voir
 * le rapport final §23/§Limitations).
 */
function json_api_error(string $code, string $message, int $status = 400): void
{
    json_response(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
}

function require_post_method(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_api_error('INVALID_REQUEST', 'Cette route nécessite une requête POST.', 405);
    }
}

function require_get_method(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_api_error('INVALID_REQUEST', 'Cette route nécessite une requête GET.', 405);
    }
}

/**
 * Table de file de commandes MikroTik (H2/H3).
 *
 * Le Render ne se connecte jamais au routeur privé : les commandes sont
 * persistées puis réclamées par le MikroTik via HTTPS polling.
 */
function ensure_hotspot_commands_table(PDO $pdo): void
{
    // Supabase/PostgreSQL schema is owned by database/migrations.
    // Runtime PHP must not create or mutate the Phase 2 schema.
}

function hotspot_command_limit(array $config): int
{
    $limit = (int)($config['hotspot']['command_poll_limit'] ?? 10);
    return max(1, min($limit, 25));
}

function hotspot_command_stale_seconds(array $config): int
{
    $seconds = (int)($config['hotspot']['command_processing_timeout'] ?? 900);
    return max(60, $seconds);
}

function hotspot_allowed_actions(): array
{
    return [
        'create', 'update', 'enable', 'disable', 'delete',
        // Ajoutées à l'étape "extension de la file asynchrone" : mêmes
        // transport (pending/claim/ack) et même table hotspot_commands que
        // les actions utilisateur ci-dessus. 'profile-*' réutilise la
        // colonne `username` comme identifiant générique de cible (ici le
        // nom du profil, pas un username hotspot) — voir le commentaire
        // sur queue_hotspot_command() pour le détail de ce choix.
        'profile-create', 'profile-update', 'profile-delete',
        'disconnect',
    ];
}

/**
 * Actions dont la cible est un NOM DE PROFIL (colonne `username` réutilisée
 * comme identifiant générique) plutôt qu'un vrai username hotspot.
 */
function hotspot_profile_actions(): array
{
    return ['profile-create', 'profile-update', 'profile-delete'];
}

function hotspot_validate_command_payload(string $action, array $payload): ?string
{
    if (!in_array($action, hotspot_allowed_actions(), true)) return 'Action inconnue.';

    if (in_array($action, hotspot_profile_actions(), true)) {
        $profileName = trim((string)($payload['username'] ?? ''));
        if (!hotspot_profile_name_valid($profileName)) return 'Nom de profil requis ou invalide.';
        if ($action === 'profile-create' && trim((string)($payload['rate_limit'] ?? '')) === '') {
            return 'rate_limit requis pour la création d’un profil.';
        }
        return null;
    }

    // 'disconnect' cible un username hotspot réellement en ligne, comme
    // 'enable'/'disable'/'delete' : mêmes règles de validation.
    $username = trim((string)($payload['username'] ?? ''));
    if ($username === '' || !hotspot_username_valid($username)) return 'Username requis ou invalide.';
    if ($action === 'create' && (string)($payload['password'] ?? '') === '') return 'Password requis.';
    return null;
}

function queue_hotspot_command(array $config, string $action, string $username, array $payload = []): ?int
{
    try {
        $action = strtolower($action);
        if (!in_array($action, hotspot_allowed_actions(), true)) {
            throw new InvalidArgumentException('Action hotspot inconnue.');
        }
        $pdo = ara_db_supabase();
        ensure_hotspot_commands_table($pdo);
        $payload['username'] = $username;
        $validation = hotspot_validate_command_payload($action, $payload);
        if ($validation !== null) {
            throw new InvalidArgumentException($validation);
        }

        // Idempotence: reuse an identical pending/processing command instead
        // of enqueueing the same operation twice. Completed commands are also
        // reused when their payload is identical, which protects repeated
        // browser submissions and CSV/API retries.
        $existingStmt = $pdo->prepare(
            "SELECT id, payload FROM hotspot_commands
             WHERE username = ? AND action = ?
               AND UPPER(status) IN ('PENDING','PROCESSING','EXECUTED')
             ORDER BY id DESC LIMIT 20"
        );
        $existingStmt->execute([$username, $action]);
        $canonical = static function (array $value): string {
            ksort($value);
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        };
        $wanted = $canonical($payload);
        foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $existing) {
            $stored = json_decode((string)($existing['payload'] ?? '{}'), true);
            if (is_array($stored)) {
                $stored['username'] = $username;
                if ($canonical($stored) === $wanted) {
                    return (int)$existing['id'];
                }
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO hotspot_commands (action, username, payload, status, created_at)
             VALUES (?, ?, ?, \'PENDING\', ?) RETURNING id'
        );
        $stmt->execute([$action, $username, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), date('c')]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    } catch (Throwable $e) {
        ara_log('hotspot_commands queue error (' . $action . '/' . $username . '): ' . $e->getMessage(), $config, 'error');
        return null;
    }
}

function hotspot_command_public(array $row, bool $includePayload = false): array
{
    $out = [
        'id' => (int)$row['id'],
        'action' => (string)$row['action'],
        'status' => strtoupper((string)$row['status']),
        'message' => (string)($row['message'] ?? $row['result'] ?? ''),
        'created_at' => $row['created_at'] ?? null,
        'processing_at' => $row['processing_at'] ?? null,
        'processed_at' => $row['processed_at'] ?? null,
    ];
    if ($includePayload) {
        $payload = json_decode((string)($row['payload'] ?? '{}'), true);
        if (!is_array($payload)) $payload = [];
        $payload['username'] = $payload['username'] ?? (string)$row['username'];
        $out['payload'] = $payload;
    }
    return $out;
}

function apply_hotspot_command_ack(PDO $pdo, string $action, string $username, array $payload): void
{
    switch ($action) {
        case 'create':
            $stmt = $pdo->prepare(
                'INSERT INTO hotspot_users
                    (username, password, profile, mac_address, comment, disabled,
                     limit_uptime, limit_bytes_total, bytes_in, bytes_out, uptime, server, last_sync)
                 VALUES (?, ?, ?, ?, ?, ?, ?::interval, ?, 0, 0, ?, ?, ?)
                 ON CONFLICT (username) DO UPDATE SET
                    password = EXCLUDED.password,
                    profile = EXCLUDED.profile,
                    comment = EXCLUDED.comment,
                    disabled = EXCLUDED.disabled,
                    limit_uptime = EXCLUDED.limit_uptime,
                    limit_bytes_total = EXCLUDED.limit_bytes_total,
                    last_sync = EXCLUDED.last_sync'
            );
            $stmt->execute([
                $username,
                (string)($payload['password'] ?? ''),
                (string)($payload['profile'] ?? 'default'),
                '',
                (string)($payload['comment'] ?? ''),
                'false',
                (($payload['limit_uptime'] ?? $payload['limit-uptime'] ?? '') !== '')
                    ? (string)($payload['limit_uptime'] ?? $payload['limit-uptime'])
                    : null,
                (($payload['limit_bytes_total'] ?? $payload['limit-bytes-total'] ?? null) === '' ? null :
                    (($payload['limit_bytes_total'] ?? $payload['limit-bytes-total'] ?? null) !== null
                        ? (int)($payload['limit_bytes_total'] ?? $payload['limit-bytes-total'])
                        : null)),
                '',
                '',
                date('c')
            ]);
            if (!empty($payload['expiry'])) {
                $pdo->prepare(
                    'INSERT INTO hotspot_expiry (user_id, expiry, updated_at)
                     VALUES (?, ?, ?)
                     ON CONFLICT (user_id) DO UPDATE SET expiry = EXCLUDED.expiry, updated_at = EXCLUDED.updated_at'
                )->execute([$username, (string)$payload['expiry'], date('c')]);
            }
            break;

        case 'update':
            $fields = [];
            $params = [];
            if (array_key_exists('profile', $payload) && trim((string)$payload['profile']) !== '') {
                $fields[] = 'profile = ?';
                $params[] = trim((string)$payload['profile']);
            }
            if (array_key_exists('comment', $payload)) {
                $fields[] = 'comment = ?';
                $params[] = (string)$payload['comment'];
            }
            if (array_key_exists('mac_address', $payload)) {
                $fields[] = 'mac_address = ?';
                $params[] = trim((string)$payload['mac_address']);
            }
            if (array_key_exists('password', $payload) && (string)$payload['password'] !== '') {
                $fields[] = 'password = ?';
                $params[] = (string)$payload['password'];
            }
            if (array_key_exists('limit_uptime', $payload) || array_key_exists('limit-uptime', $payload)) {
                $value = $payload['limit_uptime'] ?? $payload['limit-uptime'];
                $fields[] = 'limit_uptime = ?::interval';
                $params[] = ($value === '' || $value === null) ? null : (string)$value;
            }
            if (array_key_exists('limit_bytes_total', $payload) || array_key_exists('limit-bytes-total', $payload)) {
                $value = $payload['limit_bytes_total'] ?? $payload['limit-bytes-total'];
                $fields[] = 'limit_bytes_total = ?';
                $params[] = ($value === '' || $value === null) ? null : (int)$value;
            }
            if ($fields) {
                $params[] = $username;
                $pdo->prepare('UPDATE hotspot_users SET ' . implode(', ', $fields) . ', last_sync = ? WHERE username = ?')
                    ->execute(array_merge(array_slice($params, 0, -1), [date('c'), $username]));
            }
            if (array_key_exists('expiry', $payload) && trim((string)$payload['expiry']) !== '') {
                $pdo->prepare(
                    'INSERT INTO hotspot_expiry (user_id, expiry, updated_at)
                     VALUES (?, ?, ?)
                     ON CONFLICT (user_id) DO UPDATE SET expiry = EXCLUDED.expiry, updated_at = EXCLUDED.updated_at'
                )->execute([$username, trim((string)$payload['expiry']), date('c')]);
            }
            break;

        case 'enable':
        case 'disable':
            $pdo->prepare('UPDATE hotspot_users SET disabled = ?, last_sync = ? WHERE username = ?')
                ->execute([$action === 'disable' ? 'true' : 'false', date('c'), $username]);
            break;

        case 'delete':
            $pdo->prepare('DELETE FROM hotspot_users WHERE username = ?')->execute([$username]);
            try {
                $pdo->prepare('DELETE FROM hotspot_expiry WHERE user_id = ?')->execute([$username]);
            } catch (Throwable $e) {}
            break;

        // -------------------------------------------------------------
        // Extension "Profils + Déconnexion" (file asynchrone) : mêmes
        // règles que ci-dessus (n'appliquer le miroir Supabase qu'après
        // ACK positif du routeur, jamais en écriture optimiste avant).
        // -------------------------------------------------------------
        case 'profile-create':
            $pdo->prepare(
                'INSERT INTO hotspot_profiles (profile_name, shared_users, rate_limit, on_login, address_pool, last_sync)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT (profile_name) DO UPDATE SET
                    shared_users = EXCLUDED.shared_users,
                    rate_limit   = EXCLUDED.rate_limit,
                    on_login     = EXCLUDED.on_login,
                    address_pool = EXCLUDED.address_pool,
                    last_sync    = EXCLUDED.last_sync'
            )->execute([
                $username, // nom du profil (voir hotspot_profile_actions())
                max(1, (int)($payload['shared_users'] ?? 1)),
                (string)($payload['rate_limit'] ?? ''),
                (string)($payload['on_login'] ?? ''),
                (string)($payload['address_pool'] ?? ''),
                date('c'),
            ]);
            break;

        case 'profile-update':
            $fields = [];
            $params = [];
            if (array_key_exists('shared_users', $payload) && $payload['shared_users'] !== '') {
                $fields[] = 'shared_users = ?';
                $params[] = max(1, (int)$payload['shared_users']);
            }
            if (array_key_exists('rate_limit', $payload)) {
                $fields[] = 'rate_limit = ?';
                $params[] = (string)$payload['rate_limit'];
            }
            if (array_key_exists('on_login', $payload)) {
                $fields[] = 'on_login = ?';
                $params[] = (string)$payload['on_login'];
            }
            if (array_key_exists('address_pool', $payload)) {
                $fields[] = 'address_pool = ?';
                $params[] = (string)$payload['address_pool'];
            }
            if ($fields) {
                $params[] = $username; // nom du profil
                $pdo->prepare('UPDATE hotspot_profiles SET ' . implode(', ', $fields) . ', last_sync = ? WHERE profile_name = ?')
                    ->execute(array_merge(array_slice($params, 0, -1), [date('c'), $username]));
            }
            break;

        case 'profile-delete':
            $pdo->prepare('DELETE FROM hotspot_profiles WHERE profile_name = ?')->execute([$username]);
            break;

        case 'disconnect':
            // Rien à écrire dans le miroir Supabase : la session active ne
            // vit pas dans une table, elle est déduite du prochain snapshot
            // poussé par push-hotspot-status.rsc (30s plus tard, l'utilisateur
            // déconnecté n'y apparaîtra simplement plus).
            break;
    }
}

function claim_hotspot_commands(PDO $pdo, array $config, string $routerIdentity): array
{
    ensure_hotspot_commands_table($pdo);
    $limit = hotspot_command_limit($config);
    $staleCutoff = gmdate('c', time() - hotspot_command_stale_seconds($config));
    $candidates = $pdo->prepare(
        "SELECT id FROM hotspot_commands
         WHERE UPPER(status) = 'PENDING'
            OR (UPPER(status) = 'PROCESSING' AND processing_at IS NOT NULL AND processing_at < ?)
         ORDER BY created_at ASC, id ASC LIMIT $limit"
    );
    $candidates->execute([$staleCutoff]);
    $items = [];
    $claim = $pdo->prepare(
        "UPDATE hotspot_commands
         SET status = 'PROCESSING', processing_at = ?, router_identity = ?
         WHERE id = ? AND (UPPER(status) = 'PENDING'
            OR (UPPER(status) = 'PROCESSING' AND processing_at IS NOT NULL AND processing_at < ?))
         RETURNING id, action, username, payload, status, created_at, processing_at, processed_at, message, result"
    );
    foreach ($candidates->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $claim->execute([date('c'), $routerIdentity, $id, $staleCutoff]);
        $row = $claim->fetch(PDO::FETCH_ASSOC);
        if ($row) $items[] = hotspot_command_public($row, true);
    }
    return $items;
}

/**
 * Validation stricte du nom d'utilisateur avant toute requête SQL ou
 * commande destinée au routeur (H2 §26/§31).
 */
function hotspot_username_valid(string $username): bool
{
    return $username !== '' && strlen($username) <= 64 && preg_match('/^[A-Za-z0-9_.\-]+$/', $username) === 1;
}

/**
 * Validation d'un nom de profil MikroTik (distincte de
 * hotspot_username_valid : les profils RouterOS acceptent couramment des
 * espaces, ex. "1 Heure", ce qu'un username hotspot n'accepte pas).
 */
function hotspot_profile_name_valid(string $profileName): bool
{
    return $profileName !== '' && strlen($profileName) <= 64 && preg_match('/^[\p{L}0-9 _.\-]+$/u', $profileName) === 1;
}

/**
 * Vérifie qu'un profil existe dans hotspot_profiles. Si la table est
 * absente ou inaccessible, on ne bloque pas la création/modification
 * (aucune preuve que cette table soit une contrainte obligatoire) mais
 * on trace l'anomalie — voir rapport final §Limitations.
 */
function hotspot_profile_exists(PDO $pdo, string $profile, array $config): ?bool
{
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM hotspot_profiles WHERE profile_name = ? LIMIT 1');
        $stmt->execute([$profile]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        ara_log('hotspot_profile_exists: table hotspot_profiles inaccessible (' . $e->getMessage() . ')', $config, 'warning');
        return null; // inconnu : ne bloque pas
    }
}

/**
 * Retire les champs sensibles/techniques non pertinents pour l'admin
 * avant de retourner un utilisateur (le mot de passe n'est JAMAIS
 * renvoyé par une route de lecture — H2 §27).
 */
function hotspot_user_public_row(array $row): array
{
    $disabled = (($row['disabled'] ?? 'false') === 'true') || $row['disabled'] === true;
    return [
        'username'    => (string)($row['username'] ?? ''),
        'profile'     => (string)($row['profile'] ?? ''),
        'mac_address' => (string)($row['mac_address'] ?? ''),
        'comment'     => (string)($row['comment'] ?? ''),
        'disabled'    => $disabled,
        'bytes_in'    => (int)($row['bytes_in'] ?? 0),
        'bytes_out'   => (int)($row['bytes_out'] ?? 0),
        'uptime'      => (string)($row['uptime'] ?? ''),
        'server'      => (string)($row['server'] ?? ''),
        'expiry'      => isset($row['expiry']) && $row['expiry'] !== null ? (string)$row['expiry'] : null,
    ];
}

/**
 * Normalise une ligne hotspot_profiles avant retour API (H1 — contrat
 * lecture Profils). Ne renvoie que les colonnes réellement présentes
 * dans le schéma observé (§12 du brief) : profile_name, shared_users,
 * rate_limit, on_login, address_pool — aucun champ SQL fictif.
 */
function hotspot_profile_public_row(array $row): array
{
    return [
        'profile_name' => (string)($row['profile_name'] ?? ''),
        'shared_users' => (int)($row['shared_users'] ?? 1),
        'rate_limit'   => (string)($row['rate_limit'] ?? ''),
        'on_login'     => (string)($row['on_login'] ?? ''),
        'address_pool' => (string)($row['address_pool'] ?? ''),
    ];
}

/**
 * Détermine, de façon fiable, l'ensemble des usernames actuellement en
 * ligne à partir du dernier snapshot poussé par le routeur (même source
 * que la route "status"). Si aucun snapshot récent n'est disponible,
 * retourne null : l'appelant doit alors afficher UNKNOWN et jamais
 * OFFLINE par défaut (H2 §8/§11).
 */
function hotspot_online_usernames(array $config): ?array
{
    $snapshot = null;
    try {
        $pdo = ara_db_supabase();
        ensure_hotspot_snapshot_columns($pdo, true);
        $stmt = $pdo->query('SELECT * FROM hotspot_snapshots ORDER BY id DESC LIMIT 1');
        $snapshot = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        try {
            $pdoLocal = ara_db($config);
            $pdoLocal->exec("CREATE TABLE IF NOT EXISTS hotspot_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                snapshot_date TEXT NOT NULL,
                snapshot_time TEXT NOT NULL,
                active_count INTEGER NOT NULL,
                users_blob TEXT,
                received_at TEXT NOT NULL
            )");
            ensure_hotspot_snapshot_columns($pdoLocal, false);
            $stmt = $pdoLocal->query('SELECT * FROM hotspot_snapshots ORDER BY id DESC LIMIT 1');
            $snapshot = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e2) {
            return null;
        }
    }

    if (!$snapshot) {
        return null;
    }

    $routerStatus = compute_router_status(
        $snapshot['snapshot_date'] ?? null,
        $snapshot['snapshot_time'] ?? null,
        $snapshot['received_at'] ?? null
    );
    if ($routerStatus['status'] !== 'ONLINE') {
        return null;
    }

    $usernames = [];
    foreach (extract_snapshot_users($snapshot) as $u) {
        if (!empty($u['user'])) {
            $usernames[] = $u['user'];
        }
    }
    return $usernames;
}


function ensure_hotspot_users_table(PDO $pdo): void
{
    // Supabase/PostgreSQL schema is owned by database/migrations.
    // Runtime PHP must not create or mutate the Phase 2 schema.
}

function normalize_hotspot_sync_user(array $u): array
{
    $limitBytes = $u['limit-bytes-total'] ?? $u['limit_bytes_total'] ?? null;
    if ($limitBytes === '' || $limitBytes === null) {
        $limitBytes = null;
    } else {
        $limitBytes = (int)$limitBytes; // preserves a valid 0
    }

    $limitUptime = (string)($u['limit-uptime'] ?? $u['limit_uptime'] ?? '');
    return [
        'username' => trim((string)($u['name'] ?? $u['username'] ?? '')),
        'password' => (string)($u['password'] ?? ''),
        'profile' => (string)($u['profile'] ?? ''),
        'mac_address' => (string)($u['mac-address'] ?? $u['mac_address'] ?? ''),
        'comment' => (string)($u['comment'] ?? ''),
        'disabled' => (($u['disabled'] ?? 'false') === 'true' || ($u['disabled'] ?? false) === true) ? 'true' : 'false',
        'limit_uptime' => $limitUptime !== '' ? $limitUptime : null,
        'limit_bytes_total' => $limitBytes,
        'bytes_in' => (int)($u['bytes-in'] ?? $u['bytes_in'] ?? 0),
        'bytes_out' => (int)($u['bytes-out'] ?? $u['bytes_out'] ?? 0),
        'uptime' => (string)($u['uptime'] ?? ''),
        'server' => (string)($u['server'] ?? ''),
    ];
}

function upsert_hotspot_sync_user(PDO $pdo, array $u): bool
{
    if ($u['username'] === '') {
        return false;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO hotspot_users
            (username, password, profile, mac_address, comment, disabled,
             limit_uptime, limit_bytes_total, bytes_in, bytes_out, uptime, server, last_sync)
         VALUES (?, ?, ?, ?, ?, ?, ?::interval, ?, ?, ?, ?, ?, ?)
         ON CONFLICT (username) DO UPDATE SET
             password = EXCLUDED.password,
             profile = EXCLUDED.profile,
             mac_address = EXCLUDED.mac_address,
             comment = EXCLUDED.comment,
             disabled = EXCLUDED.disabled,
             limit_uptime = EXCLUDED.limit_uptime,
             limit_bytes_total = EXCLUDED.limit_bytes_total,
             bytes_in = EXCLUDED.bytes_in,
             bytes_out = EXCLUDED.bytes_out,
             uptime = EXCLUDED.uptime,
             server = EXCLUDED.server,
             last_sync = EXCLUDED.last_sync'
    );
    $stmt->execute([
        $u['username'], $u['password'], $u['profile'], $u['mac_address'], $u['comment'],
        $u['disabled'], $u['limit_uptime'], $u['limit_bytes_total'],
        $u['bytes_in'], $u['bytes_out'], $u['uptime'], $u['server'], date('c')
    ]);
    return true;
}

function require_sync_key(array $config): void
{
    $key = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($key === '') {
        $key = request_bearer_token();
    }
    $expected = trim((string)($config['hotspot']['sync_key'] ?? ''));
    if ($expected === '' || $key === '' || !hash_equals($expected, $key)) {
        json_api_error('UNAUTHORIZED', 'Non autorisé.', 401);
    }
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

// ---------------------------------------------------------------------
// FONCTIONS — STATUT V2.1 (PHASE BACKEND)
// ---------------------------------------------------------------------

/**
 * Seuil (en secondes) au-delà duquel un snapshot est considéré comme
 * périmé (OFFLINE). Valeur identifiée dans admin/status.php — conservée
 * telle quelle pour cette phase (voir §11 du brief).
 */
const HOTSPOT_SNAPSHOT_ONLINE_THRESHOLD = 360;

/**
 * Lit le corps de la requête pour push-status et exige un JSON valide.
 * Contrairement à get_request_payload() (utilisée par les autres routes),
 * cette fonction ne retombe pas silencieusement sur $_POST : un corps
 * JSON invalide doit produire une erreur explicite (voir TEST 4 du brief).
 * Un corps vide reste toléré (rétrocompatibilité avec d'anciens appels).
 */
function get_push_status_payload(): array
{
    $body = file_get_contents('php://input');
    if ($body === false || trim($body) === '') {
        return $_POST;
    }
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        json_error('Payload JSON invalide.', 400);
    }
    return $data;
}

/**
 * Validation minimale et robuste du payload push-status (voir §9 du brief).
 * Retourne un message d'erreur (string) si le payload est invalide, ou
 * null si tout est correct. Les champs inconnus sont ignorés.
 */
function validate_push_status_payload(array $payload): ?string
{
    if (isset($payload['active']) && $payload['active'] !== '' && !is_numeric($payload['active'])) {
        return "Le champ 'active' doit être numérique.";
    }
    if (isset($payload['users']) && !is_string($payload['users']) && !is_array($payload['users'])) {
        return "Le champ 'users' doit être une chaîne ou un tableau.";
    }
    if (isset($payload['router']) && !is_array($payload['router'])) {
        return "Le champ 'router' doit être un objet.";
    }
    if (isset($payload['network']) && !is_array($payload['network'])) {
        return "Le champ 'network' doit être un objet.";
    }
    return null;
}

/**
 * Normalise le champ "router" du payload (identity/uptime/version/cpu/
 * memory_total/memory_free). Une valeur absente reste NULL — jamais 0
 * (voir §7 du brief : "CPU absent ≠ CPU 0").
 */
function normalize_router_payload($router): array
{
    if (!is_array($router)) {
        $router = [];
    }
    return [
        'identity'     => isset($router['identity']) && $router['identity'] !== '' ? (string)$router['identity'] : null,
        'uptime'       => isset($router['uptime']) && $router['uptime'] !== '' ? (string)$router['uptime'] : null,
        'version'      => isset($router['version']) && $router['version'] !== '' ? (string)$router['version'] : null,
        'cpu'          => isset($router['cpu']) && is_numeric($router['cpu']) ? (float)$router['cpu'] : null,
        'memory_total' => isset($router['memory_total']) && is_numeric($router['memory_total']) ? (int)$router['memory_total'] : null,
        'memory_free'  => isset($router['memory_free']) && is_numeric($router['memory_free']) ? (int)$router['memory_free'] : null,
    ];
}

/**
 * Normalise le champ "users" du payload push-status, en acceptant :
 * - l'ancien format (chaîne "user,mac,ip||user,mac,ip||...") ;
 * - le nouveau format (tableau d'objets utilisateurs).
 *
 * Retourne toujours :
 * - 'blob'       => représentation legacy "user,mac,ip||..." (jamais NULL,
 *                   afin de rester compatible avec un éventuel schéma
 *                   Supabase où users_blob serait NOT NULL — voir §5) ;
 * - 'structured' => tableau structuré (utilisé pour users_json).
 */
function normalize_hotspot_users($usersRaw): array
{
    // Ancien format : chaîne "user,mac,ip||user,mac,ip||..."
    if (is_string($usersRaw)) {
        $structured = [];
        if (trim($usersRaw) !== '') {
            foreach (explode('||', $usersRaw) as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '') continue;
                $parts = explode(',', $chunk, 3);
                if (count($parts) === 3) {
                    $structured[] = [
                        'user' => $parts[0],
                        'mac'  => $parts[1],
                        'ip'   => $parts[2],
                    ];
                }
            }
        }
        return ['blob' => $usersRaw, 'structured' => $structured];
    }

    // Nouveau format : tableau d'objets utilisateurs
    if (is_array($usersRaw)) {
        $structured = [];
        $legacyParts = [];
        foreach ($usersRaw as $u) {
            if (!is_array($u)) continue;
            $user = isset($u['user']) ? (string)$u['user'] : '';
            $mac  = isset($u['mac'])  ? (string)$u['mac']  : '';
            $ip   = isset($u['ip'])   ? (string)$u['ip']   : '';
            $entry = ['user' => $user, 'mac' => $mac, 'ip' => $ip];
            // Champs optionnels — tolérés absents (voir §8 du brief)
            foreach (['profile', 'uptime', 'server'] as $optField) {
                if (isset($u[$optField]) && $u[$optField] !== '') {
                    $entry[$optField] = (string)$u[$optField];
                }
            }
            foreach (['bytes_in', 'bytes_out'] as $numField) {
                if (isset($u[$numField]) && is_numeric($u[$numField])) {
                    $entry[$numField] = (int)$u[$numField];
                }
            }
            $structured[] = $entry;
            $legacyParts[] = $user . ',' . $mac . ',' . $ip;
        }
        // Représentation legacy raisonnable générée à partir du nouveau
        // format, pour rester compatible avec un éventuel users_blob NOT NULL.
        return ['blob' => implode('||', $legacyParts), 'structured' => $structured];
    }

    return ['blob' => '', 'structured' => []];
}

/**
 * S'assure que les nouvelles colonnes "Statut V2.1" existent sur la table
 * hotspot_snapshots, quel que soit le moteur (SQLite local ou Supabase/
 * PostgreSQL). Le schéma Supabase n'est pas versionné dans ce dépôt (aucun
 * fichier de migration trouvé) : cette fonction inspecte donc le schéma
 * réel avant d'ajouter les colonnes manquantes, plutôt que de le supposer.
 * N'échoue jamais silencieusement sur une base déjà à jour (aucune ALTER
 * TABLE si les colonnes existent déjà).
 */
function ensure_hotspot_snapshot_columns(PDO $pdo, bool $isPostgres): void
{
    if ($isPostgres) {
        // Phase 2 owns the Supabase schema. The API must never ALTER it at
        // runtime. Missing columns are a deployment/migration error.
        $required = [
            'router_identity', 'router_uptime', 'router_version', 'cpu_load',
            'memory_total', 'memory_free', 'users_json', 'network_json',
        ];
        $stmt = $pdo->query(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'hotspot_snapshots'"
        );
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_values(array_diff($required, $existing));
        if ($missing) {
            throw new RuntimeException('Schéma Supabase incomplet: hotspot_snapshots manque des colonnes requises.');
        }
        return;
    }

    // Local SQLite is only a legacy/cache compatibility layer. Keep its
    // lightweight column upgrade path without touching Supabase.
    $columns = [
        'router_identity' => 'TEXT',
        'router_uptime'   => 'TEXT',
        'router_version'  => 'TEXT',
        'cpu_load'        => 'REAL',
        'memory_total'    => 'INTEGER',
        'memory_free'     => 'INTEGER',
        'users_json'      => 'TEXT',
        'network_json'    => 'TEXT',
    ];
    $stmt = $pdo->query('PRAGMA table_info(hotspot_snapshots)');
    $existing = array_map(static fn($r) => $r['name'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    foreach ($columns as $col => $type) {
        if (!in_array($col, $existing, true)) {
            $pdo->exec("ALTER TABLE hotspot_snapshots ADD COLUMN $col $type");
        }
    }
}


/**
 * Calcule le statut ONLINE / OFFLINE / UNKNOWN du routeur (voir §11).
 * Priorité à received_at (fiable, ISO-8601) ; à défaut, retombe sur
 * snapshot_date + snapshot_time (mécanisme déjà utilisé par
 * admin/status.php), afin de conserver une seule logique de référence.
 */
function compute_router_status(?string $snapshotDate, ?string $snapshotTime, ?string $receivedAt): array
{
    $last = null;

    if ($receivedAt) {
        try {
            $last = new DateTimeImmutable($receivedAt);
        } catch (Throwable $e) {
            $last = null;
        }
    }

    if (!$last && $snapshotDate && $snapshotTime) {
        $parsed = DateTime::createFromFormat('Y-m-d H:i:s', $snapshotDate . ' ' . $snapshotTime, new DateTimeZone('UTC'));
        if ($parsed !== false) {
            $last = DateTimeImmutable::createFromMutable($parsed);
        }
    }

    if (!$last) {
        return ['status' => 'UNKNOWN', 'age_seconds' => null];
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $age = $now->getTimestamp() - $last->getTimestamp();

    return [
        'status'      => $age < HOTSPOT_SNAPSHOT_ONLINE_THRESHOLD ? 'ONLINE' : 'OFFLINE',
        'age_seconds' => $age,
    ];
}

/**
 * Reconstruit la liste structurée des utilisateurs à partir d'un snapshot
 * (users_json en priorité, sinon fallback sur l'ancien users_blob).
 */
function extract_snapshot_users(array $snapshot): array
{
    if (!empty($snapshot['users_json'])) {
        $decoded = is_string($snapshot['users_json']) ? json_decode($snapshot['users_json'], true) : $snapshot['users_json'];
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $users = [];
    if (!empty($snapshot['users_blob'])) {
        foreach (explode('||', $snapshot['users_blob']) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') continue;
            $parts = explode(',', $chunk, 3);
            if (count($parts) === 3) {
                $users[] = ['user' => $parts[0], 'mac' => $parts[1], 'ip' => $parts[2]];
            }
        }
    }
    return $users;
}

// ---------------------------------------------------------------------
// NOUVELLES FONCTIONS POUR LE DASHBOARD V2.1
// ---------------------------------------------------------------------

function get_period_dates(string $period, string $customStart = '', string $customEnd = ''): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    switch ($period) {
        case 'today':
            $start = $now->setTime(0, 0, 0);
            $end   = $now;
            $label = "Aujourd'hui";
            break;
        case 'yesterday':
            $start = $now->modify('-1 day')->setTime(0, 0, 0);
            $end   = $now->modify('-1 day')->setTime(23, 59, 59);
            $label = 'Hier';
            break;
        case '7days':
            $start = $now->modify('-6 days')->setTime(0, 0, 0);
            $end   = $now;
            $label = '7 derniers jours';
            break;
        case 'thismonth':
            $start = $now->modify('first day of this month')->setTime(0, 0, 0);
            $end   = $now;
            $label = 'Ce mois';
            break;
        case 'lastmonth':
            $start = $now->modify('first day of last month')->setTime(0, 0, 0);
            $end   = $now->modify('last day of last month')->setTime(23, 59, 59);
            $label = 'Mois précédent';
            break;
        case 'custom':
            if ($customStart && $customEnd) {
                $start = DateTimeImmutable::createFromFormat('Y-m-d', $customStart, new DateTimeZone('UTC'));
                $end   = DateTimeImmutable::createFromFormat('Y-m-d', $customEnd, new DateTimeZone('UTC'));
                if (!$start || !$end) {
                    json_error('Dates personnalisées invalides.');
                }
                $start = $start->setTime(0, 0, 0);
                $end   = $end->setTime(23, 59, 59);
                $label = "Du " . $start->format('d/m/Y') . " au " . $end->format('d/m/Y');
            } else {
                json_error('Dates personnalisées manquantes.');
            }
            break;
        default:
            json_error('Période invalide.', 400);
    }
    return [
        'start'      => $start->format('Y-m-d'),
        'end'        => $end->format('Y-m-d'),
        'label'      => $label,
        'start_obj'  => $start,
        'end_obj'    => $end
    ];
}

function get_cached_network_status(array $config): array
{
    // The API no longer opens a direct RouterOS connection. Network state is
    // supplied by the RouterOS push/snapshot pipeline.
    return [
        'internet' => 'UNKNOWN',
        'mikrotik' => 'UNKNOWN',
        'poe_switch' => 'UNKNOWN',
        'ap_01' => 'UNKNOWN',
        'ap_02' => 'UNKNOWN',
    ];
}

function gather_alerts(array $config, array $networkStatus): array
{
    $alerts = [];
    $now = date('H:i');

    if ($networkStatus['mikrotik'] === 'OFFLINE') {
        $alerts[] = [
            'level'       => 'CRITIQUE',
            'title'       => 'Routeur injoignable',
            'description' => 'Le routeur MikroTik ne répond pas.',
            'time'        => $now,
            'action'      => 'network'
        ];
    } else {
        foreach ($networkStatus as $component => $state) {
            if ($state === 'OFFLINE') {
                $alerts[] = [
                    'level'       => 'CRITIQUE',
                    'title'       => "$component hors ligne",
                    'description' => "Le composant $component ne répond pas.",
                    'time'        => $now,
                    'action'      => 'network'
                ];
            }
        }
    }

    // On pourra ajouter plus tard les alertes d'abonnement, de logs...
    return $alerts;
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
        require_post_method();
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
        require_post_method();
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
        require_post_method();
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
        require_post_method();
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

    case 'hotspot-commands-pending':
        require_get_method();
        require_sync_key($config);
        try {
            $routerIdentity = trim((string)($_GET['router_identity'] ?? $_SERVER['HTTP_X_ROUTER_IDENTITY'] ?? 'MikroTik'));
            if ($routerIdentity === '') $routerIdentity = 'MikroTik';
            $pdo = ara_db_supabase();
            $items = claim_hotspot_commands($pdo, $config, $routerIdentity);
            json_api_success(['items' => array_map(static function (array $item): array {
                return [
                    'id' => $item['id'],
                    'action' => $item['action'],
                    'payload' => $item['payload'],
                ];
            }, $items)]);
        } catch (Throwable $e) {
            ara_log('hotspot-commands-pending error: ' . $e->getMessage(), $config, 'error');
            json_api_error('COMMAND_PENDING_FAILED', 'Impossible de récupérer les commandes.', 500);
        }
        break;

    case 'hotspot-command-ack':
        require_post_method();
        require_sync_key($config);
        $payload = get_request_payload();
        $commandId = (int)($payload['command_id'] ?? 0);
        if ($commandId <= 0) {
            json_api_error('INVALID_REQUEST', 'command_id requis.', 400);
        }
        $ok = filter_var($payload['success'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($ok === null) {
            json_api_error('INVALID_REQUEST', 'success booléen requis.', 400);
        }
        $message = trim((string)($payload['message'] ?? ($ok ? 'executed' : 'failed')));
        $message = substr(str_replace(["\r", "\n"], ' ', $message), 0, 180);

        try {
            $pdo = ara_db_supabase();
            ensure_hotspot_commands_table($pdo);
            $pdo->beginTransaction();

            $lookup = $pdo->prepare(
                'SELECT id, action, username, payload, status, created_at, processing_at, processed_at, message, result
                 FROM hotspot_commands WHERE id = ? FOR UPDATE'
            );
            $lookup->execute([$commandId]);
            $command = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!$command) {
                $pdo->rollBack();
                json_api_error('COMMAND_NOT_FOUND', 'Commande introuvable.', 404);
            }
            if (strtoupper((string)$command['status']) !== 'PROCESSING') {
                $pdo->rollBack();
                json_api_error('COMMAND_STATE_CONFLICT', 'La commande n’est pas en cours de traitement.', 409);
            }

            if ($ok) {
                $commandPayload = json_decode((string)($command['payload'] ?? '{}'), true);
                if (!is_array($commandPayload)) $commandPayload = [];
                $commandPayload['username'] = (string)$command['username'];
                apply_hotspot_command_ack(
                    $pdo,
                    strtolower((string)$command['action']),
                    (string)$command['username'],
                    $commandPayload
                );
            }

            $status = $ok ? 'EXECUTED' : 'FAILED';
            $stmt = $pdo->prepare(
                "UPDATE hotspot_commands
                 SET status = ?, processed_at = ?, result = ?, message = ?
                 WHERE id = ? AND UPPER(status) = 'PROCESSING'
                 RETURNING id, action, status, created_at, processing_at, processed_at, message, result"
            );
            $stmt->execute([$status, date('c'), $message, $message, $commandId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Impossible de finaliser la commande.');
            }
            $pdo->commit();

            json_api_success(hotspot_command_public($row));
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            ara_log('hotspot-command-ack error command_id=' . $commandId . ': ' . $e->getMessage(), $config, 'error');
            json_api_error('COMMAND_ACK_FAILED', 'Impossible d’enregistrer l’ACK.', 500);
        }
        break;

    case 'hotspot-command-status':
        require_get_method();
        require_admin_token($config);
        $commandId = (int)($_GET['id'] ?? 0);
        if ($commandId <= 0) json_api_error('INVALID_REQUEST', 'id requis.', 400);
        try {
            $pdo = ara_db_supabase();
            ensure_hotspot_commands_table($pdo);
            $stmt = $pdo->prepare('SELECT id, action, status, created_at, processing_at, processed_at, message, result FROM hotspot_commands WHERE id = ?');
            $stmt->execute([$commandId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_api_error('COMMAND_NOT_FOUND', 'Commande introuvable.', 404);
            json_api_success(hotspot_command_public($row));
        } catch (Throwable $e) {
            ara_log('hotspot-command-status error: ' . $e->getMessage(), $config, 'error');
            json_api_error('COMMAND_STATUS_FAILED', 'Impossible de lire le statut de la commande.', 500);
        }
        break;

    case 'set-expiry':
        require_post_method();
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

            // MikroTik is not contacted from the web API. Expiry is read
            // from the Supabase mirror populated by the RouterOS sync.
            // 3) Rien trouvé
            json_error('Expiration non disponible.', 404);

        } catch (Throwable $e) {
            ara_log('api.php Expiry error: ' . $e->getMessage(), $config, 'error');
            $message = 'Erreur lors de la récupération de l\'expiration.';
            if (!empty($config['debug'])) $message .= ' [debug] ' . $e->getMessage();
            json_error($message, 500);
        }
        break;

    case 'push-logs':
        require_post_method();
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

    case '':
        require_admin_token($config);
        $startDate = (string)($_GET['start'] ?? date('Y-m-01'));
        $endDate   = (string)($_GET['end']   ?? date('Y-m-d'));
        validate_date_range($startDate, $endDate);

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
            $msg = 'Erreur get-sales : ' . $e->getMessage();
            if (!empty($config['debug'])) $msg .= ' [debug] ' . $e->getMessage();
            json_error($msg, 500);
        }
        break;

    case 'log-sale':
        require_post_method();
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

    case 'get-sales':
        require_admin_token($config);
        $startDate = (string)($_GET['start'] ?? date('Y-m-01'));
        $endDate   = (string)($_GET['end']   ?? date('Y-m-d'));
        validate_date_range($startDate, $endDate);

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
            $msg = 'Erreur lors de la récupération des ventes.';
            if (!empty($config['debug'])) $msg .= ' [debug] ' . $e->getMessage();
            json_error($msg, 500);
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
            $msg = 'Erreur get-sales-daily: ' . $e->getMessage();
            if (!empty($config['debug'])) $msg .= ' [debug] ' . $e->getMessage();
            json_error($msg, 500);
        }
        break;

    case 'push-status':
        require_post_method();
        require_sync_key($config);
        $payload = get_push_status_payload();

        $validationError = validate_push_status_payload($payload);
        if ($validationError !== null) {
            json_error($validationError, 400);
        }

        $activeCount = isset($payload['active']) && $payload['active'] !== '' ? (int)$payload['active'] : 0;
        $usersNormalized = normalize_hotspot_users($payload['users'] ?? '');
        $usersBlob = $usersNormalized['blob'];
        $usersJson = json_encode($usersNormalized['structured'], JSON_UNESCAPED_UNICODE);

        $routerData = normalize_router_payload($payload['router'] ?? null);

        $networkJson = null;
        if (isset($payload['network']) && is_array($payload['network'])) {
            $networkJson = json_encode($payload['network'], JSON_UNESCAPED_UNICODE);
        }

        $now = date('c');
        $date = date('Y-m-d');
        $time = date('H:i:s');

        $insertSQL = "INSERT INTO hotspot_snapshots
            (snapshot_date, snapshot_time, active_count, users_blob, received_at,
             router_identity, router_uptime, router_version, cpu_load, memory_total, memory_free,
             users_json, network_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insertArgs = [
            $date, $time, $activeCount, $usersBlob, $now,
            $routerData['identity'], $routerData['uptime'], $routerData['version'],
            $routerData['cpu'], $routerData['memory_total'], $routerData['memory_free'],
            $usersJson, $networkJson,
        ];

        $localOk = false;
        $supabaseOk = false;
        $lastError = null;

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
            ensure_hotspot_snapshot_columns($pdo, false);
            $stmt = $pdo->prepare($insertSQL);
            $stmt->execute($insertArgs);
            $localOk = true;
        } catch (Throwable $e) {
            $lastError = $e;
            ara_log('api.php Push-status (local) error: ' . $e->getMessage(), $config, 'error');
        }

        try {
            // 2) Supabase (persistance / source de vérité)
            $pdoSup = ara_db_supabase();
            ensure_hotspot_snapshot_columns($pdoSup, true);
            $stmt2 = $pdoSup->prepare($insertSQL);
            $stmt2->execute($insertArgs);
            $supabaseOk = true;
        } catch (Throwable $e) {
            $lastError = $e;
            ara_log('api.php Push-status (supabase) error: ' . $e->getMessage(), $config, 'error');
        }

        if (!$localOk && !$supabaseOk) {
            $msg = 'Erreur lors de l\'enregistrement du statut.';
            if (!empty($config['debug']) && $lastError) $msg .= ' [debug] ' . $lastError->getMessage();
            json_error($msg, 500);
        }

        json_response(['ok' => true, 'success' => true, 'active' => $activeCount]);
        break;

    case 'status':
        require_admin_token($config);

        $snapshot = null;
        $source = null;

        try {
            $pdoSup = ara_db_supabase();
            ensure_hotspot_snapshot_columns($pdoSup, true);
            $stmt = $pdoSup->query('SELECT * FROM hotspot_snapshots ORDER BY id DESC LIMIT 1');
            $snapshot = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $source = 'supabase';
        } catch (Throwable $e) {
            ara_log('api.php status (supabase) error: ' . $e->getMessage(), $config, 'error');
        }

        if ($snapshot === null && $source === null) {
            // Supabase injoignable ou en erreur : repli sur la copie locale.
            try {
                $pdo = ara_db($config);
                $pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_snapshots (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    snapshot_date TEXT NOT NULL,
                    snapshot_time TEXT NOT NULL,
                    active_count INTEGER NOT NULL,
                    users_blob TEXT,
                    received_at TEXT NOT NULL
                )");
                ensure_hotspot_snapshot_columns($pdo, false);
                restore_from_turso_if_empty($pdo, $config, 'hotspot_snapshots',
                    'SELECT snapshot_date, snapshot_time, active_count, users_blob, received_at FROM hotspot_snapshots ORDER BY id DESC LIMIT 1',
                    [],
                    'INSERT INTO hotspot_snapshots (snapshot_date, snapshot_time, active_count, users_blob, received_at) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt = $pdo->query('SELECT * FROM hotspot_snapshots ORDER BY id DESC LIMIT 1');
                $snapshot = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                $source = 'local_sqlite';
            } catch (Throwable $e2) {
                ara_log('api.php status (local) error: ' . $e2->getMessage(), $config, 'error');
                json_error('Erreur lors de la récupération du statut.', 500);
            }
        }

        $routerStatus = compute_router_status(
            $snapshot['snapshot_date'] ?? null,
            $snapshot['snapshot_time'] ?? null,
            $snapshot['received_at'] ?? null
        );

        $users = $snapshot ? extract_snapshot_users($snapshot) : [];

        json_response([
            'ok' => true,
            'router' => [
                'status'        => $routerStatus['status'],
                'last_snapshot' => $snapshot ? trim(($snapshot['snapshot_date'] ?? '') . ' ' . ($snapshot['snapshot_time'] ?? '')) : null,
                'age_seconds'   => $routerStatus['age_seconds'],
                'identity'      => $snapshot['router_identity'] ?? null,
                'uptime'        => $snapshot['router_uptime'] ?? null,
                'version'       => $snapshot['router_version'] ?? null,
                'cpu'           => isset($snapshot['cpu_load']) && $snapshot['cpu_load'] !== null ? (float)$snapshot['cpu_load'] : null,
                'memory_total'  => isset($snapshot['memory_total']) && $snapshot['memory_total'] !== null ? (int)$snapshot['memory_total'] : null,
                'memory_free'   => isset($snapshot['memory_free']) && $snapshot['memory_free'] !== null ? (int)$snapshot['memory_free'] : null,
            ],
            'sessions' => [
                'active_count' => $snapshot ? (int)$snapshot['active_count'] : 0,
                'users'        => $users,
            ],
            'network' => [
                // Ne jamais déduire internet/poe_switch/ap_* du simple fait
                // qu'un snapshot a été reçu (voir §12 et §13 du brief).
                'internet'   => 'UNKNOWN',
                'mikrotik'   => $routerStatus['status'],
                'poe_switch' => 'UNKNOWN',
                'ap_01'      => 'UNKNOWN',
                'ap_02'      => 'UNKNOWN',
            ],
            'meta' => [
                'source'           => 'push_snapshot',
                'db_source'        => $source,
                'refresh_interval' => 30,
            ],
        ]);
        break;

    case 'sync-users':
        require_post_method();
        require_sync_key($config);
        $payload = get_request_payload();
        $users = $payload['users'] ?? [];
        if (!is_array($users) || empty($users)) {
            json_error('Aucune donnée utilisateur.');
        }

        try {
            $pdo = ara_db_supabase();
            ensure_hotspot_users_table($pdo);

            $processed = 0;
            $skipped = 0;
            foreach ($users as $rawUser) {
                if (!is_array($rawUser)) {
                    $skipped++;
                    continue;
                }
                $user = normalize_hotspot_sync_user($rawUser);
                if ($user['username'] === '') {
                    $skipped++;
                    continue;
                }
                if (upsert_hotspot_sync_user($pdo, $user)) {
                    $processed++;
                } else {
                    $skipped++;
                }
            }

            json_response([
                'success' => true,
                'received' => count($users),
                'processed' => $processed,
                'skipped' => $skipped,
            ]);
        } catch (Throwable $e) {
            ara_log('sync-users error: ' . $e->getMessage(), $config, 'error');
            json_error('Erreur sync-users.', 500);
        }
        break;

        case 'sync-profiles':
        require_post_method();
        require_sync_key($config);
        $payload = get_request_payload();
        $profiles = $payload['profiles'] ?? [];
        if (!is_array($profiles) || empty($profiles)) {
            json_error('Aucune donnée profil.');
        }
        try {
            $pdo = ara_db_supabase();
            $stmt = $pdo->prepare(
                "INSERT INTO hotspot_profiles
                    (profile_name, shared_users, rate_limit, on_login, address_pool, last_sync)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT (profile_name) DO UPDATE SET
                    shared_users = EXCLUDED.shared_users,
                    rate_limit = EXCLUDED.rate_limit,
                    on_login = EXCLUDED.on_login,
                    address_pool = EXCLUDED.address_pool,
                    last_sync = EXCLUDED.last_sync"
            );
            $processed = 0;
            $skipped = 0;
            foreach ($profiles as $p) {
                if (!is_array($p)) {
                    $skipped++;
                    continue;
                }
                $name = trim((string)($p['name'] ?? ''));
                if ($name === '') {
                    $skipped++;
                    continue;
                }
                $stmt->execute([
                    $name,
                    max(1, (int)($p['shared-users'] ?? 1)),
                    (string)($p['rate-limit'] ?? ''),
                    (string)($p['on-login'] ?? ''),
                    (string)($p['address-pool'] ?? ''),
                    date('c'),
                ]);
                $processed++;
            }
            json_response([
                'success' => true,
                'received' => count($profiles),
                'processed' => $processed,
                'skipped' => $skipped,
            ]);
        } catch (Throwable $e) {
            ara_log('sync-profiles error: ' . $e->getMessage(), $config, 'error');
            json_error('Erreur sync-profiles.', 500);
        }
        break;

    // -----------------------------------------------------------------
    // NOUVELLE ROUTE : dashboard (V2.1)
    // -----------------------------------------------------------------
    case 'dashboard':
        require_admin_token($config);

        $period      = $_GET['period'] ?? 'thismonth';
        $customStart = $_GET['start']  ?? '';
        $customEnd   = $_GET['end']    ?? '';
        $periodData  = get_period_dates($period, $customStart, $customEnd);
        $start       = $periodData['start'];
        $end         = $periodData['end'];

        // Période précédente (pour comparaison)
        $startObj = $periodData['start_obj'];
        $endObj   = $periodData['end_obj'];
        $interval = $startObj->diff($endObj);
        $days     = $interval->days + 1;
        $prevEnd  = $startObj->modify('-1 day');
        $prevStart = $prevEnd->modify("-{$days} days")->modify('+1 day');
        $prevStartStr = $prevStart->format('Y-m-d');
        $prevEndStr   = $prevEnd->format('Y-m-d');

        try {
            $pdo = ara_db_supabase();

            // CA courant et précédent
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sales_log WHERE sale_date BETWEEN ? AND ?");
            $stmt->execute([$start, $end]);
            $currentRevenue = (int)$stmt->fetchColumn();

            $stmt->execute([$prevStartStr, $prevEndStr]);
            $prevRevenue = (int)$stmt->fetchColumn();

            // Tickets
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_log WHERE sale_date BETWEEN ? AND ?");
            $stmt->execute([$start, $end]);
            $currentTickets = (int)$stmt->fetchColumn();

            $stmt->execute([$prevStartStr, $prevEndStr]);
            $prevTickets = (int)$stmt->fetchColumn();

            // Variations
            $revenueVar = ($prevRevenue != 0)
                ? round((($currentRevenue - $prevRevenue) / $prevRevenue) * 100, 1)
                : 'Nouveau';
            $ticketsVar = ($prevTickets != 0)
                ? round((($currentTickets - $prevTickets) / $prevTickets) * 100, 1)
                : 'Nouveau';

            // Sessions actives (dernier snapshot)
            $stmt2 = $pdo->query("SELECT active_count FROM hotspot_snapshots ORDER BY id DESC LIMIT 1");
            $activeSessions = $stmt2->fetchColumn() ?: 0;

    // Abonnements actifs et expirants (gestion robuste si tables absentes)
$activeSubs = 0;
$expiringSubs = 0;
try {
    $nowStr = date('Y-m-d H:i:s');
    $stmt3 = $pdo->prepare(
        "SELECT COUNT(*) FROM hotspot_users u
         INNER JOIN hotspot_expiry e ON u.username = e.user_id
         WHERE e.expiry > ?"
    );
    $stmt3->execute([$nowStr]);
    $activeSubs = (int)$stmt3->fetchColumn();

    $stmt4 = $pdo->prepare(
        "SELECT COUNT(*) FROM hotspot_users u
         INNER JOIN hotspot_expiry e ON u.username = e.user_id
         WHERE e.expiry BETWEEN ? AND ?"
    );
    $stmt4->execute([$nowStr, date('Y-m-d H:i:s', strtotime('+7 days'))]);
    $expiringSubs = (int)$stmt4->fetchColumn();
} catch (Throwable $e) {
    // Tables manquantes ou synchronisation non effectuée : on garde 0
    ara_log('dashboard abonnements: ' . $e->getMessage(), $config, 'warning');
}

            // Graphique CA (granularité adaptative)
if ($period === 'today' || $period === 'yesterday') {
    // Extraction de l'heure avec LEFT() compatible PostgreSQL
    $stmt5 = $pdo->prepare(
        "SELECT LEFT(sale_time, 2) AS hour_label, SUM(amount) AS total
         FROM sales_log WHERE sale_date = ?
         GROUP BY LEFT(sale_time, 2)
         ORDER BY hour_label"
    );
    $stmt5->execute([$start]);
    $rows = $stmt5->fetchAll();
    $chart = array_map(function($r) {
        return ['label' => $r['hour_label'] . ':00', 'value' => (int)$r['total']];
    }, $rows);
} else {
                $stmt5 = $pdo->prepare(
                    "SELECT sale_date, SUM(amount) AS total
                     FROM sales_log
                     WHERE sale_date BETWEEN ? AND ?
                     GROUP BY sale_date
                     ORDER BY sale_date"
                );
                $stmt5->execute([$start, $end]);
                $rows = $stmt5->fetchAll();
                $chart = array_map(function($r) {
                    return ['label' => $r['sale_date'], 'value' => (int)$r['total']];
                }, $rows);
            }

            // 5 dernières ventes
            $stmt6 = $pdo->prepare(
                "SELECT sale_date, sale_time, username, profile, amount
                 FROM sales_log
                 WHERE sale_date BETWEEN ? AND ?
                 ORDER BY sale_date DESC, sale_time DESC
                 LIMIT 5"
            );
            $stmt6->execute([$start, $end]);
            $recentSales = $stmt6->fetchAll();

            // Réseau (avec cache de 30 secondes)
            $network = get_cached_network_status($config);

            // Alertes
            $alerts = gather_alerts($config, $network);

            json_response([
                'success'       => true,
                'period'        => [
                    'start' => $start,
                    'end'   => $end,
                    'label' => $periodData['label'],
                ],
                'kpis'          => [
                    'revenue'                => $currentRevenue,
                    'previous_revenue'       => $prevRevenue,
                    'revenue_variation'      => $revenueVar,
                    'tickets_sold'           => $currentTickets,
                    'previous_tickets_sold'  => $prevTickets,
                    'tickets_variation'      => $ticketsVar,
                    'active_sessions'        => $activeSessions,
                    'active_subscriptions'   => $activeSubs,
                    'expiring_subscriptions' => $expiringSubs,
                ],
                'revenue_chart' => $chart,
                'recent_sales'  => $recentSales,
                'network'       => $network,
                'alerts'        => $alerts,
            ]);
        } catch (Throwable $e) {
            ara_log('dashboard error: ' . $e->getMessage(), $config, 'error');
            $message = 'Erreur lors de la récupération des données du tableau de bord.';
            if (!empty($config['debug'])) {
                $message .= ' [debug] ' . $e->getMessage();
            }
            json_error($message, 500);
        }
        break;

    // -----------------------------------------------------------------
    // HOTSPOT V2.1 — PHASE H2 : ROUTES UTILISATEURS
    // -----------------------------------------------------------------

    case 'hotspot-users':
        require_admin_token($config);
        try {
            $pdo = ara_db_supabase();

            $search  = trim((string)($_GET['search'] ?? ''));
            $profile = trim((string)($_GET['profile'] ?? 'all'));
            $status  = trim((string)($_GET['status'] ?? 'all')); // all|active|disabled|expired
            $conn    = trim((string)($_GET['connection'] ?? 'all')); // all|online|offline
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $limit   = (int)($_GET['limit'] ?? 25);
            if (!in_array($limit, [25, 50, 100], true)) {
                $limit = 25;
            }

            $where  = '1=1';
            $params = [];
            if ($profile !== 'all' && $profile !== '') {
                $where .= ' AND u.profile = ?';
                $params[] = $profile;
            }
            if ($status === 'active') {
                $where .= ' AND u.disabled = ?';
                $params[] = 'false';
            } elseif ($status === 'disabled') {
                $where .= ' AND u.disabled = ?';
                $params[] = 'true';
            } elseif ($status === 'expired') {
                $where .= ' AND e.expiry IS NOT NULL AND e.expiry <= ?';
                $params[] = date('Y-m-d H:i:s');
            }
            if ($search !== '') {
                $where .= ' AND (u.username ILIKE ? OR u.mac_address ILIKE ? OR u.comment ILIKE ?)';
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            $baseSql = "FROM hotspot_users u LEFT JOIN hotspot_expiry e ON u.username = e.user_id WHERE $where";

            // Le filtre "connexion" ne peut être appliqué qu'en mémoire :
            // l'état en ligne/hors ligne ne vit pas dans hotspot_users
            // (il vient du dernier snapshot poussé par le routeur), voir
            // hotspot_online_usernames(). Dans ce cas uniquement, on
            // renonce à la pagination SQL — le volume actuel (dizaines à
            // basse centaine d'utilisateurs) le permet ; voir rapport
            // final §Limitations pour la piste d'amélioration à grande échelle.
            $onlineSet = ($conn !== 'all') ? hotspot_online_usernames($config) : null;

            if ($conn !== 'all') {
                $stmt = $pdo->prepare("SELECT u.*, e.expiry $baseSql ORDER BY u.username ASC");
                $stmt->execute($params);
                $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $filtered = [];
                foreach ($all as $row) {
                    $isOnline = $onlineSet !== null && in_array($row['username'], $onlineSet, true);
                    $connState = $onlineSet === null ? 'unknown' : ($isOnline ? 'online' : 'offline');
                    if ($conn === 'online' && $connState !== 'online') continue;
                    if ($conn === 'offline' && $connState !== 'offline') continue;
                    $row['_connection'] = $connState;
                    $filtered[] = $row;
                }
                $total = count($filtered);
                $pageRows = array_slice($filtered, ($page - 1) * $limit, $limit);
            } else {
                $countStmt = $pdo->prepare("SELECT COUNT(*) $baseSql");
                $countStmt->execute($params);
                $total = (int)$countStmt->fetchColumn();

                $listSql = "SELECT u.*, e.expiry $baseSql ORDER BY u.username ASC LIMIT ? OFFSET ?";
                $listParams = $params;
                $listParams[] = $limit;
                $listParams[] = ($page - 1) * $limit;
                $stmt = $pdo->prepare($listSql);
                $stmt->execute($listParams);
                $pageRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // État de connexion pour la page courante (si pas déjà calculé ci-dessus)
            if ($conn === 'all') {
                $onlineSetForPage = hotspot_online_usernames($config);
                foreach ($pageRows as &$row) {
                    $row['_connection'] = $onlineSetForPage === null
                        ? 'unknown'
                        : (in_array($row['username'], $onlineSetForPage, true) ? 'online' : 'offline');
                }
                unset($row);
            }

            $items = array_map(function ($row) {
                $public = hotspot_user_public_row($row);
                $public['connection'] = $row['_connection'] ?? 'unknown';
                return $public;
            }, $pageRows);

            // KPI globaux (indépendants des filtres courants — H2 §6)
            $kpi = ['total' => 0, 'active' => 0, 'disabled' => 0, 'expiring' => 0];
            try {
                $kpi['total'] = (int)$pdo->query('SELECT COUNT(*) FROM hotspot_users')->fetchColumn();
                $stmtA = $pdo->prepare('SELECT COUNT(*) FROM hotspot_users WHERE disabled = ?');
                $stmtA->execute(['false']);
                $kpi['active'] = (int)$stmtA->fetchColumn();
                $stmtD = $pdo->prepare('SELECT COUNT(*) FROM hotspot_users WHERE disabled = ?');
                $stmtD->execute(['true']);
                $kpi['disabled'] = (int)$stmtD->fetchColumn();
                // Fenêtre "expirant" (7 jours) — règle déjà en usage dans la
                // route "dashboard" existante (expiring_subscriptions), reprise
                // ici pour rester cohérent avec une règle métier déjà établie
                // plutôt que d'en inventer une nouvelle (H2 §6).
                $stmtE = $pdo->prepare(
                    'SELECT COUNT(*) FROM hotspot_users u
                     INNER JOIN hotspot_expiry e ON u.username = e.user_id
                     WHERE e.expiry BETWEEN ? AND ?'
                );
                $stmtE->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+7 days'))]);
                $kpi['expiring'] = (int)$stmtE->fetchColumn();
            } catch (Throwable $e) {
                ara_log('hotspot-users KPI error: ' . $e->getMessage(), $config, 'warning');
            }

            json_api_success([
                'items' => $items,
                'pagination' => [
                    'page'  => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => $limit > 0 ? (int)ceil($total / $limit) : 0,
                ],
                'kpi' => $kpi,
            ]);
        } catch (Throwable $e) {
            ara_log('hotspot-users error: ' . $e->getMessage(), $config, 'error');
            $msg = 'Erreur lors de la récupération des utilisateurs.';
            if (!empty($config['debug'])) $msg .= ' [debug] ' . $e->getMessage();
            json_api_error('SYNC_ERROR', $msg, 500);
        }
        break;

    case 'hotspot-user':
        require_admin_token($config);
        $username = trim((string)($_GET['username'] ?? ''));
        if (!hotspot_username_valid($username)) {
            json_api_error('INVALID_REQUEST', 'Paramètre username manquant ou invalide.', 400);
        }
        try {
            $pdo = ara_db_supabase();
            $stmt = $pdo->prepare(
                'SELECT u.*, e.expiry FROM hotspot_users u
                 LEFT JOIN hotspot_expiry e ON u.username = e.user_id
                 WHERE u.username = ?'
            );
            $stmt->execute([$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                json_api_error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
            }
            $onlineSet = hotspot_online_usernames($config);
            $public = hotspot_user_public_row($row);
            $public['connection'] = $onlineSet === null
                ? 'unknown'
                : (in_array($username, $onlineSet, true) ? 'online' : 'offline');
            json_api_success($public);
        } catch (Throwable $e) {
            ara_log('hotspot-user error: ' . $e->getMessage(), $config, 'error');
            json_api_error('SYNC_ERROR', 'Erreur lors de la récupération de l\'utilisateur.', 500);
        }
        break;

    case 'hotspot-user-create':
        require_admin_token($config);
        require_post_method();
        $payload  = get_request_payload();
        $username = trim((string)($payload['username'] ?? ''));
        $password = (string)($payload['password'] ?? '');
        $profile  = trim((string)($payload['profile'] ?? ''));
        $comment  = trim((string)($payload['comment'] ?? ''));
        $expiry   = trim((string)($payload['expiry'] ?? ''));
        $limitUptime = trim((string)($payload['limit_uptime'] ?? $payload['limit-uptime'] ?? ''));
        $limitBytes = $payload['limit_bytes_total'] ?? $payload['limit-bytes-total'] ?? null;

        if (!hotspot_username_valid($username)) {
            json_api_error('INVALID_REQUEST', "Nom d'utilisateur manquant ou invalide.", 400);
        }
        if ($password === '') {
            json_api_error('INVALID_REQUEST', 'Mot de passe requis.', 400);
        }
        if ($profile === '') {
            json_api_error('INVALID_REQUEST', 'Profil requis.', 400);
        }
        if (!validate_hotspot_time_limit($limitUptime)) {
            json_api_error('INVALID_REQUEST', 'limit_uptime invalide.', 422);
        }
        if (!validate_hotspot_data_limit($limitBytes)) {
            json_api_error('INVALID_REQUEST', 'limit_bytes_total invalide.', 422);
        }
        if ($expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expiry)) {
            json_api_error('INVALID_REQUEST', "Format d'expiration invalide (attendu AAAA-MM-JJ HH:MM:SS).", 400);
        }
        if ($limitBytes !== null && $limitBytes !== '' && (!is_numeric($limitBytes) || (int)$limitBytes < 0)) {
            json_api_error('INVALID_REQUEST', 'limit_bytes_total invalide.', 400);
        }

        try {
            $pdo = ara_db_supabase();
            $profileCheck = hotspot_profile_exists($pdo, $profile, $config);
            if ($profileCheck === false) {
                json_api_error('PROFILE_NOT_FOUND', 'Profil inconnu.', 404);
            }

            $existsStmt = $pdo->prepare('SELECT 1 FROM hotspot_users WHERE username = ?');
            $existsStmt->execute([$username]);
            if ($existsStmt->fetchColumn()) {
                json_api_error('USER_EXISTS', 'Cet utilisateur existe déjà.', 409);
            }

            $commandId = queue_hotspot_command($config, 'create', $username, [
                'profile' => $profile,
                'comment' => $comment,
                'expiry' => $expiry !== '' ? $expiry : null,
                'password' => $password,
                'limit_uptime' => $limitUptime !== '' ? $limitUptime : null,
                'limit_bytes_total' => ($limitBytes === null || $limitBytes === '') ? null : (int)$limitBytes,
            ]);
            if ($commandId === null) {
                json_api_error('COMMAND_QUEUE_FAILED', 'Impossible de mettre la création en file.', 500);
            }

            // The router remains the operational source of truth. The mirror
            // is populated by the next MikroTik sync after the worker ACK.
            json_api_success([
                'username' => $username,
                'profile' => $profile,
                'comment' => $comment,
                'disabled' => false,
                'expiry' => $expiry !== '' ? $expiry : null,
                'limit_uptime' => $limitUptime !== '' ? $limitUptime : null,
                'limit_bytes_total' => ($limitBytes === null || $limitBytes === '') ? null : (int)$limitBytes,
                'command' => ['id' => $commandId, 'status' => 'PENDING'],
            ], 201);
        } catch (Throwable $e) {
            ara_log('hotspot-user-create error: ' . $e->getMessage(), $config, 'error');
            json_api_error('USER_CREATE_FAILED', "Impossible de créer l'utilisateur.", 500);
        }
        break;

    case 'hotspot-user-update':
        require_admin_token($config);
        require_post_method();
        $payload  = get_request_payload();
        $username = trim((string)($payload['username'] ?? ''));
        if (!hotspot_username_valid($username)) {
            json_api_error('INVALID_REQUEST', "Nom d'utilisateur manquant ou invalide.", 400);
        }

        try {
            $pdo = ara_db_supabase();
            $existsStmt = $pdo->prepare('SELECT 1 FROM hotspot_users WHERE username = ?');
            $existsStmt->execute([$username]);
            if (!$existsStmt->fetchColumn()) {
                json_api_error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
            }

            if (isset($payload['profile'])) {
                $profile = trim((string)$payload['profile']);
                if ($profile === '') {
                    json_api_error('INVALID_REQUEST', 'Profil invalide.', 400);
                }
                $profileCheck = hotspot_profile_exists($pdo, $profile, $config);
                if ($profileCheck === false) {
                    json_api_error('PROFILE_NOT_FOUND', 'Profil inconnu.', 404);
                }
            }

            $expiry = array_key_exists('expiry', $payload) ? trim((string)$payload['expiry']) : null;
            if ($expiry !== null && $expiry !== '' &&
                !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expiry)) {
                json_api_error('INVALID_REQUEST', "Format d'expiration invalide (attendu AAAA-MM-JJ HH:MM:SS).", 400);
            }

            $hasLimitUptime = array_key_exists('limit_uptime', $payload) || array_key_exists('limit-uptime', $payload);
            if ($hasLimitUptime) {
                $limitUptimeValue = $payload['limit_uptime'] ?? $payload['limit-uptime'];
                if (!validate_hotspot_time_limit($limitUptimeValue === null ? null : (string)$limitUptimeValue)) {
                    json_api_error('INVALID_REQUEST', 'limit_uptime invalide.', 422);
                }
            }

            $hasLimitBytes = array_key_exists('limit_bytes_total', $payload) || array_key_exists('limit-bytes-total', $payload);
            if ($hasLimitBytes) {
                $limitBytes = $payload['limit_bytes_total'] ?? $payload['limit-bytes-total'];
                if ($limitBytes !== null && $limitBytes !== '' && (!is_numeric($limitBytes) || (int)$limitBytes < 0)) {
                    json_api_error('INVALID_REQUEST', 'limit_bytes_total invalide.', 400);
                }
            }

            // Explicit whitelist: never forward arbitrary request fields to
            // hotspot_commands. The worker only receives fields it knows.
            $commandPayload = [];
            foreach (['password', 'profile', 'comment', 'mac_address', 'expiry'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $commandPayload[$field] = $payload[$field];
                }
            }
            $commandPayload['limit_uptime'] = array_key_exists('limit_uptime', $payload)
                ? ($payload['limit_uptime'] === '' ? null : trim((string)$payload['limit_uptime']))
                : (array_key_exists('limit-uptime', $payload) ? ($payload['limit-uptime'] === '' ? null : trim((string)$payload['limit-uptime'])) : null);
            $commandPayload['limit_bytes_total'] = array_key_exists('limit_bytes_total', $payload)
                ? (($payload['limit_bytes_total'] === '' || $payload['limit_bytes_total'] === null) ? null : (int)$payload['limit_bytes_total'])
                : (array_key_exists('limit-bytes-total', $payload) ? (($payload['limit-bytes-total'] === '' || $payload['limit-bytes-total'] === null) ? null : (int)$payload['limit-bytes-total']) : null);

            $commandId = queue_hotspot_command($config, 'update', $username, $commandPayload);
            if ($commandId === null) {
                json_api_error('COMMAND_QUEUE_FAILED', 'Impossible de mettre la modification en file.', 500);
            }

            // No optimistic mirror update: the worker ACK + next router sync
            // confirm the actual operational state.
            json_api_success([
                'username' => $username,
                'command' => ['id' => $commandId, 'status' => 'PENDING'],
            ]);
        } catch (Throwable $e) {
            ara_log('hotspot-user-update error: ' . $e->getMessage(), $config, 'error');
            json_api_error('USER_UPDATE_FAILED', "Impossible de modifier l'utilisateur.", 500);
        }
        break;

    case 'hotspot-user-enable':
    case 'hotspot-user-disable':
        require_admin_token($config);
        require_post_method();
        $isDisable = ($route === 'hotspot-user-disable');
        $payload  = get_request_payload();
        $username = trim((string)($payload['username'] ?? ''));
        if (!hotspot_username_valid($username)) {
            json_api_error('INVALID_REQUEST', "Nom d'utilisateur manquant ou invalide.", 400);
        }
        try {
            $pdo = ara_db_supabase();
            $existsStmt = $pdo->prepare('SELECT 1 FROM hotspot_users WHERE username = ?');
            $existsStmt->execute([$username]);
            if (!$existsStmt->fetchColumn()) {
                json_api_error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
            }

            $action = $isDisable ? 'disable' : 'enable';
            $commandId = queue_hotspot_command($config, $action, $username);
            if ($commandId === null) {
                json_api_error('COMMAND_QUEUE_FAILED', 'Impossible de mettre la commande en file.', 500);
            }

            json_api_success([
                'username' => $username,
                'disabled' => $isDisable,
                'command' => ['id' => $commandId, 'status' => 'PENDING'],
            ]);
        } catch (Throwable $e) {
            ara_log($route . ' error: ' . $e->getMessage(), $config, 'error');
            json_api_error($isDisable ? 'USER_DISABLE_FAILED' : 'USER_ENABLE_FAILED',
                "Impossible de modifier le statut de l'utilisateur.", 500);
        }
        break;

    case 'hotspot-user-delete':
        require_admin_token($config);
        require_post_method();
        $payload  = get_request_payload();
        $username = trim((string)($payload['username'] ?? ''));
        if (!hotspot_username_valid($username)) {
            json_api_error('INVALID_REQUEST', "Nom d'utilisateur manquant ou invalide.", 400);
        }
        try {
            $pdo = ara_db_supabase();
            $existsStmt = $pdo->prepare('SELECT 1 FROM hotspot_users WHERE username = ?');
            $existsStmt->execute([$username]);
            if (!$existsStmt->fetchColumn()) {
                // Delete is idempotent: if the operational target is already
                // absent, enqueueing a delete is unnecessary.
                json_api_success([
                    'username' => $username,
                    'deleted' => true,
                    'command' => null,
                ]);
            }

            $commandId = queue_hotspot_command($config, 'delete', $username);
            if ($commandId === null) {
                json_api_error('COMMAND_QUEUE_FAILED', 'Impossible de mettre la suppression en file.', 500);
            }

            json_api_success([
                'username' => $username,
                'deleted' => false,
                'command' => ['id' => $commandId, 'status' => 'PENDING'],
            ]);
        } catch (Throwable $e) {
            ara_log('hotspot-user-delete error: ' . $e->getMessage(), $config, 'error');
            json_api_error('USER_DELETE_FAILED', "Impossible de supprimer l'utilisateur.", 500);
        }
        break;

    // -----------------------------------------------------------------
    // HOTSPOT V2.1 — CONTRAT H1 : PROFILS / SESSIONS ACTIVES / VOUCHERS
    // (lecture uniquement — les mutations sont volontairement non
    // implémentées à ce stade, voir §7/§16 du brief H1 et NOT_IMPLEMENTED
    // ci-dessous). Même conventions que "H2 : UTILISATEURS" ci-dessus
    // (json_api_success/json_api_error, require_admin_token, colonnes
    // réellement présentes dans hotspot_profiles / hotspot_snapshots /
    // hotspot_users — aucune table ni colonne fictive créée).
    // -----------------------------------------------------------------

    case 'hotspot-profiles':
        require_admin_token($config);
        try {
            $pdo = ara_db_supabase();
            $search = trim((string)($_GET['search'] ?? ''));

            $where  = '1=1';
            $params = [];
            if ($search !== '') {
                $where .= ' AND profile_name ILIKE ?';
                $params[] = '%' . $search . '%';
            }

            $stmt = $pdo->prepare("SELECT * FROM hotspot_profiles WHERE $where ORDER BY profile_name ASC");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $items = array_map('hotspot_profile_public_row', $rows);

            json_api_success([
                'items' => $items,
                'total' => count($items),
            ]);
        } catch (Throwable $e) {
            ara_log('hotspot-profiles error: ' . $e->getMessage(), $config, 'error');
            $msg = 'Erreur lors de la récupération des profils.';
            if (!empty($config['debug'])) $msg .= ' [debug] ' . $e->getMessage();
            json_api_error('SYNC_ERROR', $msg, 500);
        }
        break;

    case 'hotspot-profile':
        require_admin_token($config);
        $profileId = trim((string)($_GET['id'] ?? ''));
        if ($profileId === '') {
            json_api_error('INVALID_REQUEST', 'Paramètre id (nom du profil) manquant.', 400);
        }
        try {
            $pdo = ara_db_supabase();
            $stmt = $pdo->prepare('SELECT * FROM hotspot_profiles WHERE profile_name = ?');
            $stmt->execute([$profileId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                json_api_error('PROFILE_NOT_FOUND', 'Profil introuvable.', 404);
            }
            json_api_success(hotspot_profile_public_row($row));
        } catch (Throwable $e) {
            ara_log('hotspot-profile error: ' . $e->getMessage(), $config, 'error');
            json_api_error('SYNC_ERROR', 'Erreur lors de la récupération du profil.', 500);
        }
        break;

    case 'hotspot-profile-create':
    case 'hotspot-profile-update':
    case 'hotspot-profile-delete':
        require_admin_token($config);
        require_post_method();
        // Le routeur MikroTik est derrière un CGNAT (voir admin/index.php) :
        // aucune connexion Render→routeur n'est possible. Cette route passe
        // donc par la MÊME file asynchrone que les mutations utilisateur
        // (hotspot_commands + hotspot-command-worker.rsc), avec des actions
        // dédiées 'profile-create'/'profile-update'/'profile-delete'.
        $payload = get_request_payload();
        $profileName = trim((string)($payload['profile_name'] ?? $payload['name'] ?? ''));

        if ($route === 'hotspot-profile-create') {
            if (!hotspot_profile_name_valid($profileName)) {
                json_api_error('INVALID_REQUEST', 'Nom de profil manquant ou invalide.', 400);
            }
            try {
                $pdo = ara_db_supabase();
                if (hotspot_profile_exists($pdo, $profileName, $config) === true) {
                    json_api_error('PROFILE_ALREADY_EXISTS', 'Ce profil existe déjà.', 409);
                }
            } catch (Throwable $e) {
                ara_log('hotspot-profile-create pré-check error: ' . $e->getMessage(), $config, 'warning');
            }
        } else {
            // update / delete : le profil doit déjà exister dans le miroir.
            if (!hotspot_profile_name_valid($profileName)) {
                json_api_error('INVALID_REQUEST', 'Paramètre profile_name manquant ou invalide.', 400);
            }
            try {
                $pdo = ara_db_supabase();
                if (hotspot_profile_exists($pdo, $profileName, $config) === false) {
                    json_api_error('PROFILE_NOT_FOUND', 'Profil introuvable.', 404);
                }
            } catch (Throwable $e) {
                ara_log('hotspot-profile-' . $route . ' pré-check error: ' . $e->getMessage(), $config, 'warning');
            }
        }

        $commandPayload = [];
        if ($route === 'hotspot-profile-create' || $route === 'hotspot-profile-update') {
            if (array_key_exists('shared_users', $payload)) {
                $commandPayload['shared_users'] = (int)$payload['shared_users'];
            }
            if (array_key_exists('rate_limit', $payload)) {
                $commandPayload['rate_limit'] = trim((string)$payload['rate_limit']);
            }
            if (array_key_exists('on_login', $payload)) {
                $commandPayload['on_login'] = trim((string)$payload['on_login']);
            }
            if (array_key_exists('address_pool', $payload)) {
                $commandPayload['address_pool'] = trim((string)$payload['address_pool']);
            }
            if ($route === 'hotspot-profile-create' && trim((string)($commandPayload['rate_limit'] ?? '')) === '') {
                json_api_error('INVALID_REQUEST', 'rate_limit requis pour la création d’un profil.', 400);
            }
        }

        $action = match ($route) {
            'hotspot-profile-create' => 'profile-create',
            'hotspot-profile-update' => 'profile-update',
            default                  => 'profile-delete',
        };

        $commandId = queue_hotspot_command($config, $action, $profileName, $commandPayload);
        if ($commandId === null) {
            json_api_error('COMMAND_QUEUE_FAILED', 'Impossible de mettre la commande en file.', 500);
        }

        json_api_success(['command_id' => $commandId, 'status' => 'PENDING'], 202);
        break;

    case 'hotspot-active':
        require_admin_token($config);
        try {
            $snapshot = null;
            try {
                $pdo = ara_db_supabase();
                ensure_hotspot_snapshot_columns($pdo, true);
                $snapshot = $pdo->query('SELECT * FROM hotspot_snapshots ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                try {
                    $pdoLocal = ara_db($config);
                    $pdoLocal->exec("CREATE TABLE IF NOT EXISTS hotspot_snapshots (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        snapshot_date TEXT NOT NULL,
                        snapshot_time TEXT NOT NULL,
                        active_count INTEGER NOT NULL,
                        users_blob TEXT,
                        received_at TEXT NOT NULL
                    )");
                    ensure_hotspot_snapshot_columns($pdoLocal, false);
                    $snapshot = $pdoLocal->query('SELECT * FROM hotspot_snapshots ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $e2) {
                    $snapshot = null;
                }
            }

            if (!$snapshot) {
                json_api_success(['sessions' => [], 'count' => 0, 'source' => 'snapshot', 'status' => 'UNKNOWN']);
            }

            $routerStatus = compute_router_status(
                $snapshot['snapshot_date'] ?? null,
                $snapshot['snapshot_time'] ?? null,
                $snapshot['received_at'] ?? null
            );

            if ($routerStatus['status'] !== 'ONLINE') {
                // Ne pas transformer une absence/expiration de données en
                // liste de sessions active fictive (§15 du brief) ; le
                // statut réel (OFFLINE ou UNKNOWN) est transmis tel quel.
                json_api_success([
                    'sessions' => [],
                    'count'    => 0,
                    'source'   => 'snapshot',
                    'status'   => $routerStatus['status'],
                ]);
            }

            $sessions = extract_snapshot_users($snapshot);

            json_api_success([
                'sessions' => $sessions,
                'count'    => count($sessions),
                'source'   => 'snapshot',
                'status'   => 'ONLINE',
            ]);
        } catch (Throwable $e) {
            ara_log('hotspot-active error: ' . $e->getMessage(), $config, 'error');
            $msg = 'Erreur lors de la récupération des sessions actives.';
            if (!empty($config['debug'])) $msg .= ' [debug] ' . $e->getMessage();
            json_api_error('SYNC_ERROR', $msg, 500);
        }
        break;

    case 'hotspot-session-disconnect':
        require_admin_token($config);
        require_post_method();
        // Comme pour les profils : passe par la file asynchrone, exécuté
        // par hotspot-command-worker.rsc via
        // /ip hotspot active remove [find user=$username].
        $payload = get_request_payload();
        $username = trim((string)($payload['username'] ?? ''));
        if (!hotspot_username_valid($username)) {
            json_api_error('INVALID_REQUEST', 'Paramètre username manquant ou invalide.', 400);
        }

        // Vérification best-effort que la session est bien active
        // actuellement (issue du dernier snapshot) — n'empêche pas la
        // commande si l'info est indisponible (UNKNOWN), seulement si on
        // sait positivement que la session n'est plus active.
        $onlineSet = hotspot_online_usernames($config);
        if ($onlineSet !== null && !in_array($username, $onlineSet, true)) {
            json_api_error('SESSION_NOT_ACTIVE', 'Cette session n’est plus active.', 409);
        }

        $commandId = queue_hotspot_command($config, 'disconnect', $username, []);
        if ($commandId === null) {
            json_api_error('COMMAND_QUEUE_FAILED', 'Impossible de mettre la commande en file.', 500);
        }

        json_api_success(['command_id' => $commandId, 'status' => 'PENDING'], 202);
        break;

    case 'hotspot-vouchers':
        require_admin_token($config);
        try {
            $pdo = ara_db_supabase();

            $search  = trim((string)($_GET['search'] ?? ''));
            $profile = trim((string)($_GET['profile'] ?? 'all'));
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $limit   = (int)($_GET['limit'] ?? 25);
            if (!in_array($limit, [25, 50, 100], true)) {
                $limit = 25;
            }

            // Aucune table de vouchers dédiée n'existe dans le dépôt
            // (audit §16 du brief) : les vouchers sont aujourd'hui
            // identifiés via le préfixe de commentaire "vc-"/"up-" sur
            // hotspot_users (convention déjà utilisée par
            // lib/voucher.php::getUsersByComment côté routeur). Cette
            // route lit donc le même mirror Supabase que hotspot-users,
            // filtré sur ce préfixe, plutôt que d'inventer une nouvelle
            // architecture persistante — limitation à documenter (§17).
            $where  = "(u.comment ILIKE 'vc-%' OR u.comment ILIKE 'up-%')";
            $params = [];
            if ($profile !== 'all' && $profile !== '') {
                $where .= ' AND u.profile = ?';
                $params[] = $profile;
            }
            if ($search !== '') {
                $where .= ' AND (u.username ILIKE ? OR u.comment ILIKE ?)';
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
            }

            $baseSql = "FROM hotspot_users u LEFT JOIN hotspot_expiry e ON u.username = e.user_id WHERE $where";

            $countStmt = $pdo->prepare("SELECT COUNT(*) $baseSql");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $listParams = $params;
            $listParams[] = $limit;
            $listParams[] = ($page - 1) * $limit;
            $stmt = $pdo->prepare("SELECT u.*, e.expiry $baseSql ORDER BY u.username ASC LIMIT ? OFFSET ?");
            $stmt->execute($listParams);
            $items = array_map('hotspot_user_public_row', $stmt->fetchAll(PDO::FETCH_ASSOC));

            json_api_success([
                'items' => $items,
                'total' => $total,
                'page'  => $page,
                'limit' => $limit,
            ]);
        } catch (Throwable $e) {
            ara_log('hotspot-vouchers error: ' . $e->getMessage(), $config, 'error');
            $msg = 'Erreur lors de la récupération des vouchers.';
            if (!empty($config['debug'])) $msg .= ' [debug] ' . $e->getMessage();
            json_api_error('SYNC_ERROR', $msg, 500);
        }
        break;

    case 'hotspot-voucher-generate':
        require_admin_token($config);
        require_post_method();
        // La génération avancée de vouchers (H1 §7/§16) implique une
        // création d'utilisateurs sur le routeur (batch), hors périmètre
        // H1 (contrat seul) et non réalisable sans connexion Render→
        // routeur. Réutilisera hotspot-user-create (déjà implémenté en
        // H2) une fois cette voie disponible.
        json_api_error('NOT_IMPLEMENTED', 'Cette fonctionnalité sera disponible dans une prochaine phase.', 501);
        break;

    default:
        json_error('Route inconnue.', 404);
}
