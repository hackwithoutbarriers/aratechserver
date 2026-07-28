<?php
return [
    'debug' => false,
    'allowed_origin' => 'http://10.10.0.1', // à ajuster selon l'IP du hotspot

    'paygate' => [
        'auth_token'     => 'VOTRE_AUTH_TOKEN',
        'base_url'       => 'https://paygateglobal.com/api/v1',
        'pay_endpoint'   => '/pay',
        'callback_url'   => 'https://aratech-ldg0.onrender.com/callback.php',
        'webhook_secret' => 'VOTRE_WEBHOOK_SECRET',
    ],

    'mikrotik' => [
        'host'           => '192.168.88.1',
        'api_user'       => 'api-hotspot',
        'api_password'   => 'VOTRE_MOT_DE_PASSE_API',
        'api_port'       => 8728,
        'hotspot_server' => 'all',
        'connect_retries' => 3,
        'connect_timeout' => 10,
    ],

    'sms' => [
        'api_url'        => 'https://api.votre-sms-gateway.com/send',
        'api_key'        => 'VOTRE_CLE_API_SMS',
        'sender_id'      => 'ARATECH',
        'retry_attempts' => 3,
        'backoff_base'   => 1,
    ],

    'admin' => [
        'token' => 'VOTRE_TOKEN_ADMIN',
    ],

    'db_path'  => __DIR__ . '/data/transactions.sqlite',
    'log_path' => __DIR__ . '/data/callback.log',

    'packages' => [
        '10H' => ['label' => '10 Heures', 'price' => 100,  'profile' => '10H-100F',  'validity' => '24h'],
        '24H' => ['label' => '24 Heures', 'price' => 200,  'profile' => '24H-200F',  'validity' => '48h'],
        '7J'  => ['label' => '7 Jours',   'price' => 500,  'profile' => '7J-500F',   'validity' => '7j'],
        '30J' => ['label' => '30 Jours',  'price' => 1500, 'profile' => '30J-1500F', 'validity' => '30j'],
    ],

    'maintenance' => [
        'retention_days'    => 90,
        'log_rotation_size' => 10485760,
    ],
];
