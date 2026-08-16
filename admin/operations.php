<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/hotspot_inventory.php';
require_once __DIR__ . '/components/embedded-page.php';

$config = require __DIR__ . '/../config.php';
$pageTitle = 'Opérations — ARA Tech WiFi';

$requestedTab = (string)($_GET['tab'] ?? 'overview');
$tabs = [
    'overview' => 'Vue d’ensemble',
    'hotspot' => 'Hotspot',
    'inventory' => 'Stock & import',
];
$tab = array_key_exists($requestedTab, $tabs) ? $requestedTab : 'overview';
$legacyTab = (string)($_GET['legacy_tab'] ?? '');
if ($legacyTab === 'vouchers' && $tab === 'hotspot') {
    $tab = 'inventory';
}

$ops = [
    'users_total' => null,
    'users_active' => null,
    'users_disabled' => null,
    'profiles' => null,
    'sessions' => null,
    'stock_available' => null,
    'stock_used' => null,
    'sync_at' => null,
    'sync_age' => null,
    'error' => null,
];

try {
    $pdo = ara_db_supabase();

    $ops['users_total'] = (int)$pdo->query('SELECT COUNT(*) FROM hotspot_users')->fetchColumn();
    $ops['users_disabled'] = (int)$pdo->query("SELECT COUNT(*) FROM hotspot_users WHERE LOWER(disabled) = 'true'")->fetchColumn();
    $ops['users_active'] = max(0, $ops['users_total'] - $ops['users_disabled']);
    $ops['profiles'] = (int)$pdo->query('SELECT COUNT(*) FROM hotspot_profiles')->fetchColumn();

    try {
        hotspot_inventory_consume_logged_in($pdo);
        $stock = hotspot_inventory_counts($pdo);
        $ops['stock_available'] = $stock['available'];
        $ops['stock_used'] = $stock['used'];
    } catch (Throwable $ignored) {
        // Migration 015 may not yet be deployed; Operations must remain usable.
    }

    $snapshotStmt = $pdo->query(
        'SELECT active_count, received_at, snapshot_date, snapshot_time
         FROM hotspot_snapshots
         ORDER BY id DESC
         LIMIT 1'
    );
    $snapshot = $snapshotStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($snapshot) {
        $ops['sessions'] = (int)($snapshot['active_count'] ?? 0);
        $ops['sync_at'] = (string)($snapshot['received_at'] ?? (($snapshot['snapshot_date'] ?? '') . ' ' . ($snapshot['snapshot_time'] ?? '')));
        try {
            $last = new DateTimeImmutable($ops['sync_at']);
            $ops['sync_age'] = max(0, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp() - $last->getTimestamp());
        } catch (Throwable $ignored) {
            $ops['sync_age'] = null;
        }
    }
} catch (Throwable $e) {
    error_log('[Operations] summary load failed: ' . $e->getMessage());
    $ops['error'] = 'Les indicateurs opérationnels ne sont pas disponibles pour le moment.';
}

function ara_operations_format_age(?int $seconds): string
{
    if ($seconds === null) return 'N/D';
    if ($seconds < 60) return $seconds . ' s';
    if ($seconds < 3600) return (int)floor($seconds / 60) . ' min';
    return (int)floor($seconds / 3600) . ' h';
}

function ara_operations_status_class(?int $age): string
{
    if ($age === null) return 'unknown';
    return $age <= 360 ? 'online' : 'offline';
}

function ara_operations_embed(string $file, array $query = []): void
{
    if (!is_file($file)) {
        echo '<div class="alert alert-danger">Vue opérationnelle indisponible.</div>';
        return;
    }
    try {
        ara_render_embedded_page($file, $query);
    } catch (Throwable $e) {
        error_log('[Operations] embedded view failed: ' . $e->getMessage());
        echo '<div class="alert alert-danger"><strong>Impossible de charger cette vue.</strong> Vérifie ses dépendances.</div>';
    }
}

require __DIR__ . '/header.php';
?>

<style>
.ops-kpi{border:1px solid #e9edf2;border-radius:12px;background:#fff;padding:1rem;height:100%;box-shadow:0 1px 3px rgba(0,0,0,.03)}
.ops-kpi .label{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:#6c757d}.ops-kpi .value{font-size:1.45rem;font-weight:700;color:var(--bleu-nuit)}.ops-kpi .meta{font-size:.78rem;color:#6c757d}
.ops-action{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem;border:1px solid #e9edf2;border-radius:12px;background:#fff;text-decoration:none;color:inherit;height:100%}.ops-action:hover{border-color:#f4a62a;box-shadow:0 4px 14px rgba(0,0,0,.05)}
.ops-action .icon{width:42px;height:42px;border-radius:10px;background:#f8f9fb;display:grid;place-items:center;font-size:1.2rem;color:var(--bleu-nuit)}
</style>

<div class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h2 class="mb-1">Opérations</h2><p class="text-muted mb-0">Centre de pilotage du Hotspot, des sessions et du stock Mikhmon.</p></div>
        <span class="small text-muted">Source : Supabase / PostgreSQL</span>
    </div>

    <ul class="nav nav-tabs mb-4">
        <?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="operations.php?tab=<?= urlencode($key) ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a></li>
        <?php endforeach; ?>
    </ul>

    <?php if ($ops['error']): ?><div class="alert alert-warning"><?= htmlspecialchars($ops['error'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if ($tab === 'overview'): ?>
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3"><div class="ops-kpi"><div class="label">Utilisateurs Hotspot</div><div class="value"><?= $ops['users_total'] === null ? 'N/D' : number_format($ops['users_total'],0,',',' ') ?></div><div class="meta"><?= $ops['users_active'] === null ? '—' : number_format($ops['users_active'],0,',',' ') . ' actifs' ?></div></div></div>
            <div class="col-6 col-xl-3"><div class="ops-kpi"><div class="label">Sessions actives</div><div class="value"><?= $ops['sessions'] === null ? 'N/D' : number_format($ops['sessions'],0,',',' ') ?></div><div class="meta">Dernier snapshot MikroTik</div></div></div>
            <div class="col-6 col-xl-3"><div class="ops-kpi"><div class="label">Stock disponible</div><div class="value"><?= $ops['stock_available'] === null ? 'N/D' : number_format($ops['stock_available'],0,',',' ') ?></div><div class="meta"><?= $ops['stock_used'] === null ? 'Migration stock requise' : number_format($ops['stock_used'],0,',',' ') . ' consommés' ?></div></div></div>
            <div class="col-6 col-xl-3"><div class="ops-kpi"><div class="label">Sync routeur</div><div class="value"><?= htmlspecialchars($ops['sync_age'] === null ? 'N/D' : ara_operations_format_age($ops['sync_age']), ENT_QUOTES, 'UTF-8') ?></div><div class="meta"><span class="status-dot <?= ara_operations_status_class($ops['sync_age']) ?>"></span><?= $ops['sync_age'] === null ? 'État inconnu' : ($ops['sync_age'] <= 360 ? 'À jour' : 'Périmée') ?></div></div></div>
        </div>

        <div class="row g-3">
            <div class="col-12 col-xl-6"><a class="ops-action" href="operations.php?tab=hotspot"><div class="d-flex align-items-center gap-3"><div class="icon"><i class="bi bi-wifi"></i></div><div><div class="fw-semibold">Hotspot</div><div class="small text-muted">Utilisateurs, sessions actives et profils techniques.</div></div></div><i class="bi bi-arrow-right"></i></a></div>
            <div class="col-12 col-xl-6"><a class="ops-action" href="operations.php?tab=inventory"><div class="d-flex align-items-center gap-3"><div class="icon"><i class="bi bi-box-seam"></i></div><div><div class="fw-semibold">Stock & import Mikhmon</div><div class="small text-muted">Importer les utilisateurs générés et suivre leur consommation.</div></div></div><i class="bi bi-arrow-right"></i></a></div>
        </div>

        <div class="card card-custom mt-4"><div class="card-header card-header-custom">Règle de stock</div><div class="card-body"><div class="text-muted">Un utilisateur importé est considéré comme <strong>disponible</strong> jusqu’au premier login détecté après son import. Il passe alors à <strong>utilisé</strong> et quitte le stock actif. L’utilisateur reste dans <code>hotspot_users</code> comme miroir MikroTik.</div></div></div>
    <?php elseif ($tab === 'hotspot'): ?>
        <?php ara_operations_embed(__DIR__ . '/partials/operations/hotspot.php', $legacyTab && in_array($legacyTab, ['users','active','profiles'], true) ? ['tab' => $legacyTab] : ['tab' => 'users']); ?>
    <?php else: ?>
        <?php ara_operations_embed(__DIR__ . '/partials/operations/inventory.php', [
            'preview' => $_GET['preview'] ?? null,
        ]); ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>