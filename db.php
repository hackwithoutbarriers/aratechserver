<?php
declare(strict_types=1);

function ara_db(array $config): PDO
{
    $dir = dirname($config['db_path']);
    if (!is_dir($dir)) mkdir($dir, 0770, true);

    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL;');

    // Table des annonces
    $pdo->exec("CREATE TABLE IF NOT EXISTS ads (
        id          TEXT PRIMARY KEY,
        type        TEXT NOT NULL,
        title       TEXT NOT NULL,
        description TEXT,
        image       TEXT,
        url         TEXT,
        start       TEXT,
        end         TEXT,
        active      INTEGER NOT NULL DEFAULT 1,
        price       INTEGER,
        views       INTEGER NOT NULL DEFAULT 0,
        clicks      INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT NOT NULL,
        updated_at  TEXT
    )");

    // Table des événements de tracking
    $pdo->exec("CREATE TABLE IF NOT EXISTS track_events (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        item_id    TEXT NOT NULL,
        event_type TEXT NOT NULL,
        user       TEXT,
        created_at TEXT NOT NULL
    )");

    // Table de fidélité
    $pdo->exec("CREATE TABLE IF NOT EXISTS loyalty (
        user          TEXT PRIMARY KEY,
        points        INTEGER NOT NULL DEFAULT 0,
        topups        INTEGER NOT NULL DEFAULT 0,
        referral_code TEXT,
        created_at    TEXT NOT NULL,
        updated_at    TEXT
    )");

    // Table des transactions (gardée pour une éventuelle réactivation)
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        identifier        TEXT UNIQUE NOT NULL,
        phone             TEXT,
        package_code      TEXT,
        amount            INTEGER,
        status            TEXT NOT NULL DEFAULT 'pending',
        created_at        TEXT NOT NULL,
        updated_at        TEXT
    )");

    return $pdo;
}

function ara_log(string $message, array $config, string $level = 'info'): void
{
    if ($config['debug'] === false && $level === 'debug') return;
    file_put_contents(
        $config['log_path'],
        '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

// ---------------------------------------------------------------------
// Fonctions Turso (centralisées ici pour être utilisées partout)
// ---------------------------------------------------------------------

function turso_pipeline(array $config, array $stmts): array
{
    if (empty($config['turso']['url']) || empty($config['turso']['token'])) {
        throw new RuntimeException('Turso non configuré.');
    }

    $url   = rtrim($config['turso']['url'], '/') . '/v2/pipeline';
    $token = $config['turso']['token'];

    $requests = [];
    foreach ($stmts as $stmt) {
        $requests[] = [
            'type' => 'execute',
            'stmt' => [
                'sql'  => $stmt['sql'],
                'args' => array_map(
                    static fn($v) => ['type' => 'text', 'value' => (string)$v],
                    $stmt['args'] ?? []
                ),
            ],
        ];
    }
    $requests[] = ['type' => 'close'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['requests' => $requests]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("Turso injoignable (cURL: $err).");
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Réponse Turso invalide (HTTP $code).");
    }

    foreach ($decoded['results'] ?? [] as $result) {
        if (($result['type'] ?? '') === 'error') {
            throw new RuntimeException('Turso SQL: ' . ($result['error']['message'] ?? 'erreur inconnue'));
        }
    }

    return $decoded['results'] ?? [];
}

function turso_rows(array $result): array
{
    $response = $result['response']['result'] ?? [];
    $cols     = array_column($response['cols'] ?? [], 'name');
    $rows     = [];
    foreach ($response['rows'] ?? [] as $row) {
        $assoc = [];
        foreach ($row as $i => $cell) {
            $assoc[$cols[$i]] = $cell['value'] ?? null;
        }
        $rows[] = $assoc;
    }
    return $rows;
}

function restore_from_turso_if_empty(PDO $pdo, array $config, string $table, string $tursoQuery, array $tursoArgs, string $insertLocalSQL): bool
{
    // Vérifier si la locale est vide
    $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
    if ((int)$stmt->fetchColumn() > 0) {
        return false;
    }

    // Turso configuré ?
    if (empty($config['turso']['url']) || empty($config['turso']['token'])) {
        return false;
    }

    try {
        $results = turso_pipeline($config, [[
            'sql'  => $tursoQuery,
            'args' => $tursoArgs,
        ]]);
        $rows = [];
        foreach ($results as $r) {
            if (!empty($r['response']['result']['cols'])) {
                $rows = turso_rows($r);
                break;
            }
        }
        foreach ($rows as $row) {
            $insert = $pdo->prepare($insertLocalSQL);
            $insert->execute(array_values($row));
        }
        return !empty($rows);
    } catch (Throwable $e) {
        // Silencieux
    }
    return false;
}

/**
 * Connexion à la base PostgreSQL Supabase.
 * Les paramètres proviennent des variables d'environnement Render.
 */
function ara_db_supabase(): PDO
{
    $host     = trim(getenv('SUPABASE_PGHOST')     ?: 'aws-1-eu-west-1.pooler.supabase.com');
    $port     = trim(getenv('SUPABASE_PGPORT')     ?: '6543');
    $dbname   = trim(getenv('SUPABASE_PGDATABASE') ?: 'postgres');
    $user     = trim(getenv('SUPABASE_PGUSER')     ?: 'postgres.pqmmuaavceftkovzrhyg');
    $password = trim(getenv('SUPABASE_PGPASSWORD') ?: '');

    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET statement_timeout = '10s'");
    return $pdo;
}
