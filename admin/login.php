<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

$config = require __DIR__ . '/../config.php';

// Si déjà connecté, rediriger vers le tableau de bord
if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = $_POST['password'] ?? '';
    $hash = getenv('ADMIN_PASSWORD_HASH') ?: ($config['admin_password_hash'] ?? '');

    if ($hash && password_verify($pass, $hash)) {
        $_SESSION['logged_in'] = true;
        $_SESSION['regenerated'] = time();
        session_regenerate_id(true);
        header('Location: index.php');
        exit;
    }

    $error = 'Mot de passe incorrect.';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ARA Tech WiFi - Connexion</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bleu-nuit: #0b2c82;
            --orange: #f5a623;
        }
        body {
            background: linear-gradient(135deg, var(--bleu-nuit) 0%, #1a3f8f 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', sans-serif;
        }
        .login-card {
            background: #fff;
            border-radius: 16px;
            padding: 2.5rem;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }
        .login-card h3 {
            color: var(--bleu-nuit);
            font-weight: 700;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h3>ARA Tech WiFi</h3>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <div class="mb-3">
                <label class="form-label" for="password">Mot de passe</label>
                <input class="form-control" type="password" id="password" name="password" required autofocus>
            </div>
            <button class="btn btn-primary w-100" type="submit">Se connecter</button>
        </form>
    </div>
</body>
</html>
