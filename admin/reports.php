<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/business_sales.php';

$config = require __DIR__ . '/../config.php';
$pageTitle = 'Rapports de ventes - ARA Tech WiFi';
$startDate = (string)($_GET['start'] ?? date('Y-m-01'));
$endDate   = (string)($_GET['end'] ?? date('Y-m-d'));
$error = '';
$sales = ['revenue'=>0,'tickets'=>0,'profile_stats'=>[],'sales'=>[],'daily'=>[],'duplicates_removed'=>0];

try {
    $pdo = ara_db_supabase();
    $sales = ara_business_sales($pdo, $startDate, $endDate);
} catch (Throwable $e) {
    error_log('[Reports] ' . $e->getMessage());
    $error = 'Impossible de charger les données commerciales.';
}

require __DIR__ . '/header.php';
?>
<div class="container-fluid mt-4">
    <h2 class="mb-3">💰 Rapports de ventes</h2>
    <form method="get" class="row g-2 align-items-end mb-4">
        <div class="col-md-3"><label class="form-label">Date début</label><input type="date" class="form-control" name="start" value="<?= htmlspecialchars($startDate,ENT_QUOTES,'UTF-8') ?>"></div>
        <div class="col-md-3"><label class="form-label">Date fin</label><input type="date" class="form-control" name="end" value="<?= htmlspecialchars($endDate,ENT_QUOTES,'UTF-8') ?>"></div>
        <div class="col-md-2"><button type="submit" class="btn btn-orange w-100"><i class="bi bi-funnel"></i> Appliquer</button></div>
        <div class="col-md-4 text-end"><span class="text-muted"><?= htmlspecialchars($startDate,ENT_QUOTES,'UTF-8') ?> → <?= htmlspecialchars($endDate,ENT_QUOTES,'UTF-8') ?></span></div>
    </form>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php else: ?>
        <div class="row g-3">
            <div class="col-md-4"><div class="card card-custom text-center p-3"><div class="stat-value"><?= number_format((int)$sales['revenue'],0,',',' ') ?> FCFA</div><div class="stat-label">Chiffre d'affaires</div></div></div>
            <div class="col-md-4"><div class="card card-custom text-center p-3"><div class="stat-value"><?= number_format((int)$sales['tickets'],0,',',' ') ?></div><div class="stat-label">Tickets vendus</div></div></div>
            <div class="col-md-4"><div class="card card-custom text-center p-3"><div class="stat-value"><?= $sales['tickets'] > 0 ? number_format((int)round($sales['revenue']/$sales['tickets']),0,',',' ') : 0 ?> FCFA</div><div class="stat-label">Panier moyen</div></div></div>
        </div>
        <div class="alert alert-info mt-3"><i class="bi bi-shield-check"></i> <?= (int)$sales['duplicates_removed'] ?> doublon(s) technique(s) ignoré(s) dans cette période.</div>
        <?php if (!empty($sales['profile_stats'])): ?><div class="card card-custom mt-3"><div class="card-header card-header-custom">Par profil</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>Profil</th><th>Nombre</th><th>CA</th></tr></thead><tbody><?php foreach($sales['profile_stats'] as $p): ?><tr><td><?= htmlspecialchars((string)$p['profile'],ENT_QUOTES,'UTF-8') ?></td><td><?= (int)$p['nb'] ?></td><td><?= number_format((int)$p['ca'],0,',',' ') ?> FCFA</td></tr><?php endforeach; ?></tbody></table></div></div></div><?php endif; ?>
        <div class="card card-custom mt-3"><div class="card-header card-header-custom">Dernières ventes</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>Date</th><th>Heure</th><th>Utilisateur</th><th>Profil</th><th>Montant</th></tr></thead><tbody><?php if(!$sales['sales']): ?><tr><td colspan="5" class="text-center text-muted">Aucune vente.</td></tr><?php else: foreach(array_reverse($sales['sales']) as $sale): ?><tr><td><?= htmlspecialchars((string)$sale['sale_date'],ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)($sale['sale_time']??''),ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)$sale['username'],ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)($sale['profile']??''),ENT_QUOTES,'UTF-8') ?></td><td><?= number_format((int)$sale['amount'],0,',',' ') ?> FCFA</td></tr><?php endforeach; endif; ?></tbody></table></div></div></div>
        <?php if (!empty($sales['daily'])): ?><div class="card card-custom mt-3"><div class="card-header card-header-custom">Évolution du chiffre d'affaires</div><div class="card-body"><canvas id="dailySalesChart" height="100"></canvas></div></div><script>document.addEventListener('DOMContentLoaded',function(){const d=<?=json_encode($sales['daily'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;new Chart(document.getElementById('dailySalesChart'),{type:'line',data:{labels:d.map(x=>x.sale_date),datasets:[{label:'CA (FCFA)',data:d.map(x=>x.total),tension:.3}]},options:{responsive:true,scales:{y:{beginAtZero:true}}}});});</script><?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
