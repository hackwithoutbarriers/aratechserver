<?php
/**
 * config.example.php — ARA Tech / Configuration paiement Mobile Money
 * ------------------------------------------------------------------
 * 1) Copiez ce fichier en "config.php" (à côté des autres scripts).
 * 2) Remplissez les valeurs marquées <-- À REMPLACER.
 * 3) NE JAMAIS committer config.php (avec vos vraies clés) dans un
 *    dépôt public. Ajoutez-le à votre .gitignore.
 *
 * RECOMMANDATION SÉCURITÉ :
 * Idéalement, placez config.php HORS du dossier accessible par le web.
 * Structure recommandée :
 *    /home/user/
 *    ├── public_html/                    (web root)
 *    │   ├── pay.php                     (require '../config.php')
 *    │   ├── status.php
 *    │   ├── callback.php
 *    │   └── .htaccess
 *    └── config.php                      (parent directory, NOT in web root)
 *
 * Cela empêche tout accès direct accidentel via HTTP, même si .htaccess échoue.
 * Si votre hébergeur n'autorise pas cela, utilisez au minimum le blocage .htaccess fourni.
 */

return [

    // ================= PayGate Global =================
    'paygate' => [
        // Jeton d'authentification trouvé dans votre tableau de bord PayGate
        // ("Guide d'intégration" -> clé API).
        'auth_token'     => 'VOTRE_AUTH_TOKEN_PAYGATE_GLOBAL', // <-- À REMPLACER

        // Base des URLs de l'API. Le chemin exact de l'endpoint de paiement
        // (pay_endpoint) est indiqué dans votre "Guide d'intégration" -
        // vérifiez-le à l'activation de votre compte, il peut différer.
        'base_url'       => 'https://paygateglobal.com/api/v1',
        'pay_endpoint'   => '/pay',

        // URL de callback (webhook) que PayGate doit appeler après paiement.
        // Doit être déclarée aussi dans votre tableau de bord PayGate.
        'callback_url'   => 'https://aratech.onrender.com/callback.php', // <-- À REMPLACER

        // Secret utilisé pour vérifier la signature du webhook (OBLIGATOIRE pour la sécurité).
        // Récupérez cette valeur dans votre tableau de bord PayGate Global.
        // Si PayGate ne fournit pas de secret, contactez leur support (sinon le système reste vulnérable).
        'webhook_secret' => 'VOTRE_WEBHOOK_SECRET_PAYGATE', // <-- À REMPLACER - NE DOIT PAS ÊTRE VIDE EN PRODUCTION
    ],

    // ================= MikroTik / Mikhmon =================
    'mikrotik' => [
        'host'           => '192.168.88.1',            // <-- IP du routeur
        'api_user'       => 'api-hotspot',              // <-- utilisateur API dédié (PAS 'admin' — voir README sécurité)
        'api_password'   => 'VOTRE_MOT_DE_PASSE_API',   // <-- À REMPLACER
        'api_port'       => 8728,                       // 8729 si API-SSL activée
        // Nom du serveur hotspot RouterOS auquel rattacher l'utilisateur.
        // 'all' fonctionne si vous n'avez qu'un seul serveur hotspot.
        'hotspot_server' => 'all',
    ],

    // ================= Passerelle SMS =================
    // Remplissez avec les paramètres réels de votre fournisseur SMS
    // (Txtlocal, Africa's Talking, Orange SMS API, etc. selon disponibilité au Togo).
    'sms' => [
        'api_url'   => 'https://api.votre-sms-gateway.com/send', // <-- À REMPLACER
        'api_key'   => 'VOTRE_CLE_API_SMS',                      // <-- À REMPLACER
        'sender_id' => 'ARATECH',                                // <-- Nom d'expéditeur si votre passerelle le supporte
    ],

    // ================= Admin local pour mise à jour des annonces et fidélité =================
    'admin' => [
        'token' => 'VOTRE_TOKEN_ADMIN', // <-- À REMPLACER par une valeur longue et secrète
    ],

    // ================= Stockage local (SQLite - aucun serveur de BDD requis) =================
    'db_path'  => __DIR__ . '/data/transactions.sqlite',
    'log_path' => __DIR__ . '/data/callback.log',

    // ================= Catalogue des forfaits =================
    // SOURCE DE VÉRITÉ CÔTÉ SERVEUR : ne jamais faire confiance à un prix
    // envoyé par le navigateur du client. 'profile' doit correspondre
    // EXACTEMENT au nom du profil utilisateur configuré dans Mikhmon/RouterOS
    // (Hotspot > User Profiles).
    'packages' => [
        '10H' => ['label' => '10 Heures', 'price' => 100,  'profile' => '10H-100F',  'validity' => '24h'],
        '24H' => ['label' => '24 Heures', 'price' => 200,  'profile' => '24H-200F',  'validity' => '48h'],
        '7J'  => ['label' => '7 Jours',   'price' => 500,  'profile' => '7J-500F',   'validity' => '7j'],
        '30J' => ['label' => '30 Jours',  'price' => 1500, 'profile' => '30J-1500F', 'validity' => '30j'],
    ],

    // CORS: Domaine(s) autorisé(s) à appeler pay.php / status.php.
    // IMPORTANT : Cette valeur doit correspondre à l'IP/domaine du routeur hotspot.
    // Par défaut, 10.10.0.1 est la passerelle hotspot (configurée dans mikrotikConfigscript.rsc).
    // Mettez '*' SEULEMENT en phase de test/développement.
    'allowed_origin' => 'http://10.10.0.1', // Hotspot gateway (mise à jour depuis '*')

    // Adresses IP autorisées à appeler callback.php (webhook PayGate Global).
    // Récupérez les IPs des serveurs webhook de PayGate Global auprès de leur support.
    // Laissez vide pour désactiver cette vérification (moins sûr), mais signature reste obligatoire.
    'webhook_allowed_ips' => [
        // '1.2.3.4',  // Exemple : IP du serveur webhook PayGate Global
        // '5.6.7.8',  // Autre IP redondante de PayGate
    ],
    // À ajouter dans le tableau retourné
'debug' => false,                             // true pour logs détaillés
'maintenance' => [
    'retention_days' => 90,                   // suppression des transactions > 90 jours
    'log_rotation_size' => 10485760,          // 10 Mo
],
'sms' => [
    'retry_attempts' => 3,
    'backoff_base'   => 1,                    // secondes (exponentiel)
],
'mikrotik' => [
    'connect_retries' => 3,
    'connect_timeout' => 10,
    'api_port'        => 8728,                // 8729 pour SSL
],
];

