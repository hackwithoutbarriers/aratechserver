<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/legacy-redirect.php';
ara_legacy_redirect('business.php', ['tab' => 'ads']);
