<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/monitoring_data.php';
require_once __DIR__ . '/components/embedded-page.php';

$config = require __DIR__ . '/../config.php';
$pageTitle = 'Monitoring — ARA Tech WiFi';

$tab = (string)($_GET['tab'] ?? 'overview');
$tabs = ['overview' => 'Vue d’ensemble', 'status' => 'Statut réseau', 'logs' => 'Logs système'];
if (!isset($tabs[$tab])) $tab = 'overview';

$monitoring = [
    'snapshot' => null,
    'router_state' => ['status' => 'UNKNOWN', 'age_seconds' => null, 'last_at' => null],
    'users' => [],
    'logs' => [],
    'logs_count' => 0,
    'error' => null,
];

try {
    $pdo = ara_db_supabase();
    $monitoring['snapshot'] = ara_monitoring_latest_snapshot($pdo);
    $monitoring['router_state'] = ara_monitoring_router_state($monitoring['snapshot']);
    $monitoring['users'] = ara_monitoring_snapshot_users($monitoring['snapshot']);

    $date = (string)($_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    $monitoring['logs'] = ara_monitoring_logs($pdo, $date, '', 5);
    $monitoring['logs_count'] = ara_monitoring_log_count($pdo, $date);
} catch (Throwable $e) {
    error_log('[Monitoring] ' . $e->getMessage());
    $monitoring['error'] = 'Impossible de charger les données de monitoring depuis Supabase.';
}

$snapshot = $monitoring['snapshot'];
$routerState = $monitoring['router_state'];
$status = $routerState['status'];
$statusClass = ara_monitoring_status_class($status);

function ara_monitoring_embed(string $file): void
{
    if (!is_file($file)) {
        echo '<div class="alert alert-danger">Vue de monitoring indisponible.</div>';
        return;
    }
    try {
        ara_render_embedded_page($file);
    } catch (Throwable $e) {
        error_log('[Monitoring] embedded view failed: ' . $e->getMessage());
        echo '<div class="alert alert-danger"><strong>Impossible de charger cette vue.</strong></div>';
    }
}

require __DIR__ . '/header.php';
?>
<div class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h2 class="mb-1">Centre de Monitoring</h2><p class="text-muted mb-0">Données réelles du dernier snapshot MikroTik et des logs système reçus.</p></div>
        <span class="small text-muted">Source : Supabase / PostgreSQL</span>
    </div>

    <ul class="nav nav-tabs mb-4">
        <?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="monitoring.php?tab=<?= urlencode($key) ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a></li>
        <?php endforeach; ?>
    </ul>

    <?php if ($monitoring['error']): ?><div class="alert alert-danger"><?= htmlspecialchars($monitoring['error'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if ($tab === 'overview'): ?>
        <div class="row g-3">
            <div class="col-xl-6">
                <div class="card card-custom h-100">
                    <div class="card-header card-header-custom d-flex justify-content-between align-items-center"><span><i class="bi bi-hdd-network"></i> Statut réseau</span><span class="status-pill <?= $statusClass ?>"><span class="status-dot <?= $statusClass ?>"></span><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></div>
                    <div class="card-body">
                        <?php if (!$snapshot): ?>
                            <div class="text-muted py-3">Aucun snapshot MikroTik disponible.</div>
                        <?php else: ?>
                            <div class="row g-3 mb-3">
                                <div class="col-6"><div class="text-muted small">Routeur</div><div class="fw-semibold"><?= htmlspecialchars((string)($snapshot['router_identity'] ?? 'N/D'), ENT_QUOTES, 'UTF-8') ?></div></div>
                                <div class="col-6"><div class="text-muted small">RouterOS</div><div class="fw-semibold"><?= htmlspecialchars((string)($snapshot['router_version'] ?? 'N/D'), ENT_QUOTES, 'UTF-8') ?></div></div>
                                <div class="col-6"><div class="text-muted small">Sessions actives</div><div class="fw-semibold"><?= (int)($snapshot['active_count'] ?? 0) ?></div></div>
                                <div class="col-6"><div class="text-muted small">CPU</div><div class="fw-semibold"><?= $snapshot['cpu_load'] === null ? 'N/D' : number_format((float)$snapshot['cpu_load'], 0, ',', ' ') . ' %' ?></div></div>
                            </div>
                            <div class="small text-muted mb-3"><i class="bi bi-clock-history"></i> Dernière synchronisation : <?= htmlspecialchars((string)($snapshot['received_at'] ?? (($snapshot['snapshot_date'] ?? '') . ' ' . ($snapshot['snapshot_time'] ?? ''))), ENT_QUOTES, 'UTF-8') ?> · âge <?= $routerState['age_seconds'] === null ? 'N/D' : (int)$routerState['age_seconds'] . ' s' ?></div>
                        <?php endif; ?>
                        <a class="btn btn-orange btn-sm" href="monitoring.php?tab=status"><i class="bi bi-arrow-right-circle"></i> Voir plus</a>
                    </div>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="card card-custom h-100">
                    <div class="card-header card-header-custom d-flex justify-content-between align-items-center"><span><i class="bi bi-journal-text"></i> Logs système</span><span class="badge text-bg-light border"><?= (int)$monitoring['logs_count'] ?> entrées aujourd’hui</span></div>
                    <div class="card-body p-0">
                        <?php if (!$monitoring['logs']): ?>
                            <div class="text-muted text-center py-5">Aucun log reçu aujourd’hui.</div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach (array_reverse($monitoring['logs']) as $log): ?>
                                    <div class="list-group-item py-2"><div class="d-flex justify-content-between gap-2"><span class="fw-semibold small"><?= htmlspecialchars((string)($log['topics'] ?? 'system'), ENT_QUOTES, 'UTF-8') ?></span><span class="text-muted small"><?= htmlspecialchars((string)($log['log_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></div><div class="small text-muted text-truncate"><?= htmlspecialchars((string)($log['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="p-3 border-top"><a class="btn btn-orange btn-sm" href="monitoring.php?tab=logs"><i class="bi bi-arrow-right-circle"></i> Voir plus</a></div>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($tab === 'status'): ?>
        <?php ara_monitoring_embed(__DIR__ . '/partials/monitoring/status.php'); ?>
    <?php elseif ($tab === 'logs'): ?>
        <?php ara_monitoring_embed(__DIR__ . '/partials/monitoring/logs.php'); ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
