<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/legacy-redirect.php';

$query = ['tab' => 'logs'];
foreach (['date', 'topic', 'search', 'page', 'level', 'start', 'end'] as $key) {
    if (isset($_GET[$key]) && $_GET[$key] !== '') $query[$key] = (string)$_GET[$key];
}

ara_legacy_redirect('monitoring.php', $query);
