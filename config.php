<?php
/**
 * config.php — Configuration complète ARA Tech avec Mikhmon
 * ------------------------------------------------------------------
 * 1) Copiez ce fichier depuis config.example.php et remplissez les valeurs
 * 2) NE JAMAIS committer ce fichier avec vos vraies clés
 */

return [

    // ================= PayGate Global =================
    'paygate' => [
        'auth_token'     => 'VOTRE_AUTH_TOKEN_PAYGATE_GLOBAL', // <-- À REMPLACER
        'base_url'       => 'https://paygateglobal.com/api/v1',
        'pay_endpoint'   => '/pay',
        'callback_url'   => 'https://aratech.onrender.com/callback.php', // <-- À REMPLACER
        'webhook_secret' => 'VOTRE_WEBHOOK_SECRET_PAYGATE', // <-- À REMPLACER
    ],

    // ================= MikroTik / Mikhmon =================
'mikrotik' => [
    'host'           => '192.168.1.0',      // L'IP réelle du routeur (à confirmer avec /ip address print)
    'api_user'       => 'api-hotspot',       // L'utilisateur créé
    'api_password'   => 'Dieu100%',          // Le mot de passe que vous avez défini
    'api_port'       => 8728,                // Port standard de l'API binaire
    'hotspot_server' => 'all',               // D'après vos utilisateurs, c'est 'all'
    'connect_retries' => 3,
    'connect_timeout' => 10,
],

    // ================= Mikhmon (gestion des utilisateurs) =================
    'mikhmon' => [
        'base_url' => 'https://votre-domaine-mikhmon.com', // <-- URL de Mikhmon
        'api_key'  => 'VOTRE_API_KEY_MIKHMON',             // <-- Si Mikhmon expose une API
        // Sinon, on utilise l'API RouterOS directement (recommandé)
        'use_api'  => true, // true = RouterOS API directe, false = API Mikhmon
    ],

    // ================= Passerelle SMS =================
    'sms' => [
        'api_url'   => 'https://api.votre-sms-gateway.com/send', // <-- À REMPLACER
        'api_key'   => 'VOTRE_CLE_API_SMS',                      // <-- À REMPLACER
        'sender_id' => 'ARATECH',
        'retry_attempts' => 3,
        'backoff_base'   => 1,
    ],

    // ================= Admin =================
    'admin' => [
        'token' => 'VOTRE_TOKEN_ADMIN_SUPER_SECURISE', // <-- À REMPLACER
    ],

    // ================= Stockage =================
    'db_path'  => __DIR__ . '/data/transactions.sqlite',
    'log_path' => __DIR__ . '/data/callback.log',

    // ================= Catalogue des forfaits =================
    // IMPORTANT : les 'profile' doivent correspondre EXACTEMENT aux profils
    // configurés dans Mikhmon / RouterOS (Hotspot > User Profiles)
    'packages' => [
        '10H' => [
            'label'    => '10 Heures',
            'price'    => 100,
            'profile'  => '10H-100F',   // Nom du profil dans Mikhmon
            'validity' => '24h'
        ],
        '24H' => [
            'label'    => '24 Heures',
            'price'    => 200,
            'profile'  => '24H-200F',
            'validity' => '48h'
        ],
        '7J' => [
            'label'    => '7 Jours',
            'price'    => 500,
            'profile'  => '7J-500F',
            'validity' => '7j'
        ],
        '30J' => [
            'label'    => '30 Jours',
            'price'    => 1500,
            'profile'  => '30J-1500F',
            'validity' => '30j'
        ],
    ],

    // ================= CORS et sécurité =================
    'allowed_origin' => 'http://10.10.0.1', // IP de la passerelle hotspot

    // IP autorisées pour le webhook (PayGate)
    'webhook_allowed_ips' => [
        // '1.2.3.4', // IP du serveur PayGate
    ],

    // ================= Debug =================
    'debug' => false,

    // ================= Maintenance =================
    'maintenance' => [
        'retention_days' => 90,
        'log_rotation_size' => 10485760, // 10 Mo
    ],

    // ================= Chiffrement =================
    'encryption_key' => 'UNE_CLE_DE_32_OCTETS_POUR_AES256', // <-- À REMPLACER
];
