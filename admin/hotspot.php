<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/legacy-redirect.php';

$tab = $_GET['tab'] ?? 'users';
if (!in_array($tab, ['users', 'active', 'vouchers', 'profiles'], true)) {
    $tab = 'users';
}

ara_legacy_redirect('operations.php', [
    'tab' => 'hotspot',
    'legacy_tab' => $tab,
]);
