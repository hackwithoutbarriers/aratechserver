<?php

declare(strict_types=1);

require_once __DIR__ . '/RouterosApiClient.php';

/**
 * src/Mikrotik/RouterosClient.php
 * -----------------------------------------------------------------------
 * @deprecated INJOIGNABLE EN PRODUCTION — NE PAS BRANCHER SUR UNE ROUTE.
 *
 * Le test réel a confirmé que le MikroTik est derrière le CGNAT du FAI
 * (voir le commentaire "CGNAT CanalBox" en tête de admin/index.php).
 * Aucune connexion TCP entrante Render -> routeur:8728 n'est donc possible,
 * WireGuard ou pas : ce fichier ne peut plus jouer le rôle prévu à l'étape
 * précédente. L'architecture retenue est désormais 100% asynchrone,
 * initiée par le routeur (push HTTPS de mikrotik-scripts/push-hotspot-
 * status.rsc + file de commandes hotspot_commands consommée en pull par
 * mikrotik-scripts/hotspot-command-worker.rsc). Voir api.php pour la
 * logique réelle (queue_hotspot_command, apply_hotspot_command_ack...).
 *
 * Ce fichier est conservé (non supprimé) uniquement au cas où
 * l'architecture réseau changerait un jour (IP publique fixe, relais VPS
 * avec port forwarding...). Il ne doit être ré-activé qu'après un nouveau
 * test réseau explicite — ne pas le réintégrer par appel direct depuis une
 * page admin sans revalider cette hypothèse.
 * -----------------------------------------------------------------------
 * Couche de connexion applicative vers le routeur MikroTik, en miroir de
 * ara_db_supabase() dans db.php : une factory unique, appelée à la
 * demande, qui retourne un client prêt à l'emploi.
 * -----------------------------------------------------------------------
 */

/**
 * Retourne un client RouterOS connecté, réutilisé pour le reste de la
 * requête HTTP en cours (pas de persistance entre deux requêtes : voir
 * §1 "point de vigilance PHP" du rapport d'audit — normal et attendu en
 * PHP-Apache classique, chaque hit ouvre et referme sa propre connexion).
 *
 * @throws RuntimeException si la configuration est incomplète ou si la
 *                           connexion échoue après $connect_retries tentatives.
 */
function ara_mikrotik(array $config): RouterosApiClient
{
    static $client = null;
    static $shutdownRegistered = false;

    if ($client instanceof RouterosApiClient && $client->connected) {
        return $client;
    }

    $mikrotikConfig = $config['mikrotik'] ?? [];

    $host     = trim((string)($mikrotikConfig['host'] ?? ''));
    $user     = trim((string)($mikrotikConfig['api_user'] ?? ''));
    $password = (string)($mikrotikConfig['api_password'] ?? '');
    $port     = (int)($mikrotikConfig['api_port'] ?? 8728);
    $timeout  = max(1, (int)($mikrotikConfig['connect_timeout'] ?? 2));
    $attempts = max(1, (int)($mikrotikConfig['connect_retries'] ?? 1));

    if ($host === '' || $user === '') {
        throw new RuntimeException(
            'Configuration MikroTik incomplète (MIKROTIK_HOST / MIKROTIK_API_USER manquant(s)).'
        );
    }

    $client = new RouterosApiClient();
    $client->port     = $port;
    $client->timeout  = $timeout;
    $client->attempts = $attempts;
    $client->delay    = 1;
    $client->debug    = false;

    $connected = $client->connect($host, $user, $password);

    if (!$connected) {
        $reason = trim($client->error_str) !== ''
            ? $client->error_str
            : 'connexion refusée ou délai dépassé (identifiants invalides, API désactivée, ou routeur injoignable via le tunnel WireGuard).';
        $client = null;

        throw new RuntimeException("Connexion MikroTik impossible ($host:$port) : $reason");
    }

    if (!$shutdownRegistered) {
        // Pas de connexion persistante entre requêtes en PHP-Apache/FPM
        // classique : on referme proprement en fin de script plutôt que
        // de compter sur le garbage collector.
        register_shutdown_function(static function () use (&$client): void {
            if ($client instanceof RouterosApiClient && $client->connected) {
                $client->disconnect();
            }
        });
        $shutdownRegistered = true;
    }

    return $client;
}

/**
 * Test de liaison synchrone Render/local -> routeur, en LECTURE SEULE
 * (/system/identity/print + /system/resource/print). Ne modifie jamais
 * rien sur le routeur.
 *
 * Ne lève jamais d'exception : toute erreur (config manquante, WireGuard
 * down, identifiants API invalides, timeout...) est capturée et retournée
 * dans le tableau de résultat, pour que l'appelant (le Dashboard, plus
 * tard une route API) puisse afficher un état clair sans planter la page.
 *
 * @return array{
 *   success: bool,
 *   host: string|null,
 *   port: int|null,
 *   latency_ms: int,
 *   data?: array{
 *     identity: string|null,
 *     version: string|null,
 *     board_name: string|null,
 *     architecture: string|null,
 *     cpu_load: int|null,
 *     uptime: string|null,
 *     free_memory: int|null,
 *     total_memory: int|null,
 *   },
 *   error?: string,
 * }
 */
function ara_mikrotik_test_connection(array $config): array
{
    $startedAt = microtime(true);
    $host = $config['mikrotik']['host'] ?? null;
    $port = isset($config['mikrotik']['api_port']) ? (int)$config['mikrotik']['api_port'] : null;

    try {
        $api = ara_mikrotik($config);

        $identity = $api->comm('/system/identity/print');
        $resource = $api->comm('/system/resource/print');
        $res = $resource[0] ?? [];

        return [
            'success'    => true,
            'host'       => $host,
            'port'       => $port,
            'latency_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'data'       => [
                'identity'      => $identity[0]['name'] ?? null,
                'version'       => $res['version'] ?? null,
                'board_name'    => $res['board-name'] ?? null,
                'architecture'  => $res['architecture-name'] ?? null,
                'cpu_load'      => isset($res['cpu-load']) ? (int)$res['cpu-load'] : null,
                'uptime'        => $res['uptime'] ?? null,
                'free_memory'   => isset($res['free-memory']) ? (int)$res['free-memory'] : null,
                'total_memory'  => isset($res['total-memory']) ? (int)$res['total-memory'] : null,
            ],
        ];
    } catch (Throwable $e) {
        error_log('[MikroTik] Test de connexion échoué : ' . $e->getMessage());

        return [
            'success'    => false,
            'host'       => $host,
            'port'       => $port,
            'latency_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'error'      => $e->getMessage(),
        ];
    }
}
