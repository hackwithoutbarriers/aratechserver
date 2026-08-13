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
// Turso — nettoyage partiel (restructuration Mikhmon Personnel, étape 1)
// ---------------------------------------------------------------------
// turso_pipeline() et turso_rows() (le vrai moteur cURL vers Turso) ont
// été supprimées : elles n'étaient de toute façon jamais atteintes, car
// config.php ne définit aucune clé 'turso' (empty($config['turso']['url'])
// est donc toujours vrai avant même d'y arriver). Code mort confirmé.
//
// restore_from_turso_if_empty() est en revanche CONSERVÉE ici, en stub
// sans effet, uniquement parce que api.php (hors périmètre de cette
// étape) l'appelle encore dans le repli local de sa route `status`.
// La supprimer maintenant ferait planter cet appel (fatal error : call
// to undefined function) tant que api.php n'aura pas été retouché.
// À supprimer définitivement dès que ce repli sera nettoyé (étape
// "nettoyage" de la feuille de route, en même temps que api.php).
// ---------------------------------------------------------------------

/**
 * @deprecated Stub de compatibilité, ne fait plus rien. Conservé
 *             uniquement tant que api.php l'appelle encore. Toujours
 *             sans effet : aucune clé 'turso' n'existe dans config.php.
 */
function restore_from_turso_if_empty(
    PDO $pdo,
    array $config,
    string $table,
    string $tursoQuery,
    array $tursoArgs,
    string $insertLocalSQL
): bool {
    return false;
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
