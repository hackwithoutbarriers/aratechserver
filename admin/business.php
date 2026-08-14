<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
$pageTitle = 'Business — ARA Tech WiFi';
require __DIR__ . '/header.php';
$tab = $_GET['tab'] ?? 'overview';
$tabs = ['overview' => 'Vue d’ensemble', 'finances' => 'Finances', 'reports' => 'Rapports', 'ads' => 'Annonces'];
if (!isset($tabs[$tab])) { $tab = 'overview'; }
?>
<div class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="mb-1">Gestion Business</h2><p class="text-muted mb-0">Espace commercial unifié pour les revenus, dépenses, rapports et annonces.</p></div></div>
    <ul class="nav nav-tabs mb-4">
        <?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="business.php?tab=<?= urlencode($key) ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($tab === 'overview'): ?>
        <div class="row g-3">
            <div class="col-md-4"><div class="card card-custom h-100"><div class="card-body"><div class="stat-label">Pilotage financier</div><div class="stat-value">FCFA</div><p class="text-muted mb-0">Suivre recettes, dépenses et bénéfice réel dans une vue dédiée.</p><a class="btn btn-sm btn-orange mt-3" href="business.php?tab=finances">Ouvrir les finances</a></div></div></div>
            <div class="col-md-4"><div class="card card-custom h-100"><div class="card-body"><div class="stat-label">Analyse commerciale</div><div class="stat-value">KPI</div><p class="text-muted mb-0">Centraliser les rapports de ventes et indicateurs de performance.</p><a class="btn btn-sm btn-orange mt-3" href="business.php?tab=reports">Ouvrir les rapports</a></div></div></div>
            <div class="col-md-4"><div class="card card-custom h-100"><div class="card-body"><div class="stat-label">Acquisition</div><div class="stat-value">ADS</div><p class="text-muted mb-0">Gérer les annonces et leurs métriques sans quitter l’espace Business.</p><a class="btn btn-sm btn-orange mt-3" href="business.php?tab=ads">Gérer les annonces</a></div></div></div>
        </div>
    <?php else: ?>
        <div class="alert alert-info"><i class="bi bi-info-circle"></i> Cette section est encore servie par son écran métier existant. La nouvelle navigation en fait le point d’entrée canonique.</div>
        <?php if ($tab === 'finances'): ?><a class="btn btn-orange" href="finances.php">Ouvrir Finances</a><?php endif; ?>
        <?php if ($tab === 'reports'): ?><a class="btn btn-orange" href="reports.php">Ouvrir Rapports</a><?php endif; ?>
        <?php if ($tab === 'ads'): ?><a class="btn btn-orange" href="ads.php">Ouvrir Annonces</a><?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
