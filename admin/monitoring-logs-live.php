<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/monitoring_data.php';

$pageTitle = 'Logs système — ARA Tech WiFi';
$date = (string)($_GET['date'] ?? date('Y-m-d'));
$topic = trim((string)($_GET['topic'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$logs = [];
$count = 0;
$error = null;
try {
    $pdo = ara_db_supabase();
    $count = ara_monitoring_log_count($pdo, $date, $topic);
    $logs = ara_monitoring_logs($pdo, $date, $topic, 500);
} catch (Throwable $e) {
    error_log('[Monitoring logs live] ' . $e->getMessage());
    $error = 'Impossible de charger les logs système depuis Supabase.';
}

require __DIR__ . '/header.php';
?>
<div class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="mb-1">Logs système</h2><p class="text-muted mb-0">Logs réellement reçus du routeur et stockés dans <code>router_logs</code>.</p></div><a href="monitoring.php?tab=overview" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Vue d’ensemble</a></div>
    <div class="card card-custom mb-3"><div class="card-body"><form method="get" class="row g-2 align-items-end"><div class="col-md-3"><label class="form-label">Date</label><input type="date" class="form-control" name="date" value="<?= htmlspecialchars($date,ENT_QUOTES,'UTF-8') ?>"></div><div class="col-md-3"><label class="form-label">Topic</label><input type="text" class="form-control" name="topic" placeholder="hotspot, system, warning…" value="<?= htmlspecialchars($topic,ENT_QUOTES,'UTF-8') ?>"></div><div class="col-md-2"><button class="btn btn-orange w-100" type="submit"><i class="bi bi-funnel"></i> Filtrer</button></div><div class="col-md-4 text-md-end"><span class="text-muted small"><?= number_format($count,0,',',' ') ?> entrée(s)</span></div></form></div></div>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php elseif (!$logs): ?><div class="alert alert-info">Aucun log trouvé pour cette date et ce filtre.</div><?php else: ?>
        <div class="card card-custom"><div class="card-header card-header-custom d-flex justify-content-between align-items-center"><span><i class="bi bi-journal-text"></i> <?= htmlspecialchars($date,ENT_QUOTES,'UTF-8') ?></span><span class="badge text-bg-light border"><?= number_format($count,0,',',' ') ?> entrées</span></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-striped table-hover mb-0"><thead class="table-dark"><tr><th>Heure</th><th>Topics</th><th>Message</th><th>Reçu</th></tr></thead><tbody><?php foreach($logs as $log): ?><tr><td><?= htmlspecialchars((string)($log['log_time']??''),ENT_QUOTES,'UTF-8') ?></td><td><span class="badge text-bg-light border"><?= htmlspecialchars((string)($log['topics']??''),ENT_QUOTES,'UTF-8') ?></span></td><td><?= htmlspecialchars((string)($log['message']??''),ENT_QUOTES,'UTF-8') ?></td><td class="text-muted small"><?= htmlspecialchars((string)($log['received_at']??''),ENT_QUOTES,'UTF-8') ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
