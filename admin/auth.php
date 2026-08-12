<?php
declare(strict_types=1);

/**
 * admin/auth.php — Garde de session, à inclure en tête de chaque page admin.
 * ---------------------------------------------------------------------------
 * Fichier unique (avant la restructuration, ce garde existait en double avec
 * des comportements légèrement différents selon la copie ; voir l'audit de
 * restructuration). Cette version est la version corrigée :
 *   - cookie de session strict (httponly, secure, SameSite=Strict) ;
 *   - redirection vers login.php si non authentifié ;
 *   - régénération périodique de l'ID de session (anti session-fixation),
 *     avec initialisation correcte au premier accès authentifié.
 */

session_set_cookie_params([
    'lifetime' => 3600,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

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
} elseif (time() - $_SESSION['regenerated'] > 300) {
    session_regenerate_id(true);
    $_SESSION['regenerated'] = time();
}
