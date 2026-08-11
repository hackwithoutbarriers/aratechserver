<?php
declare(strict_types=1);

$api = file_get_contents(__DIR__ . '/../api.php');
if ($api === false) {
    fwrite(STDERR, "api.php introuvable\n");
    exit(1);
}

$start = strpos($api, "case 'sync-users':");
$end = strpos($api, "case 'sync-profiles':", $start);
if ($start === false || $end === false) {
    fwrite(STDERR, "Route sync-users introuvable\n");
    exit(1);
}
$block = substr($api, $start, $end - $start);

$assertions = [
    'sync-users ne supprime pas le miroir' => strpos($block, 'DELETE FROM hotspot_users') === false,
    'sync-users utilise le schéma Phase 2 sans CREATE TABLE' => strpos($block, 'CREATE TABLE IF NOT EXISTS hotspot_users') === false,
    'sync-users normalise les limites' => strpos($api, "'limit_uptime'") !== false && strpos($api, "'limit_bytes_total'") !== false,
    'sync-users fait un UPSERT par username' => strpos($api, 'ON CONFLICT (username) DO UPDATE SET') !== false,
    'sync-users renseigne last_sync' => strpos($api, 'last_sync') !== false,
    'sync-profiles renseigne last_sync' => strpos($api, 'ON CONFLICT (profile_name) DO UPDATE SET') !== false && strpos($api, 'last_sync = EXCLUDED.last_sync') !== false,
    'les mutations web ne mettent pas a jour le miroir avant ACK' => strpos($api, 'UPDATE hotspot_users SET disabled = ? WHERE username = ?') === false,
];


$failed = 0;
foreach ($assertions as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) $failed++;
}

exit($failed > 0 ? 1 : 0);
