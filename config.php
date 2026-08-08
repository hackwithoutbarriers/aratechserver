<?php
/**
 * config.php — Configuration sécurisée (secrets via variables d'environnement)
 */

return [
    // MikroTik
    'router_ip'       => getenv('MIKROTIK_HOST')       ?: '192.168.88.1',
    'router_user'     => getenv('MIKROTIK_API_USER')   ?: 'admin',
    'router_password' => getenv('MIKROTIK_API_PASS')   ?: '',

    'mikrotik' => [
        'host'            => getenv('MIKROTIK_HOST')       ?: '192.168.88.1',
        'api_user'        => getenv('MIKROTIK_API_USER')   ?: 'admin',
        'api_password'    => getenv('MIKROTIK_API_PASS')   ?: '',
        'api_port'        => (int)(getenv('MIKROTIK_API_PORT')   ?: 8728),
        'hotspot_server'  => 'all',
        'connect_retries' => 1,
        'connect_timeout' => 2,
    ],

    // Hotspot (sync key)
    'hotspot' => [
        'sync_key' => getenv('HOTSPOT_SYNC_KEY') ?: '',
    ],

    // Admin
    'admin' => [
        'token' => getenv('ADMIN_TOKEN') ?: '',
    ],
    'admin_password' => getenv('ADMIN_PASSWORD') ?: '',

    // Base de données SQLite locale (éphémère)
    'db_path'  => __DIR__ . '/data/transactions.sqlite',
    'log_path' => __DIR__ . '/data/app.log',

    // CORS
    'allowed_origin' => '*',

    'debug' => false,
];
