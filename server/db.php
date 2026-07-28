<?php
declare(strict_types=1);

function ara_db(array $config): PDO {
    $dir = dirname($config['db_path']);
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    // Tables identiques à vos versions, mais je les conserve
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions ( ... )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ads ( ... )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS loyalty ( ... )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS referrals ( ... )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS track_events ( ... )");
    // ... (indexes)
    return $pdo;
}

function ara_random_code(int $length = 6): string { /* identique */ }
function ara_log(string $message, array $config): void { /* identique */ }
function ara_maintenance(array $config, int $retention_days = 90): void { /* identique avec rotation */ }

// Chiffrement (optionnel)
function ara_encrypt(string $data, string $key): string { ... }
function ara_decrypt(string $encrypted, string $key): string { ... }
