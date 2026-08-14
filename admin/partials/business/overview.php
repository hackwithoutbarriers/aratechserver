<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../lib/api_client.php';
require_once __DIR__ . '/../../../../db.php';

$config = $config ?? require __DIR__ . '/../../../../config.php';
$periodKey = $periodKey ?? 'thismonth';

function ara_business_period(string $period): array
{
    $now = new DateTimeImmutable('now');
    return match ($period) {
        'today' => ['start'=>$now->format('Y-m-d'),'end'=>$now->format('Y-m-d'),'label'=>"Aujourd'hui",'previous_start'=>$now->modify('-1 day')->format('Y-m-d'),'previous_end'=>$now->modify('-1 day')->format('Y-m-d')],
        '7days' => ['start'=>$now->modify('-6 days')->format('Y-m-d'),'end'=>$now->format('Y-m-d'),'label'=>'7 derniers jours','previous_start'=>$now->modify('-13 days')->format('Y-m-d'),'previous_end'=>$now->modify('-7 days')->format('Y-m-d')],
        'lastmonth' => ['start'=>$now->modify('first day of last month')->format('Y-m-d'),'end'=>$now->modify('last day of last month')->format('Y-m-d'),'label'=>'Mois précédent','previous_start'=>$now->modify('first day of -2 month')->format('Y-m-d'),'previous_end'=>$now->modify('last day of -2 month')->format('Y-m-d')],
        default => ['start'=>$now->modify('first day of this month')->format('Y-m-d'),'end'=>$now->format('Y-m-d'),'label'=>'Ce mois','previous_start'=>$now->modify('first day of last month')->format('Y-m-d'),'previous_end'=>$now->modify('last day of last month')->format('Y-m-d')],
    };
}
function ara_business_money(int $value): string { return number_format($value, 0, ',', ' ') . ' FCFA'; }
function ara_business_percent(?float $value): string { return $value === null ? '—' : number_format($value, 1, ',', ' ') . ' %'; }
function ara_business_change(int $current, int $previous): ?float { return $previous === 0 ? ($current === 0 ? 0.0 : null) : (($current - $previous) / $previous) * 100; }

$period = ara_business_period($periodKey);
$metrics = ['revenue'=>0,'tickets'=>0,'expenses'=>0,'profit'=>0,'margin'=>null,'average_ticket'=>0,'active_sessions'=>null,'active_subscriptions'=>null,'expiring_subscriptions'=>null,'total_users'=>null,'ads_active'=>0,'ads_views'=>0,'ads_clicks'=>0,'sales_change'=>null,'expense_change'=>null,'trend'=>[],'profile_stats'=>[],'recent_sales'=>[],'error'=>null];

try {
    $salesResult = ara_api_call($config, 'get-sales', ['start'=>$period['start'],'end'=>$period['end']]);
    $previousResult = ara_api_call($config, 'get-sales', ['start'=>$period['previous_start'],'end'=>$period['previous_end']]);
    if (!$salesResult['success']) throw new RuntimeException((string)$salesResult['message']);

    $sales = $salesResult['data'] ?? [];
    $previous = $previousResult['success'] ? ($previousResult['data'] ?? []) : [];
    $metrics['revenue'] = (int)($sales['total_ca'] ?? 0);
    $metrics['tickets'] = (int)($sales['total_tickets'] ?? 0);
    $metrics['profile_stats'] = is_array($sales['profile_stats'] ?? null) ? $sales['profile_stats'] : [];
    $metrics['recent_sales'] = array_slice(is_array($sales['sales'] ?? null) ? $sales['sales'] : [], 0, 8);
    $metrics['sales_change'] = ara_business_change($metrics['revenue'], (int)($previous['total_ca'] ?? 0));
    $metrics['average_ticket'] = $metrics['tickets'] > 0 ? (int)round($metrics['revenue'] / $metrics['tickets']) : 0;

    $pdo = ara_db_supabase();
    $q = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?');
    $q->execute([$period['start'],$period['end']]);
    $metrics['expenses'] = (int)$q->fetchColumn();
    $q->execute([$period['previous_start'],$period['previous_end']]);
    $metrics['expense_change'] = ara_business_change($metrics['expenses'], (int)$q->fetchColumn());
    $metrics['profit'] = $metrics['revenue'] - $metrics['expenses'];
    $metrics['margin'] = $metrics['revenue'] > 0 ? ($metrics['profit'] / $metrics['revenue']) * 100 : null;

    $salesByDay = $pdo->prepare('SELECT sale_date AS day, COALESCE(SUM(amount),0) AS revenue, COUNT(*) AS tickets FROM sales_log WHERE sale_date BETWEEN ? AND ? GROUP BY sale_date ORDER BY sale_date ASC');
    $salesByDay->execute([$period['start'],$period['end']]);
    $salesMap = [];
    foreach ($salesByDay->fetchAll(PDO::FETCH_ASSOC) as $row) $salesMap[(string)$row['day']] = ['revenue'=>(int)$row['revenue'],'tickets'=>(int)$row['tickets']];

    $expensesByDay = $pdo->prepare('SELECT expense_date AS day, COALESCE(SUM(amount),0) AS expenses FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY expense_date ORDER BY expense_date ASC');
    $expensesByDay->execute([$period['start'],$period['end']]);
    $expensesMap = [];
    foreach ($expensesByDay->fetchAll(PDO::FETCH_ASSOC) as $row) $expensesMap[(string)$row['day']] = (int)$row['expenses'];

    $start = new DateTimeImmutable($period['start']);
    $end = new DateTimeImmutable($period['end']);
    for ($cursor=$start; $cursor <= $end; $cursor=$cursor->modify('+1 day')) {
        $day = $cursor->format('Y-m-d');
        $metrics['trend'][] = ['label'=>$cursor->format('d/m'),'revenue'=>$salesMap[$day]['revenue'] ?? 0,'expenses'=>$expensesMap[$day] ?? 0,'tickets'=>$salesMap[$day]['tickets'] ?? 0];
    }

    $metrics['total_users'] = (int)$pdo->query('SELECT COUNT(*) FROM hotspot_users')->fetchColumn();
    $q = $pdo->prepare('SELECT COUNT(*) FROM hotspot_expiry WHERE expiry >= ?'); $q->execute([date('Y-m-d H:i:s')]); $metrics['active_subscriptions']=(int)$q->fetchColumn();
    $q = $pdo->prepare('SELECT COUNT(*) FROM hotspot_expiry WHERE expiry >= ? AND expiry <= ?'); $q->execute([date('Y-m-d H:i:s'),date('Y-m-d H:i:s',strtotime('+7 days'))]); $metrics['expiring_subscriptions']=(int)$q->fetchColumn();
    $latest = $pdo->query('SELECT active_count FROM hotspot_snapshots ORDER BY id DESC LIMIT 1')->fetchColumn();
    if ($latest !== false) $metrics['active_sessions']=(int)$latest;

    try {
        $local = ara_db($config);
        $metrics['ads_active'] = (int)$local->query('SELECT COUNT(*) FROM ads WHERE active = 1')->fetchColumn();
        $metrics['ads_views'] = (int)$local->query('SELECT COALESCE(SUM(views),0) FROM ads')->fetchColumn();
        $metrics['ads_clicks'] = (int)$local->query('SELECT COALESCE(SUM(clicks),0) FROM ads')->fetchColumn();
    } catch (Throwable $e) { error_log('[Business Command Center] ads metrics: '.$e->getMessage()); }
} catch (Throwable $e) {
    error_log('[Business Command Center] '.$e->getMessage());
    $metrics['error']='Certaines données Business ne sont pas disponibles pour le moment.';
}
?>
<div class="business-command-center">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div><div class="text-uppercase small fw-semibold text-muted mb-1">Business Intelligence</div><h2 class="mb-1">Business Command Center</h2><p class="text-muted mb-0">Pilotage des ventes, de la rentabilité, des abonnements et de l’acquisition.</p></div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <form method="get" class="d-flex gap-2"><input type="hidden" name="tab" value="overview"><select class="form-select form-select-sm" name="period" aria-label="Période commerciale" onchange="this.form.submit()"><option value="today" <?= $periodKey==='today'?'selected':'' ?>>Aujourd'hui</option><option value="7days" <?= $periodKey==='7days'?'selected':'' ?>>7 derniers jours</option><option value="thismonth" <?= $periodKey==='thismonth'?'selected':'' ?>>Ce mois</option><option value="lastmonth" <?= $periodKey==='lastmonth'?'selected':'' ?>>Mois précédent</option></select></form>
            <a class="btn btn-orange btn-sm" href="business.php?tab=finances"><i class="bi bi-plus-lg"></i> Ajouter une dépense</a>
        </div>
    </div>
    <?php if ($metrics['error']): ?><div class="alert alert-warning d-flex gap-2 align-items-center mb-4"><i class="bi bi-exclamation-triangle-fill"></i><span><?= htmlspecialchars($metrics['error'],ENT_QUOTES,'UTF-8') ?></span></div><?php endif; ?>

    <div class="row g-3">
        <?php $metricLabel='Chiffre d’affaires';$metricValue=ara_business_money($metrics['revenue']);$metricIcon='bi-cash-stack';$metricTone='success';$metricHelp=$period['label'];$metricId='business-revenue';include __DIR__.'/../../components/metric-card.php'; ?>
        <?php $metricLabel='Bénéfice';$metricValue=ara_business_money($metrics['profit']);$metricIcon='bi-graph-up-arrow';$metricTone=$metrics['profit']>=0?'success':'danger';$metricHelp='CA − dépenses';$metricId='business-profit';include __DIR__.'/../../components/metric-card.php'; ?>
        <?php $metricLabel='Tickets vendus';$metricValue=number_format($metrics['tickets'],0,',',' ');$metricIcon='bi-ticket-perforated';$metricTone='info';$metricHelp='Panier moyen : '.ara_business_money($metrics['average_ticket']);$metricId='business-tickets';include __DIR__.'/../../components/metric-card.php'; ?>
        <?php $metricLabel='Dépenses';$metricValue=ara_business_money($metrics['expenses']);$metricIcon='bi-wallet2';$metricTone='warning';$metricHelp=$period['label'];$metricId='business-expenses';include __DIR__.'/../../components/metric-card.php'; ?>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-xl-8"><div class="card card-custom h-100"><div class="card-header card-header-custom d-flex justify-content-between"><span><i class="bi bi-bar-chart-line"></i> Performance commerciale</span><span class="small opacity-75"><?= htmlspecialchars($period['label'],ENT_QUOTES,'UTF-8') ?></span></div><div class="card-body"><div class="chart-wrap"><canvas id="businessTrendChart"></canvas></div></div></div></div>
        <div class="col-xl-4"><div class="card card-custom h-100"><div class="card-header card-header-custom"><i class="bi bi-pie-chart"></i> Répartition des ventes</div><div class="card-body"><div class="chart-wrap chart-wrap-doughnut"><canvas id="businessProfileChart"></canvas></div><?php if (!$metrics['profile_stats']): ?><div class="text-center text-muted small">Aucune vente sur la période.</div><?php endif; ?></div></div></div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-xl-8"><div class="card card-custom"><div class="card-header card-header-custom d-flex justify-content-between align-items-center"><span><i class="bi bi-receipt"></i> Activité récente</span><a class="btn btn-sm btn-outline-light" href="business.php?tab=reports">Rapports complets</a></div><div class="card-body p-0"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Date</th><th>Utilisateur</th><th>Profil</th><th class="text-end">Montant</th></tr></thead><tbody><?php foreach ($metrics['recent_sales'] as $sale): ?><tr><td><?= htmlspecialchars((string)($sale['sale_date']??''),ENT_QUOTES,'UTF-8') ?><br><span class="small text-muted"><?= htmlspecialchars((string)($sale['sale_time']??''),ENT_QUOTES,'UTF-8') ?></span></td><td class="fw-semibold"><?= htmlspecialchars((string)($sale['username']??'—'),ENT_QUOTES,'UTF-8') ?></td><td><span class="badge text-bg-light border"><?= htmlspecialchars((string)($sale['profile']??'—'),ENT_QUOTES,'UTF-8') ?></span></td><td class="text-end fw-semibold"><?= ara_business_money((int)($sale['amount']??0)) ?></td></tr><?php endforeach; ?><?php if (!$metrics['recent_sales']): ?><tr><td colspan="4" class="text-center text-muted py-4">Aucune vente récente.</td></tr><?php endif; ?></tbody></table></div></div></div></div>
        <div class="col-xl-4"><div class="card card-custom h-100"><div class="card-header card-header-custom"><i class="bi bi-speedometer2"></i> Indicateurs opérationnels</div><div class="card-body"><div class="business-stat-row"><div><span class="text-muted">Sessions actives</span><strong><?= $metrics['active_sessions']===null?'—':number_format($metrics['active_sessions'],0,',',' ') ?></strong></div><i class="bi bi-wifi"></i></div><div class="business-stat-row"><div><span class="text-muted">Abonnements actifs</span><strong><?= $metrics['active_subscriptions']===null?'—':number_format($metrics['active_subscriptions'],0,',',' ') ?></strong></div><i class="bi bi-person-check"></i></div><div class="business-stat-row"><div><span class="text-muted">Expirent sous 7 jours</span><strong><?= $metrics['expiring_subscriptions']===null?'—':number_format($metrics['expiring_subscriptions'],0,',',' ') ?></strong></div><i class="bi bi-hourglass-split"></i></div><div class="business-stat-row"><div><span class="text-muted">Utilisateurs Hotspot</span><strong><?= $metrics['total_users']===null?'—':number_format($metrics['total_users'],0,',',' ') ?></strong></div><i class="bi bi-people"></i></div><div class="business-stat-row"><div><span class="text-muted">Annonces actives</span><strong><?= number_format($metrics['ads_active'],0,',',' ') ?></strong></div><i class="bi bi-megaphone"></i></div><div class="business-stat-row"><div><span class="text-muted">Vues / clics annonces</span><strong><?= number_format($metrics['ads_views'],0,',',' ') ?> / <?= number_format($metrics['ads_clicks'],0,',',' ') ?></strong></div><i class="bi bi-bar-chart"></i></div></div></div></div>
    </div>

    <div class="row g-3 mt-1"><div class="col-12"><div class="card card-custom"><div class="card-header card-header-custom"><i class="bi bi-lightning-charge"></i> Actions rapides</div><div class="card-body"><div class="row g-2"><div class="col-6 col-md-3"><a class="quick-action" href="business.php?tab=finances"><i class="bi bi-wallet2"></i><span>Gérer les finances</span></a></div><div class="col-6 col-md-3"><a class="quick-action" href="business.php?tab=reports"><i class="bi bi-graph-up"></i><span>Analyser les ventes</span></a></div><div class="col-6 col-md-3"><a class="quick-action" href="business.php?tab=ads"><i class="bi bi-megaphone"></i><span>Gérer les annonces</span></a></div><div class="col-6 col-md-3"><a class="quick-action" href="operations.php?tab=hotspot"><i class="bi bi-people"></i><span>Gérer le Hotspot</span></a></div></div></div></div></div></div>
</div>
<style>
.business-command-center .chart-wrap{position:relative;height:320px}.business-command-center .chart-wrap-doughnut{height:300px}.business-stat-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:.9rem 0;border-bottom:1px solid #edf0f3}.business-stat-row:last-child{border-bottom:0}.business-stat-row div{display:flex;flex-direction:column;gap:2px}.business-stat-row strong{font-size:1.08rem;color:var(--bleu-nuit)}.business-stat-row>i{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;background:#f4f6fb;color:var(--bleu-nuit)}.quick-action{min-height:92px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;border:1px solid #e5e9ef;border-radius:12px;background:#fff;color:#233044;text-decoration:none;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}.quick-action i{font-size:1.35rem;color:var(--orange)}.quick-action:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.08);border-color:#d4dbe5;color:#233044}@media(max-width:767.98px){.business-command-center .chart-wrap{height:260px}.business-command-center .chart-wrap-doughnut{height:250px}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
 const trend=<?= json_encode($metrics['trend'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
 const profiles=<?= json_encode($metrics['profile_stats'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
 const t=document.getElementById('businessTrendChart');
 if(t&&window.Chart)new Chart(t,{data:{labels:trend.map(x=>x.label),datasets:[{type:'bar',label:'Chiffre d’affaires',data:trend.map(x=>x.revenue),borderWidth:0},{type:'line',label:'Dépenses',data:trend.map(x=>x.expenses),tension:.3,borderWidth:2,pointRadius:2}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true,ticks:{callback:v=>new Intl.NumberFormat('fr-FR',{notation:'compact'}).format(v)}}}}});
 const p=document.getElementById('businessProfileChart');
 if(p&&window.Chart)new Chart(p,{type:'doughnut',data:{labels:profiles.map(x=>x.profile||'Sans profil'),datasets:[{data:profiles.map(x=>Number(x.ca||0)),borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,cutout:'66%',plugins:{legend:{position:'bottom'}}}});
});
</script>
