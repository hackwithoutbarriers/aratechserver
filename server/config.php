<?php
return [
    'paygate' => [
        'auth_token'     => 'VOTRE_AUTH_TOKEN_PAYGATE_GLOBAL',
        'base_url'       => 'https://paygateglobal.com/api/v1',
        'pay_endpoint'   => '/pay',
        'callback_url'   => 'https://aratech-ldg0.onrender.com/callback.php', // Votre URL Render exacte
        'webhook_secret' => 'VOTRE_WEBHOOK_SECRET_PAYGATE',
    ],
    'mikrotik' => [
        'host'           => 'IP_PUBLIQUE_OU_DNS_DE_VOTRE_ROUTER', // IP ou DynDNS de votre routeur
        'api_user'       => 'api-hotspot',
        'api_password'   => 'VOTRE_MOT_DE_PASSE_API',
        'api_port'       => 8728,
        'hotspot_server' => 'all',
    ],
    'sms' => [
        // Inutilisé désormais, laissé vide pour éviter les erreurs
        'api_url'   => '',
        'api_key'   => '',
        'sender_id' => '',
    ],
    'admin' => [
        'token' => 'UN_TOKEN_ADMIN_SECRETE',
    ],
    'db_path'  => __DIR__ . '/data/transactions.sqlite',
    'log_path' => __DIR__ . '/data/callback.log',
    'packages' => [
        '10H' => ['label' => '10 Heures', 'price' => 100,  'profile' => '10H-100F',  'validity' => '24h'],
        '24H' => ['label' => '24 Heures', 'price' => 200,  'profile' => '24H-200F',  'validity' => '48h'],
        '7J'  => ['label' => '7 Jours',   'price' => 500,  'profile' => '7J-500F',   'validity' => '7j'],
        '30J' => ['label' => '30 Jours',  'price' => 1500, 'profile' => '30J-1500F', 'validity' => '30j'],
    ],
    'allowed_origin' => '*', // Mettez l'IP de votre passerelle hotspot en production
    'webhook_allowed_ips' => [],
];
