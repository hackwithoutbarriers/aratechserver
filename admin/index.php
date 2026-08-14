<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';
$adminToken = $config['admin']['token'] ?? getenv('ADMIN_TOKEN');
$pageTitle = 'Tableau de bord - ARA Tech WiFi';
const DASHBOARD_ROUTER_ONLINE_THRESHOLD = 360;
function ara_format_bytes(?int $bytes): string
{
    if ($bytes === null) return '—';
    $units = ['o', 'Ko', 'Mo', 'Go'];
    $value = (float)$bytes;
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) { $value /= 1024; $i++; }
    return number_format($value, $i === 0 ? 0 : 1, ',', ' ') . ' ' . $units[$i];
}
function ara_router_snapshot(): array
{
    try {
        $pdo = ara_db_supabase();
        $stmt = $pdo->query(
            'SELECT id, snapshot_date, snapshot_time, active_count, received_at,
                    router_identity, router_uptime, router_version, cpu_load,
                    memory_total, memory_free
             FROM hotspot_snapshots
             ORDER BY id DESC
             LIMIT 1'
        );
        $snapshot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$snapshot) return ['status'=>'UNKNOWN','age_seconds'=>null,'snapshot'=>null,'error'=>null];
        $last = null;
        if (!empty($snapshot['received_at'])) {
            try { $last = new DateTimeImmutable((string)$snapshot['received_at']); } catch (Throwable $e) { $last = null; }
        }
        if ($last === null && !empty($snapshot['snapshot_date']) && !empty($snapshot['snapshot_time'])) {
            $raw = $snapshot['snapshot_date'] . ' ' . $snapshot['snapshot_time'];
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, new DateTimeZone('UTC'));
            if ($parsed !== false) $last = $parsed;
        }
        if ($last === null) return ['status'=>'UNKNOWN','age_seconds'=>null,'snapshot'=>$snapshot,'error'=>null];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $age = max(0, $now->getTimestamp() - $last->getTimestamp());
        return ['status'=>$age < DASHBOARD_ROUTER_ONLINE_THRESHOLD ? 'ONLINE' : 'OFFLINE','age_seconds'=>$age,'snapshot'=>$snapshot,'error'=>null];
    } catch (Throwable $e) {
        error_log('[Dashboard] Lecture hotspot_snapshots échouée : ' . $e->getMessage());
        return ['status'=>'UNKNOWN','age_seconds'=>null,'snapshot'=>null,'error'=>'Impossible de lire le dernier état du MikroTik depuis Supabase.'];
    }
}
$routerState = ara_router_snapshot();
$s = $routerState['snapshot'] ?? null;
$status = $routerState['status'];
$statusLabel = $status === 'ONLINE' ? 'Synchronisation active' : ($status === 'OFFLINE' ? 'Synchronisation interrompue' : 'Statut inconnu');
require __DIR__ . '/header.php';
?>
<div class="container-fluid px-3 px-md-4 py-4">
    <div class="card card-custom" id="network-section">
        <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
            <span><i class="bi bi-hdd-network"></i> Statut Réseau — Synchronisation MikroTik</span>
            <?php $statusCode = $status; $statusLabelOverride = $statusLabel; require __DIR__ . '/components/status-pill.php'; unset($statusLabelOverride, $statusCode); ?>
        </div>
        <div class="card-body">
            <?php if ($status === 'ONLINE' && $s): ?>
                <?php $alertType='success'; $alertIcon='bi bi-check-circle-fill'; $alertTitle='Le MikroTik est actuellement joignable par son canal de synchronisation.'; $alertMessage="Le routeur pousse son état vers ARA Tech Server ; aucune connexion entrante Render → RouterOS n'est utilisée."; require __DIR__ . '/components/alert-card.php'; unset($alertType,$alertIcon,$alertTitle,$alertMessage); ?>
            <?php elseif ($status === 'OFFLINE' && $s): ?>
                <?php $alertType='warning'; $alertIcon='bi bi-exclamation-triangle-fill'; $alertTitle='Le dernier heartbeat du MikroTik est périmé.'; $alertMessage='Vérifie le scheduler et le script push-hotspot-status.rsc sur le routeur.'; require __DIR__ . '/components/alert-card.php'; unset($alertType,$alertIcon,$alertTitle,$alertMessage); ?>
            <?php else: ?>
                <?php $alertType='secondary'; $alertIcon='bi bi-question-circle-fill'; $alertTitle='Aucun snapshot exploitable.'; $alertMessage=$routerState['error'] ?? 'Le routeur ne s’est pas encore synchronisé avec le serveur.'; require __DIR__ . '/components/alert-card.php'; unset($alertType,$alertIcon,$alertTitle,$alertMessage); ?>
            <?php endif; ?>
            <div class="row g-3">
                <?php $metricLabel='Identité'; $metricValue=(string)($s['router_identity'] ?? '—'); $metricIcon='bi-hdd-network'; $metricTone='primary'; require __DIR__ . '/components/metric-card.php'; unset($metricLabel,$metricValue,$metricIcon,$metricTone); ?>
                <?php $metricLabel='Version RouterOS'; $metricValue=(string)($s['router_version'] ?? '—'); $metricIcon='bi-cpu'; $metricTone='info'; require __DIR__ . '/components/metric-card.php'; unset($metricLabel,$metricValue,$metricIcon,$metricTone); ?>
                <?php $metricLabel='Uptime'; $metricValue=(string)($s['router_uptime'] ?? '—'); $metricIcon='bi-clock-history'; $metricTone='primary'; require __DIR__ . '/components/metric-card.php'; unset($metricLabel,$metricValue,$metricIcon,$metricTone); ?>
                <?php $metricLabel='Sessions actives'; $metricValue=isset($s['active_count']) ? (string)(int)$s['active_count'] : '—'; $metricIcon='bi-people'; $metricTone='success'; require __DIR__ . '/components/metric-card.php'; unset($metricLabel,$metricValue,$metricIcon,$metricTone); ?>
                <?php $metricLabel='CPU'; $metricValue=($s && $s['cpu_load'] !== null) ? (int)$s['cpu_load'] . ' %' : '—'; $metricIcon='bi-speedometer2'; $metricTone='warning'; require __DIR__ . '/components/metric-card.php'; unset($metricLabel,$metricValue,$metricIcon,$metricTone); ?>
                <?php $metricLabel='Mémoire libre'; $metricValue=ara_format_bytes(isset($s['memory_free']) ? (int)$s['memory_free'] : null); $metricIcon='bi-memory'; $metricTone='success'; require __DIR__ . '/components/metric-card.php'; unset($metricLabel,$metricValue,$metricIcon,$metricTone); ?>
                <?php $metricLabel='Mémoire totale'; $metricValue=ara_format_bytes(isset($s['memory_total']) ? (int)$s['memory_total'] : null); $metricIcon='bi-memory'; $metricTone='primary'; require __DIR__ . '/components/metric-card.php'; unset($metricLabel,$metricValue,$metricIcon,$metricTone); ?>
                <?php $metricLabel='Dernière synchronisation'; $metricValue=(string)($s['received_at'] ?? '—'); $metricIcon='bi-arrow-repeat'; $metricTone='info'; require __DIR__ . '/components/metric-card.php'; unset($metricLabel,$metricValue,$metricIcon,$metricTone); ?>
            </div>
            <?php if ($routerState['age_seconds'] !== null): ?>
                <div class="mt-3 text-muted small"><i class="bi bi-clock-history"></i> Âge du dernier snapshot : <?= (int)$routerState['age_seconds'] ?> s. Seuil ONLINE : <?= DASHBOARD_ROUTER_ONLINE_THRESHOLD ?> s.</div>
            <?php endif; ?>
            <div class="mt-3"><a href="monitoring.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-activity"></i> Ouvrir le monitoring</a></div>
        </div>
    </div>
    <div class="card card-custom" id="business-section">
        <div class="card-header card-header-custom d-flex flex-wrap justify-content-between align-items-center gap-2"><span><i class="bi bi-briefcase"></i> Gestion Business</span><select id="period-select" class="form-select form-select-sm" style="width:auto;min-width:150px;"><option value="today">Aujourd'hui</option><option value="yesterday">Hier</option><option value="7days">7 derniers jours</option><option value="thismonth" selected>Ce mois</option><option value="lastmonth">Mois précédent</option></select></div>
        <div class="card-body">
            <div class="row g-3" id="kpi-cards">
                <div class="col-md-3 col-6"><div class="h-100"><?php $metricLabel="Chiffre d'affaires"; $metricValue='--'; $metricIcon='bi-cash-stack'; $metricTone='success'; $metricId='kpi-revenue'; require __DIR__ . '/components/metric-card.php'; unset($metricLabel,$metricValue,$metricIcon,$metricTone,$metricId); ?></div></div>
                <div class="col-md-3 col-6"><div class="h-100"><?php $metricLabel='Tickets vendus'; $metricValue='--'; $metricIcon='bi-ticket-perforated'; $metricTone='primary'; $metricId='kpi-tickets'; require __DIR__ . '/components/metric-card.php'; unset($metricLabel,$metricValue,$metricIcon,$metricTone,$metricId); ?></div></div>
                <div class="col-md-3 col-6"><div class="h-100"><?php $metricLabel='Sessions actives'; $metricValue='--'; $metricIcon='bi-wifi'; $metricTone='info'; $metricId='kpi-sessions'; require __DIR__ . '/components/metric-card.php'; unset($metricLabel,$metricValue,$metricIcon,$metricTone,$metricId); ?></div></div>
                <div class="col-md-3 col-6"><div class="h-100"><?php $metricLabel='Abonnements'; $metricValue='--'; $metricIcon='bi-calendar-check'; $metricTone='warning'; $metricId='kpi-subs'; require __DIR__ . '/components/metric-card.php'; unset($metricLabel,$metricValue,$metricIcon,$metricTone,$metricId); ?></div></div>
            </div>
            <canvas id="revenueChart" height="90"></canvas>
        </div>
    </div>
    <div class="card card-custom" id="wifizone-section">
        <div class="card-header card-header-custom"><i class="bi bi-router"></i> WiFi Zone</div>
        <div class="card-body"><div class="placeholder-zone"><i class="bi bi-arrow-repeat"></i><strong>Management du routeur en mode asynchrone</strong><p class="mb-0 small">Les commandes passent par la file Supabase et sont récupérées par le MikroTik via HTTPS. Les snapshots remontés par le routeur alimentent le dashboard sans exiger de port entrant sur CanalBox.</p></div></div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const token = <?= json_encode($adminToken) ?>;
    const apiUrl = '/api.php';
    let revenueChartInstance = null;
    function formatMoney(amount) { return new Intl.NumberFormat('fr-FR').format(amount || 0); }
    function fetchDashboard(period) {
        const url = new URL(apiUrl, window.location.origin);
        url.searchParams.append('route', 'dashboard');
        url.searchParams.append('token', token);
        url.searchParams.append('period', period);
        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || data.error?.message || 'Erreur inconnue');
                const kpis = data.kpis || {};
                document.getElementById('kpi-revenue').textContent = formatMoney(kpis.revenue) + ' FCFA';
                document.getElementById('kpi-tickets').textContent = kpis.tickets_sold ?? '--';
                document.getElementById('kpi-sessions').textContent = kpis.active_sessions ?? '--';
                document.getElementById('kpi-subs').textContent = kpis.active_subscriptions ?? '--';
                const ctx = document.getElementById('revenueChart').getContext('2d');
                if (revenueChartInstance) revenueChartInstance.destroy();
                revenueChartInstance = new Chart(ctx, { type:'bar', data:{ labels:(data.revenue_chart || []).map(d=>d.label), datasets:[{ label:'CA', data:(data.revenue_chart || []).map(d=>d.value), backgroundColor:'#f5a623', borderColor:'#0b2c82', borderWidth:1 }] }, options:{responsive:true, scales:{y:{beginAtZero:true}}} });
            })
            .catch(err => { console.error('Dashboard KPI:', err); document.getElementById('kpi-revenue').textContent = 'N/A'; });
    }
    document.getElementById('period-select').addEventListener('change', function(){ fetchDashboard(this.value); });
    fetchDashboard('thismonth');
});
</script>
<?php require __DIR__ . '/footer.php'; ?>
