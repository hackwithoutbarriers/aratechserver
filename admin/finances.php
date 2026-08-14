<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/legacy-redirect.php';

$query = ['tab' => 'finances'];
foreach (['periode'] as $key) {
    if (isset($_GET[$key]) && $_GET[$key] !== '') $query[$key] = (string)$_GET[$key];
}

ara_legacy_redirect('business.php', $query);
