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
            <span class="status-pill <?= strtolower($status) ?>"><span class="status-dot <?= strtolower($status) ?>"></span><?= htmlspecialchars($statusLabel) ?></span>
        </div>
        <div class="card-body">
            <?php if ($status === 'ONLINE' && $s): ?>
                <div class="alert alert-success d-flex align-items-start gap-2 mb-3">
                    <i class="bi bi-check-circle-fill fs-5"></i>
                    <div><strong>Le MikroTik est actuellement joignable par son canal de synchronisation.</strong><div class="small mt-1">Le routeur pousse son état vers ARA Tech Server ; aucune connexion entrante Render → RouterOS n'est utilisée.</div></div>
                </div>
            <?php elseif ($status === 'OFFLINE' && $s): ?>
                <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
                    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                    <div><strong>Le dernier heartbeat du MikroTik est périmé.</strong><div class="small mt-1">Vérifie le scheduler et le script <code>push-hotspot-status.rsc</code> sur le routeur.</div></div>
                </div>
            <?php else: ?>
                <div class="alert alert-secondary d-flex align-items-start gap-2 mb-3">
                    <i class="bi bi-question-circle-fill fs-5"></i>
                    <div><strong>Aucun snapshot exploitable.</strong><div class="small mt-1"><?= htmlspecialchars($routerState['error'] ?? 'Le routeur ne s’est pas encore synchronisé avec le serveur.') ?></div></div>
                </div>
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-6 col-md-3"><div class="stat-label">Identité</div><div class="fw-semibold"><?= htmlspecialchars((string)($s['router_identity'] ?? '—')) ?></div></div>
                <div class="col-6 col-md-3"><div class="stat-label">Version RouterOS</div><div class="fw-semibold"><?= htmlspecialchars((string)($s['router_version'] ?? '—')) ?></div></div>
                <div class="col-6 col-md-3"><div class="stat-label">Uptime</div><div class="fw-semibold"><?= htmlspecialchars((string)($s['router_uptime'] ?? '—')) ?></div></div>
                <div class="col-6 col-md-3"><div class="stat-label">Sessions actives</div><div class="fw-semibold"><?= isset($s['active_count']) ? (int)$s['active_count'] : '—' ?></div></div>
                <div class="col-6 col-md-3"><div class="stat-label">CPU</div><div class="fw-semibold"><?= $s && $s['cpu_load'] !== null ? (int)$s['cpu_load'] . ' %' : '—' ?></div></div>
                <div class="col-6 col-md-3"><div class="stat-label">Mémoire libre</div><div class="fw-semibold"><?= htmlspecialchars(ara_format_bytes(isset($s['memory_free']) ? (int)$s['memory_free'] : null)) ?></div></div>
                <div class="col-6 col-md-3"><div class="stat-label">Mémoire totale</div><div class="fw-semibold"><?= htmlspecialchars(ara_format_bytes(isset($s['memory_total']) ? (int)$s['memory_total'] : null)) ?></div></div>
                <div class="col-6 col-md-3"><div class="stat-label">Dernière synchronisation</div><div class="fw-semibold"><?= htmlspecialchars((string)($s['received_at'] ?? '—')) ?></div></div>
            </div>
            <?php if ($routerState['age_seconds'] !== null): ?>
                <div class="mt-3 text-muted small"><i class="bi bi-clock-history"></i> Âge du dernier snapshot : <?= (int)$routerState['age_seconds'] ?> s. Seuil ONLINE : <?= DASHBOARD_ROUTER_ONLINE_THRESHOLD ?> s.</div>
            <?php endif; ?>
            <div class="mt-3"><a href="status.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-wifi"></i> Voir le statut détaillé</a></div>
        </div>
    </div>
    <div class="card card-custom" id="business-section">
        <div class="card-header card-header-custom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-briefcase"></i> Gestion Business</span>
            <select id="period-select" class="form-select form-select-sm" style="width:auto;min-width:150px;">
                <option value="today">Aujourd'hui</option><option value="yesterday">Hier</option><option value="7days">7 derniers jours</option><option value="thismonth" selected>Ce mois</option><option value="lastmonth">Mois précédent</option>
            </select>
        </div>
        <div class="card-body">
            <div class="row" id="kpi-cards">
                <div class="col-md-3 col-6 mb-3"><div class="card card-custom text-center p-3 mb-0"><div class="stat-value" id="kpi-revenue">--</div><div class="stat-label">Chiffre d'affaires</div></div></div>
                <div class="col-md-3 col-6 mb-3"><div class="card card-custom text-center p-3 mb-0"><div class="stat-value" id="kpi-tickets">--</div><div class="stat-label">Tickets vendus</div></div></div>
                <div class="col-md-3 col-6 mb-3"><div class="card card-custom text-center p-3 mb-0"><div class="stat-value" id="kpi-sessions">--</div><div class="stat-label">Sessions actives</div></div></div>
                <div class="col-md-3 col-6 mb-3"><div class="card card-custom text-center p-3 mb-0"><div class="stat-value" id="kpi-subs">--</div><div class="stat-label">Abonnements</div></div></div>
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
