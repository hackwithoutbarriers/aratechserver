<?php
declare(strict_types=1);

session_start();
$config = require __DIR__ . '/config.php';

$error = '';

// If already logged in, go straight to status (or the redirect target)
if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $redirect = $_GET['redirect'] ?? 'status.php';
    header('Location: ' . $redirect);
    exit;
}

// Handle login attempt
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = $_POST['password'] ?? '';
    if ($pass === $config['admin_password']) {
        $_SESSION['logged_in'] = true;
        $redirect = $_GET['redirect'] ?? 'status.php';
        // Sanitize redirect to prevent open redirects
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/\?\=&]+$/', $redirect)) {
            $redirect = 'status.php';
        }
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = 'Incorrect password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <style>
        body { font-family: sans-serif; max-width: 300px; margin: 50px auto; }
        input, button { width: 100%; padding: 10px; margin: 5px 0; box-sizing: border-box; }
        .error { color: red; }
    </style>
</head>
<body>
    <h2>Hotspot Admin</h2>
    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="post">
        <input type="password" name="password" placeholder="Password" required autofocus>
        <button type="submit">Login</button>
    </form>
</body>
</html>
