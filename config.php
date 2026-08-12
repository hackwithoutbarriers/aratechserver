<?php
/**
 * config.php — configuration publique sans secrets.
 * Les credentials doivent être fournis par l'environnement d'exécution.
 */

return [
    'router_ip'       => getenv('MIKROTIK_HOST') ?: '192.168.88.1',
    'router_user'     => getenv('MIKROTIK_API_USER') ?: '',
    'router_password' => getenv('MIKROTIK_API_PASS') ?: '',

    'mikrotik' => [
        'host'            => getenv('MIKROTIK_HOST') ?: '192.168.88.1',
        'api_user'        => getenv('MIKROTIK_API_USER') ?: '',
        'api_password'    => getenv('MIKROTIK_API_PASS') ?: '',
        'api_port'        => (int)(getenv('MIKROTIK_API_PORT') ?: 8728),
        'hotspot_server'  => 'all',
        'connect_retries' => 1,
        'connect_timeout' => 2,
    ],

    'hotspot' => [
        'sync_key' => getenv('HOTSPOT_SYNC_KEY') ?: '',
        'command_poll_limit' => (int)(getenv('HOTSPOT_COMMAND_POLL_LIMIT') ?: 10),
        'command_processing_timeout' => (int)(getenv('HOTSPOT_COMMAND_PROCESSING_TIMEOUT') ?: 900),
    ],

    'admin' => [
        'token' => getenv('ADMIN_TOKEN') ?: '',
    ],
    'admin_password_hash' => getenv('ADMIN_PASSWORD_HASH') ?: '',

    'allowed_origin' => trim((string)(getenv('ALLOWED_ORIGIN') ?: '')),
    'debug' => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
];
