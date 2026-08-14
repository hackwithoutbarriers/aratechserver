<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require_once __DIR__ . '/components/embedded-page.php';
require_once __DIR__ . '/../lib/api_client.php';
$config = require __DIR__ . '/../config.php';
$pageTitle = 'Monitoring — ARA Tech WiFi';

$tab = $_GET['tab'] ?? 'overview';
$tabs = ['overview' => 'Vue d’ensemble', 'status' => 'Statut réseau', 'logs' => 'Logs système'];
if (!isset($tabs[$tab])) {
    $tab = 'overview';
}

$monitoring = [
    'status' => 'UNKNOWN',
    'router' => [],
    'age_seconds' => null,
    'logs' => [],
    'logs_count' => 0,
    'logs_error' => null,
];

if ($tab === 'overview') {
    $statusResult = ara_api_call($config, 'status');
    if ($statusResult['success']) {
        $data = $statusResult['data'] ?? [];
        $router = is_array($data['router'] ?? null) ? $data['router'] : [];
        $monitoring['status'] = (string)($router['status'] ?? 'UNKNOWN');
        $monitoring['router'] = $router;
        $monitoring['age_seconds'] = isset($router['age_seconds']) ? (int)$router['age_seconds'] : null;
    }

    $date = (string)($_GET['date'] ?? date('Y-m-d'));
    $logsResult = ara_api_call($config, 'get-logs', ['date' => $date]);
    if ($logsResult['success']) {
        $data = $logsResult['data'] ?? [];
        $monitoring['logs'] = array_slice(is_array($data['logs'] ?? null) ? $data['logs'] : [], -5);
        $monitoring['logs_count'] = (int)($data['count'] ?? count($monitoring['logs']));
    } else {
        $monitoring['logs_error'] = $logsResult['message'];
    }
}

function monitoring_status_class(string $status): string
{
    return match ($status) {
        'ONLINE' => 'online',
        'OFFLINE' => 'offline',
        default => 'unknown',
    };
}

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
        echo '<div class="alert alert-danger"><strong>Impossible de charger cette vue de monitoring.</strong> Vérifie les dépendances de la page concernée.</div>';
    }
}

require __DIR__ . '/header.php';
?>
<div class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h2 class="mb-1">Centre de Monitoring</h2>
            <p class="text-muted mb-0">État réseau et événements système dans un espace unique.</p>
        </div>
    </div>

    <ul class="nav nav-tabs mb-4">
        <?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="monitoring.php?tab=<?= urlencode($key) ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a></li>
        <?php endforeach; ?>
    </ul>

    <?php if ($tab === 'overview'): ?>
        <div class="row g-3">
            <div class="col-xl-6">
                <div class="card card-custom h-100">
                    <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-hdd-network"></i> Statut réseau</span>
                        <span class="status-pill <?= monitoring_status_class($monitoring['status']) ?>"><span class="status-dot <?= monitoring_status_class($monitoring['status']) ?>"></span><?= htmlspecialchars($monitoring['status'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="card-body">
                        <?php $router = $monitoring['router']; ?>
                        <div class="row g-3 mb-3">
                            <div class="col-6"><div class="text-muted small">Routeur</div><div class="fw-semibold"><?= htmlspecialchars((string)($router['identity'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div></div>
                            <div class="col-6"><div class="text-muted small">Version</div><div class="fw-semibold"><?= htmlspecialchars((string)($router['version'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div></div>
                            <div class="col-6"><div class="text-muted small">Sessions actives</div><div class="fw-semibold"><?= isset($router['active_count']) ? (int)$router['active_count'] : '—' ?></div></div>
                            <div class="col-6"><div class="text-muted small">CPU</div><div class="fw-semibold"><?= isset($router['cpu']) ? ((int)$router['cpu'] . ' %') : '—' ?></div></div>
                        </div>
                        <div class="small text-muted mb-3"><i class="bi bi-clock-history"></i> Dernier snapshot : <?= htmlspecialchars((string)($router['last_snapshot'] ?? '—'), ENT_QUOTES, 'UTF-8') ?> · âge <?= $monitoring['age_seconds'] === null ? '—' : (int)$monitoring['age_seconds'] . ' s' ?></div>
                        <a class="btn btn-orange btn-sm" href="monitoring.php?tab=status"><i class="bi bi-arrow-right-circle"></i> Voir plus</a>
                    </div>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="card card-custom h-100">
                    <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-journal-text"></i> Logs système</span>
                        <span class="badge text-bg-light border"><?= (int)$monitoring['logs_count'] ?> entrées</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if ($monitoring['logs_error']): ?>
                            <div class="alert alert-warning m-3 mb-0"><?= htmlspecialchars((string)$monitoring['logs_error'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php elseif (!$monitoring['logs']): ?>
                            <div class="text-muted text-center py-5">Aucun log pour la période sélectionnée.</div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach (array_reverse($monitoring['logs']) as $log): ?>
                                    <div class="list-group-item py-2">
                                        <div class="d-flex justify-content-between gap-2"><span class="fw-semibold small"><?= htmlspecialchars((string)($log['topics'] ?? 'system'), ENT_QUOTES, 'UTF-8') ?></span><span class="text-muted small"><?= htmlspecialchars((string)($log['log_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></div>
                                        <div class="small text-muted text-truncate"><?= htmlspecialchars((string)($log['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
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
