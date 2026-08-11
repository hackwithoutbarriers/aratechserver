<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/api.php');
$allFiles = '';
foreach (glob($root . '/mikrotik-scripts/*.rsc') ?: [] as $script) { $allFiles .= file_get_contents($script) ?: ''; }
if ($api === false) {
    fwrite(STDERR, "api.php introuvable\n");
    exit(1);
}

$checks = [
    'JSON success contract exists' => strpos($api, "function json_api_success") !== false,
    'JSON error contract has code/message' => strpos($api, "'error' => ['code' => \$code, 'message' => \$message]") !== false,
    'legacy message compatibility retained' => strpos($api, "'message' => \$message") !== false,
    'wildcard CORS removed' => strpos($api, "Access-Control-Allow-Origin: *") === false,
    'allowed origin is configuration driven' => strpos($api, "allowed_origin") !== false,
    'admin header auth supported' => strpos($api, 'HTTP_X_ADMIN_TOKEN') !== false,
    'Bearer auth supported' => strpos($api, 'request_bearer_token') !== false,
    'sync key remains X-API-Key compatible' => strpos($api, 'HTTP_X_API_KEY') !== false,
    'invalid JSON rejected' => strpos($api, "INVALID_JSON") !== false,
    'prepared SQL is used for hotspot writes' => strpos($api, 'prepare(') !== false,
    'hotspot command action whitelist exists' => strpos($api, 'hotspot_allowed_actions') !== false,
    'hotspot command payload is deduplicated' => strpos($api, "UPPER(status) IN ('PENDING','PROCESSING','EXECUTED')") !== false,
    'update payload is whitelisted' => strpos($api, 'Explicit whitelist: never forward arbitrary request fields') !== false,
    'time limit validated by API' => strpos($api, 'validate_hotspot_time_limit') !== false,
    'data limit validated by API' => strpos($api, 'validate_hotspot_data_limit') !== false,
    'no direct RouterOS API include' => strpos($api, 'RouterosAPI.php') === false,
    'no direct RouterOS connect' => strpos($api, '->connect(') === false,
    'Supabase command schema is not created at runtime' => strpos($api, 'CREATE TABLE IF NOT EXISTS hotspot_commands') === false,
    'Supabase users schema is not created at runtime' => strpos($api, 'CREATE TABLE IF NOT EXISTS hotspot_users') === false,
    'Postgres snapshot schema is not altered at runtime' => strpos($api, 'if ($isPostgres)') !== false && strpos($api, 'ALTER TABLE hotspot_snapshots ADD COLUMN $col') !== false,
    'sync-users requires POST' => strpos($api, "case 'sync-users':\n        require_post_method();") !== false,
    'sync-profiles requires POST' => strpos($api, "case 'sync-profiles':\n        require_post_method();") !== false,
    'push-status requires POST' => strpos($api, "case 'push-status':\n        require_post_method();") !== false,
    'track requires POST' => strpos($api, "case 'track':\n        require_post_method();") !== false,
    'no known exposed sync key remains in scripts' => strpos($allFiles, 'lQgFtB6JyUdb72wMorus7bOdLftDwYB0uh7QzsH2BGoe8UT4joCJcYJO380EYXUcQBQZms6bC0HyJUePbWo7SEbXi1vV7fUd64yo') === false,
];

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed ? 1 : 0);
