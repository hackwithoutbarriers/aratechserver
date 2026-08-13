<?php
declare(strict_types=1);

/**
 * admin/includes/header.php
 * -----------------------------------------------------------------------
 * Layout partagé de toutes les pages admin/*, extrait du squelette visuel
 * du Dashboard (admin/index.php). Remplace l'ancien admin/header.php
 * (navbar + subnavbar) : nouvelle structure Sidebar + topbar.
 *
 * Contrat pour les pages appelantes :
 *   - Définir $pageTitle AVANT d'inclure ce fichier (sinon valeur par défaut).
 *   - Ne RIEN avoir déjà affiché (echo/HTML) avant l'inclusion : auth.php a
 *     besoin de pouvoir encore envoyer un header('Location: login.php')
 *     si la session n'est pas valide.
 *   - Après l'inclusion, $config (tableau de config.php) est disponible.
 *   - Ouvre <body>, la sidebar, la topbar, et
 *     <div class="container-fluid ...">   — chaque page place son contenu
 *     directement après l'include, puis referme avec includes/footer.php.
 *
 * require_once partout ici : auth.php démarre une session (session_start())
 * et une double inclusion ferait planter PHP ("session already active" /
 * redéclarations) si jamais une page incluait header.php deux fois par
 * erreur.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../auth.php';

$config = require __DIR__ . '/../../config.php';
$pageTitle = $pageTitle ?? 'ARA Tech WiFi Admin';

// Page courante, pour la classe .active du menu.
$currentPage = basename((string)($_SERVER['PHP_SELF'] ?? ''));

/**
 * Un lien de sidebar peut couvrir plusieurs fichiers (ex : "Hotspot" reste
 * actif sur ses sous-pages). $activeMap associe un identifiant de section
 * à la liste des fichiers qui doivent l'allumer.
 */
$activeMap = [
    'dashboard' => ['index.php'],
    'status'    => ['status.php'],
    'hotspot'   => ['hotspot.php', 'active-users.php', 'profiles.php', 'users.php', 'vouchers.php'],
    'inventory' => ['inventory.php'],
    'finances'  => ['finances.php'],
    'reports'   => ['reports.php'],
    'ads'       => ['ads.php'],
    'logs'      => ['logs.php'],
    'settings'  => ['settings.php'],
];

function ara_nav_active(string $section, array $activeMap, string $currentPage): string
{
    return in_array($currentPage, $activeMap[$section] ?? [], true) ? ' active' : '';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root { --bleu-nuit:#0b2c82; --bleu-nuit-dark:#081f5c; --orange:#f5a623; }
        body { background:#f4f6f9; font-family:'Segoe UI',sans-serif; overflow-x:hidden; }
        .app-shell { display:flex; min-height:100vh; }
        .sidebar { width:250px; flex-shrink:0; background:linear-gradient(180deg,var(--bleu-nuit) 0%,var(--bleu-nuit-dark) 100%); color:#fff; display:flex; flex-direction:column; }
        .sidebar-brand { font-weight:700; font-size:1.15rem; padding:1.25rem 1rem; border-bottom:3px solid var(--orange); }
        .sidebar-nav { padding:.75rem 0; flex:1; overflow-y:auto; }
        .sidebar-nav .nav-link { color:rgba(255,255,255,.85); padding:.65rem 1.1rem; font-size:.92rem; border-left:3px solid transparent; }
        .sidebar-nav .nav-link i { width:20px; margin-right:8px; color:var(--orange); }
        .sidebar-nav .nav-link:hover { background:rgba(255,255,255,.08); color:#fff; }
        .sidebar-nav .nav-link.active { background:rgba(255,255,255,.12); color:#fff; border-left-color:var(--orange); font-weight:600; }
        .sidebar-nav .nav-section-title { text-transform:uppercase; font-size:.7rem; letter-spacing:.05em; color:rgba(255,255,255,.45); padding:1rem 1.1rem .35rem; }
        .sidebar-nav .disabled-soon { color:rgba(255,255,255,.45); }
        .badge-soon { font-size:.62rem; background:rgba(245,166,35,.25); color:var(--orange); margin-left:6px; vertical-align:middle; }
        .sidebar-footer { padding:.85rem 1.1rem; border-top:1px solid rgba(255,255,255,.1); }
        .main-content { flex:1; min-width:0; }
        .topbar { background:#fff; border-bottom:1px solid #e5e7eb; padding:.85rem 1.25rem; display:flex; align-items:center; justify-content:space-between; }
        #sidebarToggle { display:none; }
        @media (max-width:991.98px) {
            .sidebar { position:fixed; left:-260px; top:0; bottom:0; z-index:1040; transition:left .2s ease; }
            .sidebar.open { left:0; }
            #sidebarToggle { display:inline-flex; }
            .sidebar-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:1030; }
            .sidebar-backdrop.open { display:block; }
        }
        .card-custom { border:none; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08); margin-bottom:1.5rem; max-width:100%; }
        .card-header-custom { background:var(--bleu-nuit); color:#fff; border-radius:12px 12px 0 0 !important; font-weight:600; }
        .stat-value { font-size:1.9rem; font-weight:700; color:var(--bleu-nuit); }
        .stat-label { font-size:.88rem; color:#6c757d; }
        .status-pill { display:inline-flex; align-items:center; gap:6px; padding:.3rem .75rem; border-radius:999px; font-weight:600; font-size:.85rem; }
        .status-pill.online { background:#d1f4dd; color:#0f7a3d; }
        .status-pill.offline { background:#fde1e1; color:#b02525; }
        .status-pill.unknown { background:#eef0f3; color:#6c757d; }
        .status-dot { width:9px; height:9px; border-radius:50%; display:inline-block; }
        .status-dot.online { background:#28a745; }
        .status-dot.offline { background:#dc3545; }
        .status-dot.unknown { background:#adb5bd; }
        .btn-orange { background:var(--orange); border:none; color:#fff; font-weight:600; border-radius:8px; }
        .btn-orange:hover { background:#e5941f; color:#fff; }
        .placeholder-zone { border:2px dashed #d7dce3; border-radius:12px; padding:2.5rem 1.5rem; text-align:center; color:#8a93a3; background:#fbfcfe; }
        .placeholder-zone i { font-size:2rem; color:#c3cad6; margin-bottom:.5rem; display:block; }
        #backToTop {
            position:fixed; bottom:30px; right:30px; display:none;
            width:48px; height:48px; border-radius:50%;
            background:var(--orange); color:#fff; border:none;
            font-size:1.5rem; cursor:pointer; box-shadow:0 4px 8px rgba(0,0,0,.2);
            transition:background .2s; z-index:1000;
        }
        #backToTop:hover { background:#e5941f; }
    </style>
</head>
<body>
<div class="app-shell">
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">⚡ ARA Tech WiFi</div>
        <nav class="sidebar-nav">
            <a class="nav-link<?= ara_nav_active('dashboard', $activeMap, $currentPage) ?>" href="index.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link<?= ara_nav_active('status', $activeMap, $currentPage) ?>" href="status.php"><i class="bi bi-wifi"></i> Statut détaillé</a>
            <a class="nav-link<?= ara_nav_active('hotspot', $activeMap, $currentPage) ?>" href="hotspot.php"><i class="bi bi-people"></i> Hotspot</a>
            <a class="nav-link<?= ara_nav_active('inventory', $activeMap, $currentPage) ?>" href="inventory.php"><i class="bi bi-box-seam"></i> Stocks &amp; Import CSV</a>
            <a class="nav-link<?= ara_nav_active('finances', $activeMap, $currentPage) ?>" href="finances.php"><i class="bi bi-cash-coin"></i> Finances</a>
            <a class="nav-link<?= ara_nav_active('reports', $activeMap, $currentPage) ?>" href="reports.php"><i class="bi bi-graph-up"></i> Rapports</a>
            <a class="nav-link<?= ara_nav_active('ads', $activeMap, $currentPage) ?>" href="ads.php"><i class="bi bi-megaphone"></i> Annonces</a>
            <a class="nav-link<?= ara_nav_active('logs', $activeMap, $currentPage) ?>" href="logs.php"><i class="bi bi-journal-text"></i> Logs</a>
            <a class="nav-link<?= ara_nav_active('settings', $activeMap, $currentPage) ?>" href="settings.php"><i class="bi bi-sliders"></i> Configuration</a>
        </nav>
        <div class="sidebar-footer">
            <a href="logout.php" class="btn btn-outline-light btn-sm w-100"><i class="bi bi-box-arrow-right"></i> Déconnexion</a>
        </div>
    </aside>

    <div class="main-content">
        <div class="topbar">
            <div class="d-flex align-items-center gap-2">
                <button id="sidebarToggle" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list"></i></button>
                <h5 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h5>
            </div>
            <span class="text-muted small">ARA Tech WiFi Admin</span>
        </div>

        <div class="container-fluid px-3 px-md-4 py-4">
        <!-- Le contenu de chaque page (cartes, tableaux, formulaires...) vient ici. -->
