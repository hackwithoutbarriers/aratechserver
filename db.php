<?php
declare(strict_types=1);

/**
 * Return the application database connection.
 *
 * Local SQLite storage has been removed. Keep the historical ara_db()
 * function as a compatibility wrapper because api.php still calls it from
 * a few legacy fallback paths; it now always points to Supabase/PostgreSQL.
 */
function ara_db(array $config): PDO
{
    return ara_db_supabase();
}

/**
 * Application logging without filesystem persistence.
 * Render/container logs are the authoritative runtime log sink.
 */
function ara_log(string $message, array $config, string $level = 'info'): void
{
    if (($config['debug'] ?? false) === false && $level === 'debug') {
        return;
    }

    error_log(
        '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message
    );
}

// ---------------------------------------------------------------------
// Fonctions Turso conservées uniquement pour compatibilité avec certains
// chemins historiques de l'API. Elles ne constituent plus un stockage local.
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

function restore_from_turso_if_empty(
    PDO $pdo,
    array $config,
    string $table,
    string $tursoQuery,
    array $tursoArgs,
    string $insertLocalSQL
): bool {
    // This is retained only for legacy compatibility. $pdo is Supabase now,
    // so no local database is created or populated by this function.
    $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
    if ((int)$stmt->fetchColumn() > 0) {
        return false;
    }

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
        return false;
    }
}

/**
 * Connexion PostgreSQL Supabase.
 * Les paramètres proviennent des variables d'environnement Render.
 */
function ara_db_supabase(): PDO
{
    $host     = trim(getenv('SUPABASE_PGHOST')     ?: 'aws-1-eu-west-1.pooler.supabase.com');
    $port     = trim(getenv('SUPABASE_PGPORT')     ?: '6543');
    $dbname   = trim(getenv('SUPABASE_PGDATABASE') ?: 'postgres');
    $user     = trim(getenv('SUPABASE_PGUSER')     ?: 'postgres.pqmmuaavceftkovzrhyg');
    $password = trim(getenv('SUPABASE_PGPASSWORD') ?: '');

    if ($password === '') {
        throw new RuntimeException('SUPABASE_PGPASSWORD non configuré.');
    }

    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET statement_timeout = '10s'");
    return $pdo;
}
