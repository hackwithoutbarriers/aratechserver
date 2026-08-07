<?php
declare(strict_types=1);
// Ce fichier doit être inclus après auth.php et après avoir défini $config si nécessaire.
// Il suppose que $config est disponible (pour le token admin si besoin), mais il ne l'utilise pas directement ici.
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'ARA Tech WiFi Admin' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root { --bleu-nuit: #0b2c82; --orange: #f5a623; }
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; padding-bottom: 60px; }
        .navbar-custom { background: var(--bleu-nuit); }
        .navbar-brand { font-weight: 700; font-size: 1.3rem; color: #fff !important; }
        .nav-link { color: rgba(255,255,255,0.85) !important; }
        .nav-link:hover { color: #fff !important; }
        .dropdown-menu { border-radius: 8px; }
        .dropdown-item i { color: var(--orange); margin-right: 6px; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 1.5rem; }
        .card-header-custom { background: var(--bleu-nuit); color: #fff; border-radius: 12px 12px 0 0 !important; font-weight: 600; }
        .btn-orange { background: var(--orange); border: none; color: #fff; font-weight: 600; border-radius: 8px; }
        .btn-orange:hover { background: #e5941f; color: #fff; }
        #backToTop {
            position: fixed; bottom: 30px; right: 30px; display: none;
            width: 48px; height: 48px; border-radius: 50%;
            background: var(--orange); color: #fff; border: none;
            font-size: 1.5rem; cursor: pointer; box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            transition: background 0.2s; z-index: 1000;
        }
        #backToTop:hover { background: #e5941f; }
    </style>
</head>
<body>
    <!-- Barre de navigation avec dropdown Navigation rapide -->
    <nav class="navbar navbar-custom navbar-dark navbar-expand-lg px-3">
        <a href="index.php" class="navbar-brand">⚡ ARA Tech WiFi Admin</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-compass"></i> Navigation rapide
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="status.php"><i class="bi bi-wifi"></i> Statut Hotspot</a></li>
                        <li><a class="dropdown-item" href="ads.php"><i class="bi bi-megaphone"></i> Annonces</a></li>
                        <li><a class="dropdown-item" href="logs.php"><i class="bi bi-journal-text"></i> Logs</a></li>
                        <li><a class="dropdown-item" href="users.php"><i class="bi bi-people"></i> Utilisateurs</a></li>
                        <li><a class="dropdown-item" href="reports.php"><i class="bi bi-graph-up"></i> Rapports</a></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="bi bi-sliders"></i> Configuration</a></li>
                    </ul>
                </li>
            </ul>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Déconnexion</a>
        </div>
    </nav>

    <!-- Bouton retour en haut -->
    <button id="backToTop" title="Retour en haut"><i class="bi bi-arrow-up"></i></button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gestion du bouton "retour en haut"
        const backToTopBtn = document.getElementById('backToTop');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 300) {
                backToTopBtn.style.display = 'block';
            } else {
                backToTopBtn.style.display = 'none';
            }
        });
        backToTopBtn.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    </script>
