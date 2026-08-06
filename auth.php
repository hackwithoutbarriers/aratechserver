<?php
declare(strict_types=1);

session_start();

$config = require __DIR__ . '/config.php';

// If already logged in, carry on
if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    return;
}

// If not, redirect to login page (preserve the original requested URL)
$redirect = $_SERVER['REQUEST_URI'];
header('Location: login.php?redirect=' . urlencode($redirect));
exit;
