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
$sales = ['revenue'=>0,'tickets'=>0,'profile_stats'=>[],'sales'=>[],'daily'=>[],'duplicates_removed'=>0,'raw_rows'=>0,'source_label'=>'Activations Hotspot dédupliquées'];

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
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><div class="text-uppercase small fw-semibold text-muted">Business Intelligence</div><h2 class="mb-1">Rapports commerciaux</h2><p class="text-muted mb-0">Activations Hotspot dédupliquées à partir du journal technique.</p></div>
    </div>
    <form method="get" class="row g-2 align-items-end mb-4">
        <div class="col-md-3"><label class="form-label">Date début</label><input type="date" class="form-control" name="start" value="<?= htmlspecialchars($startDate,ENT_QUOTES,'UTF-8') ?>"></div>
        <div class="col-md-3"><label class="form-label">Date fin</label><input type="date" class="form-control" name="end" value="<?= htmlspecialchars($endDate,ENT_QUOTES,'UTF-8') ?>"></div>
        <div class="col-md-2"><button type="submit" class="btn btn-orange w-100"><i class="bi bi-funnel"></i> Appliquer</button></div>
        <div class="col-md-4 text-end"><span class="text-muted"><?= htmlspecialchars($startDate,ENT_QUOTES,'UTF-8') ?> → <?= htmlspecialchars($endDate,ENT_QUOTES,'UTF-8') ?></span></div>
    </form>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php else: ?>
        <div class="alert alert-info"><i class="bi bi-info-circle"></i> Source : <strong><?= htmlspecialchars((string)$sales['source_label'],ENT_QUOTES,'UTF-8') ?></strong>. Le `on-login` MikroTik enregistre des connexions techniques ; ces données ne constituent pas encore un ledger de paiement transactionnel.</div>
        <div class="row g-3">
            <div class="col-md-4"><div class="card card-custom text-center p-3"><div class="stat-value"><?= number_format((int)$sales['revenue'],0,',',' ') ?> FCFA</div><div class="stat-label">Montants journalisés</div></div></div>
            <div class="col-md-4"><div class="card card-custom text-center p-3"><div class="stat-value"><?= number_format((int)$sales['tickets'],0,',',' ') ?></div><div class="stat-label">Activations retenues</div></div></div>
            <div class="col-md-4"><div class="card card-custom text-center p-3"><div class="stat-value"><?= $sales['tickets'] > 0 ? number_format((int)round($sales['revenue']/$sales['tickets']),0,',',' ') : 0 ?> FCFA</div><div class="stat-label">Montant moyen / activation</div></div></div>
        </div>
        <div class="alert alert-secondary mt-3"><i class="bi bi-database-check"></i> <?= number_format((int)$sales['raw_rows'],0,',',' ') ?> événements techniques trouvés ; <?= number_format((int)$sales['duplicates_removed'],0,',',' ') ?> reconnexions ignorées.</div>
        <?php if (!empty($sales['profile_stats'])): ?><div class="card card-custom mt-3"><div class="card-header card-header-custom">Activations par profil</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>Profil</th><th>Activations</th><th>Montants</th></tr></thead><tbody><?php foreach($sales['profile_stats'] as $p): ?><tr><td><?= htmlspecialchars((string)$p['profile'],ENT_QUOTES,'UTF-8') ?></td><td><?= (int)$p['nb'] ?></td><td><?= number_format((int)$p['ca'],0,',',' ') ?> FCFA</td></tr><?php endforeach; ?></tbody></table></div></div></div><?php endif; ?>
        <div class="card card-custom mt-3"><div class="card-header card-header-custom">Activations retenues</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>Date</th><th>Heure</th><th>Utilisateur</th><th>Profil</th><th>Montant</th></tr></thead><tbody><?php if(!$sales['sales']): ?><tr><td colspan="5" class="text-center text-muted">Aucune activation.</td></tr><?php else: foreach(array_reverse($sales['sales']) as $sale): ?><tr><td><?= htmlspecialchars((string)$sale['sale_date'],ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)($sale['sale_time']??''),ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)$sale['username'],ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)($sale['profile']??''),ENT_QUOTES,'UTF-8') ?></td><td><?= number_format((int)$sale['amount'],0,',',' ') ?> FCFA</td></tr><?php endforeach; endif; ?></tbody></table></div></div></div>
        <?php if (!empty($sales['daily'])): ?><div class="card card-custom mt-3"><div class="card-header card-header-custom">Évolution des montants journalisés</div><div class="card-body"><canvas id="dailySalesChart" height="100"></canvas></div></div><script>document.addEventListener('DOMContentLoaded',function(){const d=<?=json_encode($sales['daily'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;new Chart(document.getElementById('dailySalesChart'),{type:'line',data:{labels:d.map(x=>x.sale_date),datasets:[{label:'Montants journalisés (FCFA)',data:d.map(x=>x.total),tension:.3}]},options:{responsive:true,scales:{y:{beginAtZero:true}}}});});</script><?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
