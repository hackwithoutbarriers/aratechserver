<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/components/embedded-page.php';
$pageTitle = 'Monitoring — ARA Tech WiFi';
require __DIR__ . '/header.php';
$tab = $_GET['tab'] ?? 'overview';
$tabs = ['overview' => 'Vue d’ensemble', 'status' => 'Statut réseau', 'logs' => 'Logs système'];
if (!isset($tabs[$tab])) { $tab = 'overview'; }
?>
<div class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h2 class="mb-1">Centre de Monitoring</h2><p class="text-muted mb-0">État réseau et événements système dans un espace unique.</p></div>
    </div>
    <ul class="nav nav-tabs mb-4">
        <?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="monitoring.php?tab=<?= urlencode($key) ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($tab === 'overview'): ?>
        <div class="row g-3">
            <div class="col-md-6"><div class="card card-custom h-100"><div class="card-body"><i class="bi bi-hdd-network fs-2 text-warning"></i><h5 class="mt-2">Statut réseau</h5><p class="text-muted">État du MikroTik, snapshots, sessions et synchronisation.</p><a class="btn btn-orange" href="monitoring.php?tab=status">Voir le statut</a></div></div></div>
            <div class="col-md-6"><div class="card card-custom h-100"><div class="card-body"><i class="bi bi-journal-text fs-2 text-warning"></i><h5 class="mt-2">Événements système</h5><p class="text-muted">Journal opérationnel consolidé pour le diagnostic.</p><a class="btn btn-orange" href="monitoring.php?tab=logs">Voir les logs</a></div></div></div>
        </div>
    <?php elseif ($tab === 'status'): ?>
        <?php ara_render_embedded_page(__DIR__ . '/partials/monitoring/status.php'); ?>
    <?php elseif ($tab === 'logs'): ?>
        <?php ara_render_embedded_page(__DIR__ . '/partials/monitoring/logs.php'); ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
