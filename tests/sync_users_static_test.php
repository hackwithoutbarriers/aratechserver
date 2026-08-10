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
    'sync-users ne vide pas hotspot_users' => strpos($block, 'DELETE FROM hotspot_users') === false,
    'sync-users prépare le schéma utilisateur' => strpos($block, 'ensure_hotspot_users_table($pdo)') !== false,
    'sync-users fait un traitement idempotent par utilisateur' => strpos($block, 'upsert_hotspot_sync_user($pdo, $user)') !== false,
    'sync-users journalise le volume reçu/traité' => strpos($block, "sync-users: received=") !== false,
];

$failed = 0;
foreach ($assertions as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) $failed++;
}

exit($failed > 0 ? 1 : 0);
