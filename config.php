<?php
/**
 * config.php — Configuration finale du backend ARA Tech Wi-Fi Zone
 * ------------------------------------------------------------------
 * Version allégée : pas de paiement en ligne, pas de fidélité.
 * Utilisé uniquement pour :
 *   - api.php (routes ads, expiry, set-expiry, track, admin)
 *   - Connexion à l'API MikroTik pour la route expiry (repli, optionnelle)
 *   - Stockage des annonces et statistiques (SQLite)
 */

return [

    // ================= MikroTik (repli de la route expiry) =================
    // ATTENTION : 192.168.88.1 est une IP privée du réseau de gestion,
    // injoignable depuis Render (Internet). Ce bloc ne sert donc en
    // pratique QUE si vous mettez un jour en place un VPN ou une IP
    // publique/redirection de port vers le routeur. En attendant, la
    // route expiry s'appuie en priorité sur hotspot_expiry (alimentée
    // par le script on-login via set-expiry) — ce repli ici échouera
    // systématiquement, d'où un timeout volontairement court pour ne
    // pas ralentir inutilement les requêtes.
    'mikrotik' => [
        'host'            => '192.168.88.1',          // IP du routeur (réseau de gestion)
        'api_user'        => 'api-hotspot',            // Utilisateur API (peut être désactivé)
        'api_password'    => 'Dieu100%',                // Mot de passe de l'utilisateur API — à changer, exposé dans le chat
        'api_port'        => 8728,                     // Port API binaire
        'hotspot_server'  => 'all',                    // Nom du serveur hotspot ('all' ou nom exact)
        'connect_retries' => 1,                        // Réduit de 3 à 1 : ce repli échoue toujours depuis Render
        'connect_timeout' => 2,                         // Réduit de 10 à 2 : échoue vite plutôt que de bloquer ~30s
    ],

    // ================= Hotspot (synchronisation date d'expiration) =================
    // Clé partagée avec le script on-login du routeur (en-tête X-API-Key
    // sur la route set-expiry). Ne JAMAIS exposer côté navigateur/status.html.
    'hotspot' => [
        'sync_key' => 'e6f5420f329e00c464e04ac79d20776411c7e2286bafd79aa1dc3d24427c1117',
    ],

    // ================= Administration (pour gérer les annonces) =================
    'admin' => [
        // Token utilisé pour les routes admin (protégées par ?token=...)
        'token' => 'c8739df7488d9dc34e31b4c103949f289bc69f9a571b96ee42543abbc265d8bc',
    ],

    // ================= Stockage local (SQLite) =================
    'db_path'  => __DIR__ . '/data/transactions.sqlite', // Base de données SQLite
    'log_path' => __DIR__ . '/data/app.log',            // Fichier de logs

    // ================= CORS (pour autoriser le routeur à appeler l'API) =================
    // Ne concerne que les appels faits par un navigateur (status.html) ;
    // n'a aucun effet sur set-expiry, appelée par /tool fetch (pas un
    // navigateur) — c'est pour ça que set-expiry est protégée par sync_key
    // et non par cette valeur.
    'allowed_origin' => 'http://10.10.0.1', // IP de la passerelle hotspot

    // ================= Debug (à désactiver en production) =================
    'debug' => false,
];
