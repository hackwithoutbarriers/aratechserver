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
    'snapshot' => null,
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
        $monitoring['snapshot'] = $router;
        $monitoring['age_seconds'] = isset($router['age_seconds']) ? (int)$router['age_seconds'] : null;
    }

    $date = (string)($_GET['date'] ?? date('Y-m-d'));
    $logsResult = ara_api_call($config, 'get-logs', ['date' => $date]);
    if ($logsResult['success']) {
        $data = $logsResult['data'] ?? [];
        $monitoring['logs'] = array_slice(is_array($data['logs'] ?? null) ? $data['logs'] : [], -8);
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

require __DIR__ . '/header.php';
?>
<div class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h2 class="mb-1">Centre de Monitoring</h2>
            <p class="text-muted mb-0">Command center du système : état réseau, synchronisation et événements récents.</p>
        </div>
    </div>

    <ul class="nav nav-tabs mb-4">
        <?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="monitoring.php?tab=<?= urlencode($key) ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a></li>
        <?php endforeach; ?>
    </ul>

    <?php if ($tab === 'overview'): ?>
        <div class="row g-3">
            <div class="col-xl-7">
                <div class="card card-custom h-100">
                    <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-hdd-network"></i> État réseau</span>
                        <span class="status-pill <?= monitoring_status_class($monitoring['status']) ?>"><span class="status-dot <?= monitoring_status_class($monitoring['status']) ?>"></span><?= htmlspecialchars($monitoring['status'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?php
                            $router = $monitoring['snapshot'] ?? [];
                            $cards = [
                                ['Identité', $router['identity'] ?? '—', 'bi-hdd-network'],
                                ['Version', $router['version'] ?? '—', 'bi-cpu'],
                                ['Uptime', $router['uptime'] ?? '—', 'bi-clock-history'],
                                ['Sessions actives', $router['active_count'] ?? '—', 'bi-people'],
                                ['CPU', isset($router['cpu']) ? ((int)$router['cpu'] . ' %') : '—', 'bi-speedometer2'],
                                ['Dernier snapshot', $router['last_snapshot'] ?? '—', 'bi-arrow-repeat'],
                            ];
                            foreach ($cards as [$label, $value, $icon]):
                                $metricLabel = $label;
                                $metricValue = (string)$value;
                                $metricIcon = $icon;
                                $metricTone = 'primary';
                                require __DIR__ . '/components/metric-card.php';
                                unset($metricLabel, $metricValue, $metricIcon, $metricTone);
                            endforeach;
                            ?>
                        </div>
                        <div class="mt-3 text-muted small"><i class="bi bi-clock-history"></i> Âge du snapshot : <?= $monitoring['age_seconds'] === null ? '—' : (int)$monitoring['age_seconds'] . ' s' ?></div>
                        <a class="btn btn-orange btn-sm mt-3" href="monitoring.php?tab=status"><i class="bi bi-activity"></i> Ouvrir le statut réseau complet</a>
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="card card-custom h-100">
                    <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-journal-text"></i> Logs système</span>
                        <span class="badge text-bg-light border"><?= (int)$monitoring['logs_count'] ?> entrées</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if ($monitoring['logs_error']): ?>
                            <div class="alert alert-warning m-3 mb-0"><?= htmlspecialchars((string)$monitoring['logs_error'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php elseif (!$monitoring['logs']): ?>
                            <div class="text-muted text-center py-5">Aucun log pour aujourd’hui.</div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach (array_reverse($monitoring['logs']) as $log): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between gap-2">
                                            <span class="fw-semibold small"><?= htmlspecialchars((string)($log['topics'] ?? 'system'), ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="text-muted small"><?= htmlspecialchars((string)($log['log_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <div class="small text-muted mt-1"><?= htmlspecialchars((string)($log['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="p-3 border-top"><a class="btn btn-outline-primary btn-sm" href="monitoring.php?tab=logs"><i class="bi bi-list-ul"></i> Voir tous les logs</a></div>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($tab === 'status'): ?>
        <?php ara_render_embedded_page(__DIR__ . '/partials/monitoring/status.php'); ?>
    <?php elseif ($tab === 'logs'): ?>
        <?php ara_render_embedded_page(__DIR__ . '/partials/monitoring/logs.php'); ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
