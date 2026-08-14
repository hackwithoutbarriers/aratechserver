<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/legacy-redirect.php';

$query = ['tab' => 'reports'];
foreach (['start', 'end', 'start_date', 'end_date'] as $key) {
    if (isset($_GET[$key]) && $_GET[$key] !== '') $query[$key] = (string)$_GET[$key];
}

ara_legacy_redirect('business.php', $query);
