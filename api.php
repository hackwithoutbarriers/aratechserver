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

/**
 * Table de file de commandes MikroTik (H2 §19).
 *
 * Aucun mécanisme de file (command/queue/job/pending/sync/push/action)
 * n'a été trouvé ailleurs dans le dépôt lors de l'audit H2 : la seule
 * voie existante Render → routeur est le "push" initié PAR le routeur
 * (push-status, sync-users, sync-profiles, set-expiry). Le Render ne
 * peut pas ouvrir de connexion vers 192.168.88.1.
 *
 * Cette table est donc une structure MINIMALE : elle enregistre
 * l'intention (create/update/enable/disable/delete) pour qu'un futur
 * script MikroTik/scheduler (à écrire en H3, sur le modèle de
 * push-hotspot-status.rsc) puisse la consommer via une route de type
 * "GET api.php?route=hotspot-commands-pending" (NON implémentée ici)
 * et confirmer l'exécution. Pour l'instant, aucune consommation
 * automatique n'existe : voir le rapport final §11/§18.
 */
function ensure_hotspot_commands_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS hotspot_commands (
        id BIGSERIAL PRIMARY KEY,
        action TEXT NOT NULL,
        username TEXT NOT NULL,
        payload TEXT,
        status TEXT NOT NULL DEFAULT \'pending\',
        created_at TEXT NOT NULL,
        processed_at TEXT
    )');
}

function queue_hotspot_command(array $config, string $action, string $username, array $payload = []): void
{
    try {
        $pdo = ara_db_supabase();
        ensure_hotspot_commands_table($pdo);
        // Ne jamais persister le mot de passe en clair dans la file de commandes.
        unset($payload['password']);
        $stmt = $pdo->prepare(
            'INSERT INTO hotspot_commands (action, username, payload, status, created_at)
             VALUES (?, ?, ?, \'pending\', ?)'
        );
        $stmt->execute([$action, $username, json_encode($payload, JSON_UNESCAPED_UNICODE), date('c')]);
    } catch (Throwable $e) {
        // La file de commandes est une amélioration H2 non bloquante :
        // si elle échoue, la mutation Supabase elle-même n'est pas annulée,
        // mais on trace l'échec (jamais le mot de passe).
        ara_log('hotspot_commands queue error (' . $action . '/' . $username . '): ' . $e->getMessage(), $config, 'error');
    }
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

function require_sync_key(array $config): void
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $expected = $config['hotspot']['sync_key'] ?? '';
    if ($expected === '' || !hash_equals($expected, $key)) {
        json_error('Non autorisé.', 403);
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
    $columns = [
        'router_identity' => $isPostgres ? 'TEXT'             : 'TEXT',
        'router_uptime'   => $isPostgres ? 'TEXT'             : 'TEXT',
        'router_version'  => $isPostgres ? 'TEXT'             : 'TEXT',
        'cpu_load'        => $isPostgres ? 'DOUBLE PRECISION' : 'REAL',
        'memory_total'    => $isPostgres ? 'BIGINT'           : 'INTEGER',
        'memory_free'     => $isPostgres ? 'BIGINT'           : 'INTEGER',
        'users_json'      => $isPostgres ? 'JSONB'            : 'TEXT',
        'network_json'    => $isPostgres ? 'JSONB'            : 'TEXT',
    ];

    if ($isPostgres) {
        $stmt = $pdo->prepare(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'hotspot_snapshots'"
        );
        $stmt->execute();
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $stmt = $pdo->query('PRAGMA table_info(hotspot_snapshots)');
        $existing = array_map(static fn($r) => $r['name'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

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
    static $status = null;
    if ($status !== null) {
        return $status;
    }

    $status = [
        'internet'    => 'UNKNOWN',
        'mikrotik'    => 'OFFLINE',
        'poe_switch'  => 'UNKNOWN',
        'ap_01'       => 'UNKNOWN',
        'ap_02'       => 'UNKNOWN',
    ];

    $api = new RouterosAPI();
    $api->timeout   = $config['mikrotik']['connect_timeout'] ?? 10;
    $api->attempts  = $config['mikrotik']['connect_retries'] ?? 3;
    $port           = $config['mikrotik']['api_port'] ?? 8728;
    $connected = $api->connect(
        $config['mikrotik']['host'],
        $config['mikrotik']['api_user'],
        $config['mikrotik']['api_password'],
        $port
    );

    if ($connected) {
        $status['mikrotik'] = 'ONLINE';
        $status['internet'] = 'ONLINE'; // simplification

        $interfaces = $api->comm('/interface/print');
        $api->disconnect();

        foreach ($interfaces as $iface) {
            $name    = $iface['name'] ?? '';
            $running = ($iface['running'] ?? 'false') === 'true';
            if (stripos($name, 'poe') !== false && stripos($name, 'ether') !== false) {
                $status['poe_switch'] = $running ? 'ONLINE' : 'OFFLINE';
            } elseif (stripos($name, 'wlan') !== false) {
                if (stripos($name, '1') !== false) {
                    $status['ap_01'] = $running ? 'ONLINE' : 'OFFLINE';
                } elseif (stripos($name, '2') !== false) {
                    $status['ap_02'] = $running ? 'ONLINE' : 'OFFLINE';
                }
            }
        }
    } else {
        // Journaliser l'erreur pour diagnostic
        $errorMsg = error_get_last()['message'] ?? 'Connexion échouée';
        ara_log('MikroTik connection failed: ' . $errorMsg, $config, 'error');
    }

    return $status;
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

    case '':
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
            $msg = 'Erreur get-sales : ' . $e->getMessage();
            if (!empty($config['debug'])) $msg .= ' [debug] ' . $e->getMessage();
            json_error($msg, 500);
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
        require_sync_key($config);
        $payload = get_request_payload();
        $users = $payload['users'] ?? [];
        if (!is_array($users) || empty($users)) {
            json_error('Aucune donnée utilisateur.');
        }
        try {
            $pdo = ara_db_supabase();
            // Insertion/update en batch (on peut vider la table avant ou faire un upsert)
            $pdo->beginTransaction();
            // Vider la table pour refléter l'état exact du routeur (optionnel)
            $pdo->exec("DELETE FROM hotspot_users");
            $stmt = $pdo->prepare(
                "INSERT INTO hotspot_users (username, password, profile, mac_address, comment, disabled, bytes_in, bytes_out, uptime, server)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($users as $u) {
                $stmt->execute([
                    $u['name'] ?? '',
                    $u['password'] ?? '',
                    $u['profile'] ?? '',
                    $u['mac-address'] ?? '',
                    $u['comment'] ?? '',
                    ($u['disabled'] ?? 'false') === 'true' ? 'true' : 'false',
                    (int)($u['bytes-in'] ?? 0),
                    (int)($u['bytes-out'] ?? 0),
                    $u['uptime'] ?? '',
                    $u['server'] ?? '',
                ]);
            }
            $pdo->commit();
            json_response(['success' => true, 'count' => count($users)]);
        } catch (Throwable $e) {
            if (isset($pdo)) $pdo->rollBack();
            json_error('Erreur sync-users: ' . $e->getMessage(), 500);
        }
        break;

        case 'sync-profiles':
        require_sync_key($config);
        $payload = get_request_payload();
        $profiles = $payload['profiles'] ?? [];
        if (!is_array($profiles) || empty($profiles)) {
            json_error('Aucune donnée profil.');
        }
        try {
            $pdo = ara_db_supabase();
            // Création automatique de la table si elle n'existe pas
            $pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_profiles (
                profile_name TEXT PRIMARY KEY,
                shared_users INTEGER NOT NULL DEFAULT 1,
                rate_limit TEXT,
                on_login TEXT,
                address_pool TEXT
            )");
            $stmt = $pdo->prepare(
                "INSERT INTO hotspot_profiles (profile_name, shared_users, rate_limit, on_login, address_pool)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT (profile_name) DO UPDATE SET
                     shared_users = excluded.shared_users,
                     rate_limit = excluded.rate_limit,
                     on_login = excluded.on_login,
                     address_pool = excluded.address_pool"
            );
            foreach ($profiles as $p) {
                $stmt->execute([
                    $p['name'] ?? '',
                    (int)($p['shared-users'] ?? 1),
                    $p['rate-limit'] ?? '',
                    $p['on-login'] ?? '',
                    $p['address-pool'] ?? '',
                ]);
            }
            json_response(['success' => true, 'count' => count($profiles)]);
        } catch (Throwable $e) {
            ara_log('sync-profiles error: ' . $e->getMessage(), $config, 'error');
            $msg = 'Erreur sync-profiles.';
            if (!empty($config['debug'])) $msg .= ' [debug] ' . $e->getMessage();
            json_error($msg, 500);
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

        if (!hotspot_username_valid($username)) {
            json_api_error('INVALID_REQUEST', 'Nom d\'utilisateur manquant ou invalide.', 400);
        }
        if ($password === '') {
            json_api_error('INVALID_REQUEST', 'Mot de passe requis.', 400);
        }
        if ($profile === '') {
            json_api_error('INVALID_REQUEST', 'Profil requis.', 400);
        }
        if ($expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expiry)) {
            json_api_error('INVALID_REQUEST', 'Format d\'expiration invalide (attendu AAAA-MM-JJ HH:MM:SS).', 400);
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

            $insert = $pdo->prepare(
                'INSERT INTO hotspot_users (username, password, profile, mac_address, comment, disabled, bytes_in, bytes_out, uptime, server)
                 VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, ?)'
            );
            $insert->execute([$username, $password, $profile, '', $comment, 'false', '', '']);

            if ($expiry !== '') {
                ensure_hotspot_expiry_table($pdo);
                $stmt = $pdo->prepare(
                    "INSERT INTO hotspot_expiry (user_id, expiry, updated_at)
                     VALUES (?, ?, ?)
                     ON CONFLICT (user_id) DO UPDATE SET expiry = excluded.expiry, updated_at = excluded.updated_at"
                );
                $stmt->execute([$username, $expiry, date('c')]);
                try {
                    $pdoLocal = ara_db($config);
                    ensure_hotspot_expiry_table($pdoLocal);
                    $stmtLocal = $pdoLocal->prepare(
                        'INSERT INTO hotspot_expiry (user, expiry, updated_at)
                         VALUES (:user, :expiry, :updated_at)
                         ON CONFLICT(user) DO UPDATE SET expiry = excluded.expiry, updated_at = excluded.updated_at'
                    );
                    $stmtLocal->execute([':user' => $username, ':expiry' => $expiry, ':updated_at' => date('c')]);
                } catch (Throwable $e) {}
            }

            queue_hotspot_command($config, 'create', $username, [
                'profile' => $profile, 'comment' => $comment, 'expiry' => $expiry ?: null, 'password' => $password,
            ]);

            json_api_success([
                'username' => $username, 'profile' => $profile, 'comment' => $comment,
                'disabled' => false, 'expiry' => $expiry ?: null,
            ], 201);
        } catch (Throwable $e) {
            ara_log('hotspot-user-create error: ' . $e->getMessage(), $config, 'error');
            $msg = 'Impossible de créer l\'utilisateur.';
            if (!empty($config['debug'])) $msg .= ' [debug] ' . $e->getMessage();
            json_api_error('USER_CREATE_FAILED', $msg, 500);
        }
        break;

    case 'hotspot-user-update':
        require_admin_token($config);
        require_post_method();
        $payload  = get_request_payload();
        $username = trim((string)($payload['username'] ?? ''));
        if (!hotspot_username_valid($username)) {
            json_api_error('INVALID_REQUEST', 'Nom d\'utilisateur manquant ou invalide.', 400);
        }

        try {
            $pdo = ara_db_supabase();
            $existsStmt = $pdo->prepare('SELECT 1 FROM hotspot_users WHERE username = ?');
            $existsStmt->execute([$username]);
            if (!$existsStmt->fetchColumn()) {
                json_api_error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
            }

            $fields = [];
            $params = [];

            if (isset($payload['profile']) && trim((string)$payload['profile']) !== '') {
                $profile = trim((string)$payload['profile']);
                $profileCheck = hotspot_profile_exists($pdo, $profile, $config);
                if ($profileCheck === false) {
                    json_api_error('PROFILE_NOT_FOUND', 'Profil inconnu.', 404);
                }
                $fields[] = 'profile = ?';
                $params[] = $profile;
            }
            if (isset($payload['comment'])) {
                $fields[] = 'comment = ?';
                $params[] = trim((string)$payload['comment']);
            }
            if (isset($payload['mac_address'])) {
                $fields[] = 'mac_address = ?';
                $params[] = trim((string)$payload['mac_address']);
            }
            if (isset($payload['password']) && (string)$payload['password'] !== '') {
                $fields[] = 'password = ?';
                $params[] = (string)$payload['password'];
            }

            if (!empty($fields)) {
                $params[] = $username;
                $sql = 'UPDATE hotspot_users SET ' . implode(', ', $fields) . ' WHERE username = ?';
                $pdo->prepare($sql)->execute($params);
            }

            $expiry = isset($payload['expiry']) ? trim((string)$payload['expiry']) : null;
            if ($expiry !== null && $expiry !== '') {
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expiry)) {
                    json_api_error('INVALID_REQUEST', 'Format d\'expiration invalide (attendu AAAA-MM-JJ HH:MM:SS).', 400);
                }
                ensure_hotspot_expiry_table($pdo);
                $stmt = $pdo->prepare(
                    "INSERT INTO hotspot_expiry (user_id, expiry, updated_at)
                     VALUES (?, ?, ?)
                     ON CONFLICT (user_id) DO UPDATE SET expiry = excluded.expiry, updated_at = excluded.updated_at"
                );
                $stmt->execute([$username, $expiry, date('c')]);
            }

            queue_hotspot_command($config, 'update', $username, $payload);

            $stmt = $pdo->prepare(
                'SELECT u.*, e.expiry FROM hotspot_users u
                 LEFT JOIN hotspot_expiry e ON u.username = e.user_id
                 WHERE u.username = ?'
            );
            $stmt->execute([$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            json_api_success(hotspot_user_public_row($row ?: ['username' => $username]));
        } catch (Throwable $e) {
            ara_log('hotspot-user-update error: ' . $e->getMessage(), $config, 'error');
            $msg = 'Impossible de modifier l\'utilisateur.';
            if (!empty($config['debug'])) $msg .= ' [debug] ' . $e->getMessage();
            json_api_error('USER_UPDATE_FAILED', $msg, 500);
        }
        break;

    case 'hotspot-user-enable':
    case 'hotspot-user-disable':
        require_admin_token($config);
        require_post_method();
        $targetDisabled = ($route === 'hotspot-user-disable') ? 'true' : 'false';
        $failCode = ($route === 'hotspot-user-disable') ? 'USER_DISABLE_FAILED' : 'USER_ENABLE_FAILED';
        $payload  = get_request_payload();
        $username = trim((string)($payload['username'] ?? ''));
        if (!hotspot_username_valid($username)) {
            json_api_error('INVALID_REQUEST', 'Nom d\'utilisateur manquant ou invalide.', 400);
        }
        try {
            $pdo = ara_db_supabase();
            $existsStmt = $pdo->prepare('SELECT disabled FROM hotspot_users WHERE username = ?');
            $existsStmt->execute([$username]);
            $current = $existsStmt->fetch(PDO::FETCH_ASSOC);
            if (!$current) {
                json_api_error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
            }
            $pdo->prepare('UPDATE hotspot_users SET disabled = ? WHERE username = ?')
                ->execute([$targetDisabled, $username]);

            queue_hotspot_command($config, $route === 'hotspot-user-disable' ? 'disable' : 'enable', $username);

            json_api_success(['username' => $username, 'disabled' => $targetDisabled === 'true']);
        } catch (Throwable $e) {
            ara_log($route . ' error: ' . $e->getMessage(), $config, 'error');
            $msg = 'Impossible de modifier le statut de l\'utilisateur.';
            if (!empty($config['debug'])) $msg .= ' [debug] ' . $e->getMessage();
            json_api_error($failCode, $msg, 500);
        }
        break;

    case 'hotspot-user-delete':
        require_admin_token($config);
        require_post_method();
        $payload  = get_request_payload();
        $username = trim((string)($payload['username'] ?? ''));
        if (!hotspot_username_valid($username)) {
            json_api_error('INVALID_REQUEST', 'Nom d\'utilisateur manquant ou invalide.', 400);
        }
        try {
            $pdo = ara_db_supabase();
            $existsStmt = $pdo->prepare('SELECT 1 FROM hotspot_users WHERE username = ?');
            $existsStmt->execute([$username]);
            if (!$existsStmt->fetchColumn()) {
                json_api_error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404);
            }
            $pdo->prepare('DELETE FROM hotspot_users WHERE username = ?')->execute([$username]);
            try {
                $pdo->prepare('DELETE FROM hotspot_expiry WHERE user_id = ?')->execute([$username]);
            } catch (Throwable $e) {}
            try {
                $pdoLocal = ara_db($config);
                $pdoLocal->prepare('DELETE FROM hotspot_expiry WHERE user = ?')->execute([$username]);
            } catch (Throwable $e) {}

            queue_hotspot_command($config, 'delete', $username);

            json_api_success(['username' => $username, 'deleted' => true]);
        } catch (Throwable $e) {
            ara_log('hotspot-user-delete error: ' . $e->getMessage(), $config, 'error');
            $msg = 'Impossible de supprimer l\'utilisateur.';
            if (!empty($config['debug'])) $msg .= ' [debug] ' . $e->getMessage();
            json_api_error('USER_DELETE_FAILED', $msg, 500);
        }
        break;

    default:
        json_error('Route inconnue.', 404);
}
