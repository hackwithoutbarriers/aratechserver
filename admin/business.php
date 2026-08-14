<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/components/embedded-page.php';
$pageTitle = 'Business — ARA Tech WiFi';
require __DIR__ . '/header.php';

$tab = $_GET['tab'] ?? null;
if ($tab === null) {
    if (isset($_GET['periode'])) {
        $tab = 'finances';
    } elseif (isset($_GET['start'], $_GET['end']) || isset($_GET['start_date'], $_GET['end_date'])) {
        $tab = 'reports';
    } else {
        $tab = 'overview';
    }
}
$tabs = ['overview' => 'Vue d’ensemble', 'finances' => 'Finances', 'reports' => 'Rapports', 'ads' => 'Annonces'];
if (!isset($tabs[$tab])) { $tab = 'overview'; }
?>
<div class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h2 class="mb-1">Gestion Business</h2><p class="text-muted mb-0">Revenus, dépenses, rapports et annonces dans un espace unique.</p></div>
    </div>
    <ul class="nav nav-tabs mb-4">
        <?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="business.php?tab=<?= urlencode($key) ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($tab === 'overview'): ?>
        <div class="row g-3">
            <div class="col-md-4"><div class="card card-custom h-100"><div class="card-body"><div class="stat-label">Pilotage financier</div><div class="stat-value">FCFA</div><p class="text-muted mb-0">Recettes, dépenses et bénéfice réel.</p><a class="btn btn-sm btn-orange mt-3" href="business.php?tab=finances">Finances</a></div></div></div>
            <div class="col-md-4"><div class="card card-custom h-100"><div class="card-body"><div class="stat-label">Analyse commerciale</div><div class="stat-value">KPI</div><p class="text-muted mb-0">Rapports et performance des ventes.</p><a class="btn btn-sm btn-orange mt-3" href="business.php?tab=reports">Rapports</a></div></div></div>
            <div class="col-md-4"><div class="card card-custom h-100"><div class="card-body"><div class="stat-label">Acquisition</div><div class="stat-value">ADS</div><p class="text-muted mb-0">Annonces, vues et clics.</p><a class="btn btn-sm btn-orange mt-3" href="business.php?tab=ads">Annonces</a></div></div></div>
        </div>
    <?php elseif ($tab === 'finances'): ?>
        <?php ara_render_embedded_page(__DIR__ . '/partials/business/finances.php'); ?>
    <?php elseif ($tab === 'reports'): ?>
        <?php ara_render_embedded_page(__DIR__ . '/partials/business/reports.php'); ?>
    <?php elseif ($tab === 'ads'): ?>
        <?php ara_render_embedded_page(__DIR__ . '/partials/business/ads.php'); ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
