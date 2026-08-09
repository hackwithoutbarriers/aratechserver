<?php
declare(strict_types=1);
// Ce fichier doit être inclus après auth.php et après avoir défini $pageTitle si nécessaire.
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
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; padding-bottom: 60px; overflow-x: hidden;}
        .navbar-custom { background: var(--bleu-nuit); }
        .navbar-brand { font-weight: 700; font-size: 1.3rem; color: #fff !important; }
        .subnavbar { background: #1a1e2b; border-bottom: 3px solid var(--orange); }
        .subnavbar .nav-link { color: rgba(255,255,255,0.8) !important; font-size: 0.9rem; padding: 0.5rem 0.8rem; }
        .subnavbar .nav-link:hover,
        .subnavbar .nav-link.active { color: var(--orange) !important; background: rgba(255,255,255,0.1); border-radius: 4px; }
        .subnavbar .nav-link i { margin-right: 4px; color: var(--orange); }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 1.5rem; max-width: 100%;}
        .card-header-custom { background: var(--bleu-nuit); color: #fff; border-radius: 12px 12px 0 0 !important; font-weight: 600; }
        .btn-orange { background: var(--orange); border: none; color: #fff; font-weight: 600; border-radius: 8px; }
        .btn-orange:hover { background: #e5941f; color: #fff; }
        .stat-value { font-size: 2rem; font-weight: 700; color: var(--bleu-nuit); }
        .stat-label { font-size: 0.9rem; color: #6c757d; }
        .status-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 6px; }
        .online { background: #28a745; }
        .offline { background: #dc3545; }
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
    <!-- Barre principale -->
    <nav class="navbar navbar-custom navbar-dark px-3">
        <a href="index.php" class="navbar-brand">⚡ ARA Tech WiFi Admin</a>
        <div class="ms-auto">
            <a href="logout.php" class="btn btn-outline-light btn-sm">Déconnexion</a>
        </div>
    </nav>

    <!-- Sous-menu navigation rapide (toujours visible) -->
    <div class="subnavbar py-1">
        <div class="container-fluid">
            <ul class="nav flex-wrap">
                <li class="nav-item"><a class="nav-link" href="index.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="status.php"><i class="bi bi-wifi"></i> Statut</a></li>
                <li class="nav-item"><a class="nav-link" href="hotspot.php"><i class="bi bi-people"></i> Hotspot</a></li>
                <li class="nav-item"><a class="nav-link" href="ads.php"><i class="bi bi-megaphone"></i> Annonces</a></li>
                <li class="nav-item"><a class="nav-link" href="logs.php"><i class="bi bi-journal-text"></i> Logs</a></li>
                <li class="nav-item"><a class="nav-link" href="reports.php"><i class="bi bi-graph-up"></i> Rapports</a></li>
                <li class="nav-item"><a class="nav-link" href="settings.php"><i class="bi bi-sliders"></i> Configuration</a></li>
                <li class="nav-item"><a class="nav-link" href="traffic.php"><i class="bi bi-graph-up-arrow"></i> Trafic</a></li>
            </ul>
        </div>
    </div>

    <!-- Bouton retour en haut -->
    <button id="backToTop" title="Retour en haut"><i class="bi bi-arrow-up"></i></button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
