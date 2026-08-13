<?php
declare(strict_types=1);

/**
 * ARA Tech Admin — shared layout header.
 * Auth is centralized here.
 */
require_once __DIR__ . '/../auth.php';

$currentPage = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$pageTitle = $pageTitle ?? 'ARA Tech WiFi Admin';

$navItems = [
    'index.php'     => ['label' => 'Dashboard',        'icon' => 'bi-speedometer2'],
    'status.php'    => ['label' => 'Statut détaillé',  'icon' => 'bi-wifi'],
    'hotspot.php'   => ['label' => 'Hotspot',          'icon' => 'bi-people'],
    'inventory.php' => ['label' => 'Stocks & Import CSV','icon' => 'bi-box-seam'],
    'finances.php' => ['label' => 'Finances',          'icon' => 'bi-cash-coin'],
    'reports.php'   => ['label' => 'Rapports',         'icon' => 'bi-graph-up'],
    'ads.php'       => ['label' => 'Annonces',         'icon' => 'bi-megaphone'],
    'logs.php'      => ['label' => 'Logs',             'icon' => 'bi-journal-text'],
    'settings.php'  => ['label' => 'Configuration',    'icon' => 'bi-sliders'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars((string)$pageTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

    <?php if (!empty($headExtra ?? '')): ?>
        <?= $headExtra ?>
    <?php endif; ?>

    <style>
        :root {
            --bleu-nuit: #0b2c82;
            --bleu-nuit-dark: #081f5c;
            --orange: #f5a623;
            --page-bg: #f4f6f9;
        }
        body { background: var(--page-bg); font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .app-shell { display:flex; min-height:100vh; }
        .sidebar { width:250px; flex-shrink:0; background:linear-gradient(180deg,var(--bleu-nuit) 0%,var(--bleu-nuit-dark) 100%); color:#fff; display:flex; flex-direction:column; }
        .sidebar-brand { font-weight:700; font-size:1.15rem; padding:1.25rem 1rem; border-bottom:3px solid var(--orange); }
        .sidebar-nav { padding:.75rem 0; flex:1; overflow-y:auto; }
        .sidebar-nav .nav-link { color:rgba(255,255,255,.85); padding:.65rem 1.1rem; font-size:.92rem; border-left:3px solid transparent; }
        .sidebar-nav .nav-link i { width:20px; margin-right:8px; color:var(--orange); }
        .sidebar-nav .nav-link:hover { background:rgba(255,255,255,.08); color:#fff; }
        .sidebar-nav .nav-link.active { background:rgba(255,255,255,.12); color:#fff; border-left-color:var(--orange); font-weight:600; }
        .sidebar-nav .nav-link.active i { color:#fff; }
        .sidebar-nav .nav-section-title { text-transform:uppercase; font-size:.7rem; letter-spacing:.05em; color:rgba(255,255,255,.45); padding:1rem 1.1rem .35rem; }
        .sidebar-footer { padding:.85rem 1.1rem; border-top:1px solid rgba(255,255,255,.1); }
        .main-content { flex:1; min-width:0; }
        .topbar { background:#fff; border-bottom:1px solid #e5e7eb; padding:.85rem 1.25rem; display:flex; align-items:center; justify-content:space-between; gap:1rem; }
        #sidebarToggle { display:none; }
        .page-content { padding:1rem .75rem 2rem; }
        .card-custom { border:none; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08); margin-bottom:1.5rem; }
        .card-header-custom { background:var(--bleu-nuit); color:#fff; border-radius:12px 12px 0 0 !important; font-weight:600; }
        .stat-value { font-size:1.9rem; font-weight:700; color:var(--bleu-nuit); }
        .stat-label { font-size:.88rem; color:#6c757d; }
        .btn-orange { background:var(--orange); border:none; color:#fff; font-weight:600; }
        .btn-orange:hover { background:#e5941f; color:#fff; }
        .app-footer { color:#8a93a3; font-size:.8rem; padding:0 1rem 1.5rem; }
        @media (max-width:991.98px) {
            .sidebar { position:fixed; left:-260px; top:0; bottom:0; z-index:1040; transition:left .2s ease; }
            .sidebar.open { left:0; }
            #sidebarToggle { display:inline-flex; }
            .sidebar-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:1030; }
            .sidebar-backdrop.open { display:block; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">⚡ ARA Tech WiFi</div>

        <nav class="sidebar-nav" aria-label="Navigation administration">
            <?php foreach ($navItems as $href => $item): ?>
                <a class="nav-link <?= $currentPage === $href ? 'active' : '' ?>"
                   href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
                   <?= $currentPage === $href ? 'aria-current="page"' : '' ?>>
                    <i class="bi <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                    <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>

            <div class="nav-section-title">À venir</div>
            <a class="nav-link disabled-soon" href="#business-section">
                <i class="bi bi-briefcase"></i> Gestion Business
                <span class="badge bg-warning text-dark ms-1">Bientôt</span>
            </a>
            <a class="nav-link disabled-soon" href="#wifizone-section">
                <i class="bi bi-router"></i> WiFi Zone
                <span class="badge bg-warning text-dark ms-1">Bientôt</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="logout.php" class="btn btn-outline-light btn-sm w-100">
                <i class="bi bi-box-arrow-right"></i> Déconnexion
            </a>
        </div>
    </aside>

    <div class="main-content">
        <div class="topbar">
            <div class="d-flex align-items-center gap-2">
                <button id="sidebarToggle" type="button" class="btn btn-outline-secondary btn-sm"
                        aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="sidebar">
                    <i class="bi bi-list"></i>
                </button>
                <h5 class="mb-0"><?= htmlspecialchars((string)$pageTitle, ENT_QUOTES, 'UTF-8') ?></h5>
            </div>
            <span class="text-muted small d-none d-md-inline">ARA Tech WiFi Admin</span>
        </div>

        <main class="page-content">
