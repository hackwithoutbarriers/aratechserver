<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/api.php');
$sync = file_get_contents($root . '/mikrotik-scripts/sync-users-supabase.rsc');
$worker = file_get_contents($root . '/mikrotik-scripts/hotspot-command-worker.rsc');
if ($api === false || $sync === false || $worker === false) {
    fwrite(STDERR, "Fichier Phase 3 introuvable\n");
    exit(1);
}

$checks = [
    'mapping limit-uptime API' => strpos($api, 'limit_uptime') !== false,
    'mapping limit-bytes-total API' => strpos($api, 'limit_bytes_total') !== false,
    'mapping last_sync users' => strpos($api, 'last_sync') !== false,
    'create queue avant mutation miroir' => strpos($api, "queue_hotspot_command(\$config, 'create'") !== false,
    'update queue avant mutation miroir' => strpos($api, "queue_hotspot_command(\$config, 'update'") !== false,
    'enable/disable queue' => strpos($api, "queue_hotspot_command(\$config, \$action") !== false,
    'delete queue' => strpos($api, "queue_hotspot_command(\$config, 'delete'") !== false,
    'ACK applique l etat confirme' => strpos($api, 'apply_hotspot_command_ack') !== false,
    'worker supporte limit-uptime' => strpos($worker, 'limit-uptime=') !== false,
    'worker supporte limit-bytes-total' => strpos($worker, 'limit-bytes-total=') !== false,
    'sync script envoie limit-uptime' => strpos($sync, '"limit-uptime"') !== false,
    'sync script envoie limit-bytes-total' => strpos($sync, '"limit-bytes-total"') !== false,
    'worker actions obligatoires' => strpos($worker, '"create"') !== false && strpos($worker, '"update"') !== false
        && strpos($worker, '"enable"') !== false && strpos($worker, '"disable"') !== false
        && strpos($worker, '"delete"') !== false,
];

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed ? 1 : 0);
