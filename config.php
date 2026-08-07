<?php
/**
 * config.php — Configuration sécurisée avec variables d'environnement
 * ------------------------------------------------------------------
 * Tous les secrets sont lus depuis les variables d'environnement Render.
 * Les valeurs par défaut ici servent UNIQUEMENT au développement local
 * (et ne doivent jamais être les vrais secrets de production).
 */

return [

    // ================= MikroTik (API RouterOS) =================
    'router_ip'       => getenv('MIKROTIK_HOST') ?: '192.168.88.1',
    'router_user'     => getenv('MIKROTIK_API_USER') ?: 'api-hotspot',
    'router_password' => getenv('MIKROTIK_API_PASS') ?: '',
    'mikrotik' => [
        'host'            => getenv('MIKROTIK_HOST') ?: '192.168.88.1',
        'api_user'        => getenv('MIKROTIK_API_USER') ?: 'api-hotspot',
        'api_password'    => getenv('MIKROTIK_API_PASS') ?: '',
        'api_port'        => (int)(getenv('MIKROTIK_API_PORT') ?: 8728),
        'hotspot_server'  => 'all',
        'connect_retries' => 1,
        'connect_timeout' => 2,
    ],

    // ================= Hotspot (clé de synchronisation) =================
    'hotspot' => [
        'sync_key' => getenv('HOTSPOT_SYNC_KEY') ?: '',
    ],

    // ================= Administration =================
    'admin' => [
        'token' => getenv('ADMIN_TOKEN') ?: '',
    ],
    'admin_password' => getenv('ADMIN_PASSWORD') ?: '',

    // ================= Stockage local (SQLite) =================
    'db_path'  => __DIR__ . '/data/transactions.sqlite',
    'log_path' => __DIR__ . '/data/app.log',

    // ================= CORS =================
    'allowed_origin' => '*',

    // ================= Turso (logs persistants) =================
    'turso' => [
        'url'   => getenv('TURSO_URL') ?: 'https://...',
        'token' => getenv('TURSO_TOKEN') ?: '',
    ],

    // ================= Debug =================
    'debug' => false,
];
