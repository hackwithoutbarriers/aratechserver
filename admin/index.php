<?php
declare(strict_types=1);

/**
 * admin/index.php — Dashboard ARA Tech WiFi
 *
 * Le routeur MikroTik est derrière le CGNAT CanalBox : Render ne peut donc
 * pas ouvrir une connexion TCP entrante vers RouterOS. Le dashboard ne fait
 * plus de test RouterOS direct. Il lit le dernier snapshot poussé par le
 * MikroTik vers l'API/Supabase et considère le routeur ONLINE quand ce
 * snapshot est suffisamment récent.
 */

require __DIR__ . '/auth.php';
require __DIR__ . '/../db.php';

$config = require __DIR__ . '/../config.php';
$adminToken = $config['admin']['token'] ?? getenv('ADMIN_TOKEN');
$pageTitle = 'Tableau de bord - ARA Tech WiFi';

const DASHBOARD_ROUTER_ONLINE_THRESHOLD = 360;

function ara_format_bytes(?int $bytes): string
{
    if ($bytes === null) {
        return '—';
    }
    $units = ['o', 'Ko', 'Mo', 'Go'];
    $value = (float)$bytes;
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
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

        if (!$snapshot) {
            return [
                'status' => 'UNKNOWN',
                'age_seconds' => null,
                'snapshot' => null,
                'error' => null,
            ];
        }

        $last = null;
        if (!empty($snapshot['received_at'])) {
            try {
                $last = new DateTimeImmutable((string)$snapshot['received_at']);
            } catch (Throwable $e) {
                $last = null;
            }
        }
        if ($last === null && !empty($snapshot['snapshot_date']) && !empty($snapshot['snapshot_time'])) {
            $raw = $snapshot['snapshot_date'] . ' ' . $snapshot['snapshot_time'];
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, new DateTimeZone('UTC'));
            if ($parsed !== false) {
                $last = $parsed;
            }
        }

        if ($last === null) {
            return [
                'status' => 'UNKNOWN',
                'age_seconds' => null,
                'snapshot' => $snapshot,
                'error' => null,
            ];
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $age = max(0, $now->getTimestamp() - $last->getTimestamp());

        return [
            'status' => $age < DASHBOARD_ROUTER_ONLINE_THRESHOLD ? 'ONLINE' : 'OFFLINE',
            'age_seconds' => $age,
            'snapshot' => $snapshot,
            'error' => null,
        ];
    } catch (Throwable $e) {
        error_log('[Dashboard] Lecture hotspot_snapshots échouée : ' . $e->getMessage());
        return [
            'status' => 'UNKNOWN',
            'age_seconds' => null,
            'snapshot' => null,
            'error' => 'Impossible de lire le dernier état du MikroTik depuis Supabase.',
        ];
    }
}

$routerState = ara_router_snapshot();
$s = $routerState['snapshot'] ?? null;
$status = $routerState['status'];
$statusLabel = $status === 'ONLINE' ? 'Synchronisation active' : ($status === 'OFFLINE' ? 'Synchronisation interrompue' : 'Statut inconnu');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root { --bleu-nuit:#0b2c82; --bleu-nuit-dark:#081f5c; --orange:#f5a623; }
        body { background:#f4f6f9; font-family:'Segoe UI',sans-serif; overflow-x:hidden; }
        .app-shell { display:flex; min-height:100vh; }
        .sidebar { width:250px; flex-shrink:0; background:linear-gradient(180deg,var(--bleu-nuit) 0%,var(--bleu-nuit-dark) 100%); color:#fff; display:flex; flex-direction:column; }
        .sidebar-brand { font-weight:700; font-size:1.15rem; padding:1.25rem 1rem; border-bottom:3px solid var(--orange); }
        .sidebar-nav { padding:.75rem 0; flex:1; overflow-y:auto; }
        .sidebar-nav .nav-link { color:rgba(255,255,255,.85); padding:.65rem 1.1rem; font-size:.92rem; border-left:3px solid transparent; }
        .sidebar-nav .nav-link i { width:20px; margin-right:8px; color:var(--orange); }
        .sidebar-nav .nav-link:hover { background:rgba(255,255,255,.08); color:#fff; }
        .sidebar-nav .nav-link.active { background:rgba(255,255,255,.12); color:#fff; border-left-color:var(--orange); font-weight:600; }
        .sidebar-nav .nav-section-title { text-transform:uppercase; font-size:.7rem; letter-spacing:.05em; color:rgba(255,255,255,.45); padding:1rem 1.1rem .35rem; }
        .sidebar-nav .disabled-soon { color:rgba(255,255,255,.45); }
        .badge-soon { font-size:.62rem; background:rgba(245,166,35,.25); color:var(--orange); margin-left:6px; vertical-align:middle; }
        .sidebar-footer { padding:.85rem 1.1rem; border-top:1px solid rgba(255,255,255,.1); }
        .main-content { flex:1; min-width:0; }
        .topbar { background:#fff; border-bottom:1px solid #e5e7eb; padding:.85rem 1.25rem; display:flex; align-items:center; justify-content:space-between; }
        #sidebarToggle { display:none; }
        @media (max-width:991.98px) { .sidebar{position:fixed;left:-260px;top:0;bottom:0;z-index:1040;transition:left .2s ease}.sidebar.open{left:0}#sidebarToggle{display:inline-flex}.sidebar-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:1030}.sidebar-backdrop.open{display:block} }
        .card-custom { border:none; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08); margin-bottom:1.5rem; }
        .card-header-custom { background:var(--bleu-nuit); color:#fff; border-radius:12px 12px 0 0 !important; font-weight:600; }
        .stat-value { font-size:1.9rem; font-weight:700; color:var(--bleu-nuit); }
        .stat-label { font-size:.88rem; color:#6c757d; }
        .status-pill { display:inline-flex; align-items:center; gap:6px; padding:.3rem .75rem; border-radius:999px; font-weight:600; font-size:.85rem; }
        .status-pill.online { background:#d1f4dd; color:#0f7a3d; }
        .status-pill.offline { background:#fde1e1; color:#b02525; }
        .status-pill.unknown { background:#eef0f3; color:#6c757d; }
        .status-dot { width:9px; height:9px; border-radius:50%; display:inline-block; }
        .status-dot.online { background:#28a745; }
        .status-dot.offline { background:#dc3545; }
        .status-dot.unknown { background:#adb5bd; }
        .btn-orange { background:var(--orange); border:none; color:#fff; font-weight:600; }
        .btn-orange:hover { background:#e5941f; color:#fff; }
        .placeholder-zone { border:2px dashed #d7dce3; border-radius:12px; padding:2.5rem 1.5rem; text-align:center; color:#8a93a3; background:#fbfcfe; }
        .placeholder-zone i { font-size:2rem; color:#c3cad6; margin-bottom:.5rem; display:block; }
    </style>
</head>
<body>
<div class="app-shell">
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">⚡ ARA Tech WiFi</div>
        <nav class="sidebar-nav">
            <a class="nav-link active" href="index.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link" href="status.php"><i class="bi bi-wifi"></i> Statut détaillé</a>
            <a class="nav-link" href="hotspot.php"><i class="bi bi-people"></i> Hotspot</a>
            <a class="nav-link" href="inventory.php"><i class="bi bi-box-seam"></i> Stocks &amp; Import CSV</a>
            <a class="nav-link" href="finances.php"><i class="bi bi-cash-coin"></i> Finances</a>
            <a class="nav-link" href="reports.php"><i class="bi bi-graph-up"></i> Rapports</a>
            <a class="nav-link" href="ads.php"><i class="bi bi-megaphone"></i> Annonces</a>
            <a class="nav-link" href="logs.php"><i class="bi bi-journal-text"></i> Logs</a>
            <a class="nav-link" href="settings.php"><i class="bi bi-sliders"></i> Configuration</a>
            <div class="nav-section-title">À venir</div>
            <a class="nav-link disabled-soon" href="#business-section"><i class="bi bi-briefcase"></i> Gestion Business</a>
            <a class="nav-link disabled-soon" href="#wifizone-section"><i class="bi bi-router"></i> WiFi Zone <span class="badge badge-soon">Bientôt</span></a>
        </nav>
        <div class="sidebar-footer"><a href="logout.php" class="btn btn-outline-light btn-sm w-100"><i class="bi bi-box-arrow-right"></i> Déconnexion</a></div>
    </aside>

    <div class="main-content">
        <div class="topbar">
            <div class="d-flex align-items-center gap-2"><button id="sidebarToggle" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list"></i></button><h5 class="mb-0">Tableau de bord</h5></div>
            <span class="text-muted small">ARA Tech WiFi Admin</span>
        </div>

        <div class="container-fluid px-3 px-md-4 py-4">
            <div class="card card-custom" id="network-section">
                <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-hdd-network"></i> Statut Réseau — Synchronisation MikroTik</span>
                    <span class="status-pill <?= strtolower($status) ?>"><span class="status-dot <?= strtolower($status) ?>"></span><?= htmlspecialchars($statusLabel) ?></span>
                </div>

                    <?php elseif ($status === 'OFFLINE' && $s): ?>
                        <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
                            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                            <div>
                                <strong>Le dernier heartbeat du MikroTik est périmé.</strong>
                                <div class="small mt-1">Vérifie le scheduler et le script <code>push-hotspot-status.rsc</code> sur le routeur.</div>
                            </div>
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
                    <div class="mt-3"><a href="index.php" class="btn btn-sm btn-orange"><i class="bi bi-arrow-clockwise"></i> Actualiser l'état</a> <a href="status.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-wifi"></i> Statut détaillé</a></div>
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
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    document.getElementById('sidebarToggle')?.addEventListener('click', function(){ sidebar.classList.toggle('open'); backdrop.classList.toggle('open'); });
    backdrop.addEventListener('click', function(){ sidebar.classList.remove('open'); backdrop.classList.remove('open'); });

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
</body>
</html>
