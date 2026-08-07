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
    'router_ip'       => '192.168.88.1',  
    'router_user'     => 'admin',          
    'router_password' => '04011997',
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
        // Nouvelle valeur : l'ancienne avait été partagée en clair dans le chat.
        'token' => 'c8739df7488d9dc34e31b4c103949f289bc69f9a571b96ee42543abbc265d8bc',
    ],

    // ================= Stockage local (SQLite) =================
    'db_path'  => __DIR__ . '/data/transactions.sqlite', // Base de données SQLite
    'log_path' => __DIR__ . '/data/app.log',            // Fichier de logs

    // ================= CORS (pour autoriser le routeur/status.html à appeler l'API) =================
    // '*' : la route expiry ne renvoie qu'une date (rien de sensible), et l'IP exacte
    // de la passerelle hotspot peut varier selon le réseau/profil — plus fiable qu'une
    // valeur figée qui casse silencieusement le fetch() côté navigateur si elle ne
    // correspond pas exactement à l'origine réelle de status.html.
    'allowed_origin' => '*',


    // ================= Turso (logs persistants) =================
    // Base SQLite distribuée — survit aux redémarrages Render.
    // URL  : depuis le dashboard Turso → votre base → Overview → "URL"
    //        (remplacer libsql:// par https://)
    // Token: depuis Turso → votre base → Tokens → "Create Token" (Read & Write)
    "turso" => [
        "url"   => "https://ara-tech-logs-hackwithoutbarriers.aws-us-east-1.turso.io",
        "token" => "eyJhbGciOiJFZERTQSIsInR5cCI6IkpXVCJ9.eyJhIjoicnciLCJpYXQiOjE3ODYwNTIyNTIsImlkIjoiMDE5ZmQ5MDEtMDIwMS03Mzc3LTllMzAtOTNjNDM0MTNmOWNmIiwia2lkIjoiU0FLQlFuMHY2NWprX3h3V0ZmRjBHYWk0LXFiQUprLVV0M0FKQlhBaEFpYyIsInJpZCI6IjJhYzkzZWEyLTM0NGQtNDRkNy05YzlmLTI2YTBlMDExZmI1MiJ9.gzggCfZ28-JXzNiXL7nE75H86CpIgoqOWiewnpPOBV_LCuBouNqkpksTrXT5XLsrq-ci_-YFGAjBuebnMFI3CA",
    ],

    // ================= Debug (à désactiver en production) =================
    'debug' => false,
];
