<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/monitoring_data.php';

$pageTitle = 'Statut réseau — ARA Tech WiFi';
$snapshot = null;
$state = ['status'=>'UNKNOWN','age_seconds'=>null,'last_at'=>null];
$users = [];
$error = null;
try {
    $pdo = ara_db_supabase();
    $snapshot = ara_monitoring_latest_snapshot($pdo);
    $state = ara_monitoring_router_state($snapshot);
    $users = ara_monitoring_snapshot_users($snapshot);
} catch (Throwable $e) {
    error_log('[Monitoring status live] ' . $e->getMessage());
    $error = 'Impossible de charger le dernier snapshot MikroTik depuis Supabase.';
}
$statusClass = ara_monitoring_status_class($state['status']);
$total = $snapshot && $snapshot['memory_total'] !== null ? (int)$snapshot['memory_total'] : null;
$free = $snapshot && $snapshot['memory_free'] !== null ? (int)$snapshot['memory_free'] : null;
$usedPct = ($total !== null && $total > 0 && $free !== null) ? max(0, min(100, (($total - $free) / $total) * 100)) : null;
$alerts = [];
if ($state['status'] === 'OFFLINE') $alerts[] = ['type'=>'danger','title'=>'Synchronisation périmée','message'=>'Aucun heartbeat récent du MikroTik depuis plus de 360 secondes.'];
if ($state['status'] === 'UNKNOWN') $alerts[] = ['type'=>'secondary','title'=>'Aucun état exploitable','message'=>'Aucun snapshot valide n’est disponible.'];
if ($snapshot && $snapshot['cpu_load'] !== null && (float)$snapshot['cpu_load'] >= 80) $alerts[] = ['type'=>'warning','title'=>'CPU élevé','message'=>'La charge CPU remontée est supérieure ou égale à 80 %.'];
if ($usedPct !== null && $usedPct >= 85) $alerts[] = ['type'=>'warning','title'=>'Mémoire élevée','message'=>'La mémoire utilisée dépasse 85 %.'];

require __DIR__ . '/header.php';
?>
<style>
.m-live-hero{padding:1.25rem 1.5rem}.m-live-state{font-size:1.45rem;font-weight:700}.m-live-state.online{color:#198754}.m-live-state.offline{color:#dc3545}.m-live-state.unknown{color:#6c757d}.m-live-dot{display:inline-block;width:12px;height:12px;border-radius:50%;margin-right:.5rem}.m-live-dot.online{background:#198754}.m-live-dot.offline{background:#dc3545}.m-live-dot.unknown{background:#adb5bd}.m-live-kpi{border:1px solid #edf0f3;border-radius:10px;padding:.85rem;height:100%}.m-live-kpi .value{font-size:1.1rem;font-weight:700;color:var(--bleu-nuit)}.m-live-kpi .label{font-size:.72rem;color:#6c757d;text-transform:uppercase}.m-live-table{min-width:820px}.m-live-table th,.m-live-table td{white-space:nowrap}
</style>
<div class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="mb-1">Statut réseau</h2><p class="text-muted mb-0">Mesures réelles du dernier push MikroTik.</p></div><a href="monitoring.php?tab=overview" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Vue d’ensemble</a></div>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <div class="card card-custom m-live-hero mb-3"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3"><div><div class="small text-muted">MikroTik</div><div class="m-live-state <?= $statusClass ?>"><span class="m-live-dot <?= $statusClass ?>"></span><?= htmlspecialchars($state['status'], ENT_QUOTES, 'UTF-8') ?></div></div><div class="text-end"><div class="small text-muted">Dernier snapshot</div><strong><?= htmlspecialchars((string)($snapshot['received_at'] ?? 'N/D'), ENT_QUOTES, 'UTF-8') ?></strong><div class="small text-muted">Âge : <?= $state['age_seconds'] === null ? 'N/D' : (int)$state['age_seconds'] . ' s' ?></div></div></div></div>
    <?php if ($alerts): ?><div class="card card-custom mb-3"><div class="card-header card-header-custom"><i class="bi bi-exclamation-triangle"></i> Alertes</div><div class="card-body"><?php foreach ($alerts as $a): ?><div class="alert alert-<?= $a['type'] ?> mb-2"><strong><?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?></strong><div class="small"><?= htmlspecialchars($a['message'], ENT_QUOTES, 'UTF-8') ?></div></div><?php endforeach; ?></div></div><?php else: ?><div class="alert alert-success"><i class="bi bi-check-circle"></i> Aucun seuil d’alerte détecté.</div><?php endif; ?>
    <div class="row g-3 mb-3">
        <?php $items=[['Identité',$snapshot['router_identity']??'N/D'],['RouterOS',$snapshot['router_version']??'N/D'],['Uptime',$snapshot['router_uptime']??'N/D'],['Sessions actives',(string)(int)($snapshot['active_count']??0)],['CPU',$snapshot&&$snapshot['cpu_load']!==null?number_format((float)$snapshot['cpu_load'],0,',',' ').' %':'N/D'],['Mémoire libre',ara_monitoring_format_bytes($free)],['Mémoire totale',ara_monitoring_format_bytes($total)],['Mémoire utilisée',$usedPct===null?'N/D':number_format($usedPct,1,',',' ').' %']]; foreach($items as [$label,$value]): ?><div class="col-6 col-xl-3"><div class="m-live-kpi"><div class="label"><?= htmlspecialchars($label,ENT_QUOTES,'UTF-8') ?></div><div class="value"><?= htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8') ?></div></div></div><?php endforeach; ?>
    </div>
    <div class="card card-custom"><div class="card-header card-header-custom"><i class="bi bi-people"></i> Utilisateurs actifs — dernier snapshot</div><div class="card-body p-0"><?php if(!$users): ?><div class="text-center text-muted py-5">Aucune session active remontée.</div><?php else: ?><div class="table-responsive"><table class="table table-striped mb-0 m-live-table"><thead class="table-dark"><tr><th>#</th><th>Utilisateur</th><th>IP</th><th>MAC</th><th>Profil</th><th>Uptime</th><th>Entrant</th><th>Sortant</th><th>Serveur</th></tr></thead><tbody><?php foreach($users as $i=>$u): ?><tr><td><?= $i+1 ?></td><td><?= htmlspecialchars((string)($u['user']??''),ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)($u['ip']??''),ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)($u['mac']??''),ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)($u['profile']??'N/D'),ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)($u['uptime']??'N/D'),ENT_QUOTES,'UTF-8') ?></td><td><?= ara_monitoring_format_bytes(isset($u['bytes_in'])?(int)$u['bytes_in']:null) ?></td><td><?= ara_monitoring_format_bytes(isset($u['bytes_out'])?(int)$u['bytes_out']:null) ?></td><td><?= htmlspecialchars((string)($u['server']??'N/D'),ENT_QUOTES,'UTF-8') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
