<?php
declare(strict_types=1);

function ara_db(array $config): PDO
{
    $dir = dirname($config['db_path']);
    if (!is_dir($dir)) mkdir($dir, 0770, true);

    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL;');

    // Table des annonces (pour l'admin)
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
