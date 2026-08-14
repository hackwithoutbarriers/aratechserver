<?php
declare(strict_types=1);

/**
 * admin/auth.php — Garde de session unique pour toutes les pages admin.
 *
 * Important: certains appels de pages/partials peuvent inclure ce fichier
 * alors qu'une session est déjà ouverte. Les paramètres du cookie ne peuvent
 * être modifiés dans cet état ; on ne les applique donc qu'avant toute
 * session active et on n'appelle session_start() qu'une seule fois.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}

require_once __DIR__ . '/../config.php';

if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Régénère l'ID de session toutes les 5 minutes pour limiter la fenêtre
// d'exploitation d'une éventuelle fixation/fuite de session.
if (!isset($_SESSION['regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['regenerated'] = time();
} elseif (time() - (int)$_SESSION['regenerated'] > 300) {
    session_regenerate_id(true);
    $_SESSION['regenerated'] = time();
}
