<?php
declare(strict_types=1);

if (!empty($_GET['embed'])) {
    return;
}

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$pageTitle = $pageTitle ?? 'ARA Tech WiFi Admin';
function ara_nav_active(array|string $pages, string $currentPage): string { return in_array($currentPage, (array)$pages, true) ? ' active' : ''; }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root{--bleu-nuit:#0b2c82;--bleu-nuit-dark:#081f5c;--orange:#f5a623}
body{background:#f4f6f9;font-family:'Segoe UI',sans-serif;overflow-x:hidden}.app-shell{display:flex;min-height:100vh}.sidebar{width:250px;flex-shrink:0;background:linear-gradient(180deg,var(--bleu-nuit) 0%,var(--bleu-nuit-dark) 100%);color:#fff;display:flex;flex-direction:column}.sidebar-brand{font-weight:700;font-size:1.15rem;padding:1.25rem 1rem;border-bottom:3px solid var(--orange)}.sidebar-nav{padding:.75rem 0;flex:1;overflow-y:auto}.sidebar-nav .nav-link{color:rgba(255,255,255,.85);padding:.65rem 1.1rem;font-size:.92rem;border-left:3px solid transparent;display:block}.sidebar-nav .nav-link i{width:20px;margin-right:8px;color:var(--orange)}.sidebar-nav .nav-link:hover{background:rgba(255,255,255,.08);color:#fff}.sidebar-nav .nav-link.active{background:rgba(255,255,255,.12);color:#fff;border-left-color:var(--orange);font-weight:600}.sidebar-footer{padding:.85rem 1.1rem;border-top:1px solid rgba(255,255,255,.1)}.main-content{flex:1;min-width:0}.topbar{background:#fff;border-bottom:1px solid #e5e7eb;padding:.85rem 1.25rem;display:flex;align-items:center;justify-content:space-between}#sidebarToggle{display:none}.card-custom{border:none;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:1.5rem}.card-header-custom{background:var(--bleu-nuit);color:#fff;border-radius:12px 12px 0 0!important;font-weight:600}.stat-value{font-size:1.9rem;font-weight:700;color:var(--bleu-nuit)}.stat-label{font-size:.88rem;color:#6c757d}.metric-card .metric-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:#eef3ff;color:var(--bleu-nuit);font-size:1.15rem;flex:0 0 42px}.metric-card-warning .metric-icon{background:#fff4df;color:#a66600}.metric-card-success .metric-icon{background:#e6f7ed;color:#198754}.metric-card-danger .metric-icon{background:#fdebec;color:#b02a37}.metric-card-info .metric-icon{background:#e8f5ff;color:#0d6efd}.status-pill{display:inline-flex;align-items:center;gap:6px;padding:.3rem .75rem;border-radius:999px;font-weight:600;font-size:.85rem}.status-pill.online{background:#d1f4dd;color:#0f7a3d}.status-pill.offline{background:#fde1e1;color:#b02525}.status-pill.unknown{background:#eef0f3;color:#6c757d}.status-pill.warning{background:#fff3cd;color:#997404}.status-dot{width:9px;height:9px;border-radius:50%;display:inline-block}.status-dot.online{background:#28a745}.status-dot.offline{background:#dc3545}.status-dot.unknown{background:#adb5bd}.status-dot.warning{background:#f5a623}.btn-orange{background:var(--orange);border:none;color:#fff;font-weight:600;border-radius:8px}.btn-orange:hover{background:#e5941f;color:#fff}.placeholder-zone{border:2px dashed #d7dce3;border-radius:12px;padding:2.5rem 1.5rem;text-align:center;color:#8a93a3;background:#fbfcfe}.placeholder-zone i{font-size:2rem;color:#c3cad6;margin-bottom:.5rem;display:block}.table thead th{white-space:nowrap;font-size:.8rem;text-transform:uppercase;letter-spacing:.02em;color:#5f6b7a;background:#f8f9fb}.data-table-shell{border:1px solid #e7ebf0;border-radius:12px;overflow:hidden;background:#fff}#backToTop{position:fixed;bottom:30px;right:30px;display:none;width:48px;height:48px;border-radius:50%;background:var(--orange);color:#fff;border:none;font-size:1.5rem;cursor:pointer;box-shadow:0 4px 8px rgba(0,0,0,.2);z-index:1000}
@media(max-width:991.98px){.sidebar{position:fixed;left:-260px;top:0;bottom:0;z-index:1040;transition:left .2s ease}.sidebar.open{left:0}#sidebarToggle{display:inline-flex}.sidebar-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:1030}.sidebar-backdrop.open{display:block}}
</style>
</head>
<body>
<div class="app-shell">
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<aside class="sidebar" id="sidebar"><div class="sidebar-brand">⚡ ARA Tech WiFi</div><nav class="sidebar-nav">
<a class="nav-link<?= ara_nav_active('index.php',$currentPage) ?>" href="index.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
<a class="nav-link<?= ara_nav_active(['monitoring.php','status.php','logs.php'],$currentPage) ?>" href="monitoring.php"><i class="bi bi-activity"></i> System &amp; Monitoring</a>
<a class="nav-link<?= ara_nav_active(['operations.php','hotspot.php','inventory.php'],$currentPage) ?>" href="operations.php"><i class="bi bi-router"></i> Opérations</a>
<a class="nav-link<?= ara_nav_active(['business.php','finances.php','reports.php','ads.php'],$currentPage) ?>" href="business.php"><i class="bi bi-briefcase"></i> Business</a>
<a class="nav-link<?= ara_nav_active('settings.php',$currentPage) ?>" href="settings.php"><i class="bi bi-sliders"></i> Configuration</a>
</nav><div class="sidebar-footer"><a href="logout.php" class="btn btn-outline-light btn-sm w-100"><i class="bi bi-box-arrow-right"></i> Déconnexion</a></div></aside>
<div class="main-content"><div class="topbar"><div class="d-flex align-items-center gap-2"><button id="sidebarToggle" class="btn btn-outline-secondary btn-sm" type="button" aria-label="Ouvrir le menu"><i class="bi bi-list"></i></button><h5 class="mb-0"><?= htmlspecialchars($pageTitle,ENT_QUOTES,'UTF-8') ?></h5></div><span class="text-muted small d-none d-md-inline">ARA Tech WiFi Admin</span></div>
