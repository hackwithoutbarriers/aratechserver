<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../lib/business_sales.php';
$config = $config ?? require __DIR__ . '/../../../config.php';
$periodKey = $periodKey ?? 'thismonth';
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$periods = [
    'today' => [$now->format('Y-m-d'), $now->format('Y-m-d'), "Aujourd'hui"],
    '7days' => [$now->modify('-6 days')->format('Y-m-d'), $now->format('Y-m-d'), '7 derniers jours'],
    'thismonth' => [$now->modify('first day of this month')->format('Y-m-d'), $now->format('Y-m-d'), 'Ce mois'],
    'lastmonth' => [$now->modify('first day of last month')->format('Y-m-d'), $now->modify('last day of last month')->format('Y-m-d'), 'Mois précédent'],
];
if (!isset($periods[$periodKey])) $periodKey = 'thismonth';
[$start, $end, $periodLabel] = $periods[$periodKey];

$metrics = [
    'revenue'=>0,
    'tickets'=>0,
    'expenses'=>0,
    'profit'=>0,
    'margin'=>null,
    'average'=>0,
    'duplicates'=>0,
    'inferred'=>0,
    'raw_rows'=>0,
    'source_label'=>'Transactions commerciales',
    'profile_stats'=>[],
    'sales'=>[],
    'daily'=>[],
    'error'=>null,
];
try {
    $pdo = ara_db_supabase();
    $sales = ara_business_sales($pdo, $start, $end);
    $metrics['revenue'] = $sales['revenue'];
    $metrics['tickets'] = $sales['tickets'];
    $metrics['duplicates'] = $sales['duplicates_removed'];
    $metrics['inferred'] = $sales['inferred_count'];
    $metrics['raw_rows'] = $sales['raw_rows'];
    $metrics['source_label'] = $sales['source_label'];
    $metrics['profile_stats'] = $sales['profile_stats'];
    $metrics['sales'] = array_slice(array_reverse($sales['sales']), 0, 8);
    $metrics['daily'] = $sales['daily'];

    $q = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?');
    $q->execute([$start, $end]);
    $metrics['expenses'] = (int)$q->fetchColumn();
    $metrics['profit'] = $metrics['revenue'] - $metrics['expenses'];
    $metrics['margin'] = $metrics['revenue'] > 0 ? ($metrics['profit'] / $metrics['revenue']) * 100 : null;
    $metrics['average'] = $metrics['tickets'] > 0 ? (int)round($metrics['revenue'] / $metrics['tickets']) : 0;
} catch (Throwable $e) {
    error_log('[Business Command Center] ' . $e->getMessage());
    $metrics['error'] = 'Certaines données Business ne sont pas disponibles.';
}

function business_money(int $value): string { return number_format($value, 0, ',', ' ') . ' FCFA'; }
?>
<div class="business-command-center">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <div class="text-uppercase small fw-semibold text-muted mb-1">Business Intelligence</div>
            <h2 class="mb-1">Business Command Center</h2>
            <p class="text-muted mb-0">CA et tickets calculés depuis le ledger transactionnel. Les transactions historiques inférées restent explicitement identifiées.</p>
        </div>
        <form method="get"><input type="hidden" name="tab" value="overview"><select class="form-select form-select-sm" name="period" onchange="this.form.submit()"><option value="today" <?= $periodKey==='today'?'selected':'' ?>>Aujourd'hui</option><option value="7days" <?= $periodKey==='7days'?'selected':'' ?>>7 derniers jours</option><option value="thismonth" <?= $periodKey==='thismonth'?'selected':'' ?>>Ce mois</option><option value="lastmonth" <?= $periodKey==='lastmonth'?'selected':'' ?>>Mois précédent</option></select></form>
    </div>
    <?php if ($metrics['error']): ?><div class="alert alert-warning"><?= htmlspecialchars($metrics['error'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <div class="alert alert-info py-2"><i class="bi bi-database-check"></i> Source : <strong><?= htmlspecialchars($metrics['source_label'], ENT_QUOTES, 'UTF-8') ?></strong>. Une transaction = un événement de vente identifié par `transaction_id`.</div>
    <div class="row g-3">
        <?php $cards=[['Chiffre d’affaires',business_money($metrics['revenue']),'bi-cash-stack','success',$periodLabel],['Tickets vendus',number_format($metrics['tickets'],0,',',' '),'bi-ticket-perforated','info','Transactions PAID'],['Panier moyen',business_money($metrics['average']),'bi-calculator','primary','CA / tickets'],['Résultat après dépenses',business_money($metrics['profit']),'bi-graph-up-arrow',$metrics['profit']>=0?'success':'danger','CA − dépenses']]; foreach($cards as [$metricLabel,$metricValue,$metricIcon,$metricTone,$metricHelp]): ?><div class="col-12 col-sm-6 col-xl-3"><div class="h-100"><?php include __DIR__.'/../../components/metric-card.php'; ?></div></div><?php endforeach; ?>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-xl-8"><div class="card card-custom h-100"><div class="card-header card-header-custom"><i class="bi bi-bar-chart-line"></i> CA par jour — <?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?></div><div class="card-body"><canvas id="businessSalesChart" height="100"></canvas></div></div></div>
        <div class="col-xl-4"><div class="card card-custom h-100"><div class="card-header card-header-custom"><i class="bi bi-pie-chart"></i> Ventes par profil</div><div class="card-body"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Profil</th><th>Tickets</th><th>CA</th></tr></thead><tbody><?php foreach($metrics['profile_stats'] as $p): ?><tr><td><?= htmlspecialchars((string)$p['profile'],ENT_QUOTES,'UTF-8') ?></td><td><?= (int)$p['nb'] ?></td><td><?= business_money((int)$p['ca']) ?></td></tr><?php endforeach; ?><?php if(!$metrics['profile_stats']): ?><tr><td colspan="3" class="text-center text-muted">Aucune vente.</td></tr><?php endif; ?></tbody></table></div></div></div></div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-xl-8"><div class="card card-custom"><div class="card-header card-header-custom d-flex justify-content-between"><span><i class="bi bi-receipt"></i> Transactions récentes</span><a class="btn btn-sm btn-outline-light" href="business.php?tab=reports">Voir plus</a></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>Date</th><th>Utilisateur</th><th>Profil</th><th class="text-end">Montant</th></tr></thead><tbody><?php foreach($metrics['sales'] as $sale): ?><tr><td><?= htmlspecialchars((string)($sale['sale_date']??''),ENT_QUOTES,'UTF-8') ?> <?= htmlspecialchars((string)($sale['sale_time']??''),ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)($sale['username']??''),ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)($sale['profile']??''),ENT_QUOTES,'UTF-8') ?></td><td class="text-end"><?= business_money((int)($sale['amount']??0)) ?></td></tr><?php endforeach; ?><?php if(!$metrics['sales']): ?><tr><td colspan="4" class="text-center text-muted py-4">Aucune transaction.</td></tr><?php endif; ?></tbody></table></div></div></div></div>
        <div class="col-xl-4"><div class="card card-custom h-100"><div class="card-header card-header-custom"><i class="bi bi-shield-check"></i> Qualité du ledger</div><div class="card-body"><div class="business-stat-row"><span>Transactions du ledger<strong><?= number_format($metrics['raw_rows'],0,',',' ') ?></strong></span></div><div class="business-stat-row"><span>Transactions retenues<strong><?= number_format($metrics['tickets'],0,',',' ') ?></strong></span></div><div class="business-stat-row"><span>Historiques inférés<strong><?= number_format($metrics['inferred'],0,',',' ') ?></strong></span></div><div class="business-stat-row"><span>Doublons techniques<strong><?= number_format($metrics['duplicates'],0,',',' ') ?></strong></span></div><div class="business-stat-row"><span>Dépenses<strong><?= business_money($metrics['expenses']) ?></strong></span></div><div class="business-stat-row"><span>Marge<strong><?= $metrics['margin']===null?'—':number_format($metrics['margin'],1,',',' ').' %' ?></strong></span></div></div></div></div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){const data=<?= json_encode($metrics['daily'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>; const el=document.getElementById('businessSalesChart'); if(el && typeof Chart!=='undefined'){new Chart(el,{type:'bar',data:{labels:data.map(x=>x.sale_date),datasets:[{label:'CA (FCFA)',data:data.map(x=>x.total),tension:.3}]},options:{responsive:true,scales:{y:{beginAtZero:true}}}});}});
</script>
<style>.business-stat-row{display:flex;justify-content:space-between;padding:.8rem 0;border-bottom:1px solid #edf0f3}.business-stat-row:last-child{border-bottom:0}.business-stat-row span{display:flex;flex-direction:column;color:#6c757d}.business-stat-row strong{color:var(--bleu-nuit);font-size:1.05rem}</style>
