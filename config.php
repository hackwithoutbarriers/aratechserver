<?php
return [
    // ================= MikroTik =================
    'mikrotik' => [
        'host'           => '192.168.88.1',      // IP du routeur (privée)
        'api_user'       => 'api-hotspot',
        'api_password'   => 'Dieu100%',
        'api_port'       => 8728,
        'hotspot_server' => 'all',
        'connect_retries' => 3,
        'connect_timeout' => 10,
    ],

    // ================= Admin (pour gérer les annonces) =================
    'admin' => [
        'token' => 'VOTRE_TOKEN_ADMIN', // changez-moi
    ],

    // ================= Stockage =================
    'db_path'  => __DIR__ . '/data/transactions.sqlite', // sera utilisé pour les stats
    'log_path' => __DIR__ . '/data/app.log',

    // ================= CORS =================
    'allowed_origin' => 'http://10.10.0.1',

    // ================= Debug =================
    'debug' => false,
];
