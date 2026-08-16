<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/hotspot_csv_import.php';
require_once __DIR__ . '/../lib/hotspot_inventory.php';

$embedded = ((string)($_GET['embed'] ?? '') === '1');
$pageTitle = 'Stock Hotspot — ARA Tech WiFi';

if (empty($_SESSION['hotspot_stock_csrf'])) {
    $_SESSION['hotspot_stock_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['hotspot_stock_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Requête refusée : token CSRF invalide.');
        }

        $action = (string)($_POST['action'] ?? '');
        $pdo = ara_db_supabase();

        if ($action === 'preview') {
            $file = $_FILES['csv_file'] ?? [];
            hotspot_csv_validate_upload($file);
            $parsed = hotspot_csv_read((string)$file['tmp_name'], $pdo);
            $_SESSION['hotspot_stock_preview'] = [
                'created_at' => time(),
                'source_name' => basename((string)$file['name']),
                'delimiter' => $parsed['delimiter'],
                'unknown_headers' => $parsed['unknown_headers'],
                'rows' => $parsed['rows'],
            ];
            header('Location: ' . ($embedded ? 'operations.php?tab=inventory&preview=1' : 'stock.php?preview=1'));
            exit;
        }

        if ($action === 'confirm') {
            $preview = $_SESSION['hotspot_stock_preview'] ?? null;
            if (!is_array($preview) || empty($preview['rows'])) {
                throw new RuntimeException('Aucune prévisualisation à confirmer.');
            }
            if ((int)($preview['created_at'] ?? 0) < time() - 1800) {
                unset($_SESSION['hotspot_stock_preview']);
                throw new RuntimeException('La prévisualisation a expiré. Veuillez sélectionner à nouveau le CSV.');
            }

            $pdo->beginTransaction();
            try {
                $report = hotspot_csv_import_rows($pdo, $preview['rows']);
                foreach ($preview['rows'] as $row) {
                    if (!empty($row['errors'])) continue;
                    hotspot_inventory_upsert($pdo, $row['username'], $row['profile'], [
                        'time_limit' => $row['time_limit'],
                        'data_limit' => $row['data_limit'],
                        'comment' => $row['comment'],
                        'source_name' => $preview['source_name'],
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            unset($_SESSION['hotspot_stock_preview']);
            $_SESSION['hotspot_stock_report'] = $report;
            header('Location: ' . ($embedded ? 'operations.php?tab=inventory&done=1' : 'stock.php?done=1'));
            exit;
        }

        if ($action === 'cancel') {
            unset($_SESSION['hotspot_stock_preview']);
            header('Location: ' . ($embedded ? 'operations.php?tab=inventory' : 'stock.php'));
            exit;
        }

        throw new RuntimeException('Action d’import inconnue.');
    } catch (Throwable $e) {
        $_SESSION['hotspot_stock_error'] = $e->getMessage();
        header('Location: ' . ($embedded ? 'operations.php?tab=inventory' : 'stock.php'));
        exit;
    }
}

$preview = $_SESSION['hotspot_stock_preview'] ?? null;
$report = $_SESSION['hotspot_stock_report'] ?? null;
$error = $_SESSION['hotspot_stock_error'] ?? null;
unset($_SESSION['hotspot_stock_report'], $_SESSION['hotspot_stock_error']);

$pdo = null;
$counts = ['available' => 0, 'used' => 0, 'total' => 0];
$available = [];
$usedToday = 0;
$dbError = null;

try {
    $pdo = ara_db_supabase();
    hotspot_inventory_consume_logged_in($pdo);
    $counts = hotspot_inventory_counts($pdo);
    $available = hotspot_inventory_list($pdo, 'AVAILABLE', 500);
    $usedStmt = $pdo->query("SELECT COUNT(*) FROM hotspot_inventory WHERE status = 'USED' AND consumed_at >= CURRENT_DATE");
    $usedToday = (int)$usedStmt->fetchColumn();
} catch (Throwable $e) {
    $dbError = 'Impossible de charger le stock Hotspot : ' . $e->getMessage();
}

$stats = ['total' => 0, 'valid' => 0, 'invalid' => 0];
$previewErrors = [];
if (is_array($preview) && !empty($preview['rows'])) {
    $stats['total'] = count($preview['rows']);
    foreach ($preview['rows'] as $row) {
        if (!empty($row['errors'])) {
            $stats['invalid']++;
            $previewErrors[] = [
                'line' => $row['line'],
                'username' => $row['username'],
                'error' => implode(' ', $row['errors']),
            ];
        } else {
            $stats['valid']++;
        }
    }
}

if (!$embedded) require_once __DIR__ . '/header.php';
?>
<style>
.stock-hero{border:1px solid #e9edf2;border-radius:14px;background:#fff;padding:1.15rem}.stock-kpi{border:1px solid #e9edf2;border-radius:12px;background:#fff;padding:1rem;height:100%}.stock-kpi .value{font-size:1.6rem;font-weight:700;color:var(--bleu-nuit)}.stock-kpi .label{font-size:.74rem;text-transform:uppercase;letter-spacing:.04em;color:#6c757d}.stock-state{font-size:.8rem}.stock-state.available{color:#198754}.stock-state.used{color:#6c757d}.stock-table{min-width:760px}
</style>
<div class="container-fluid <?= $embedded ? 'px-0' : 'px-3 px-md-4 py-4' ?>">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h3 class="mb-1">Stock Hotspot</h3><p class="text-muted mb-0">Import Mikhmon → stock disponible → consommation au premier login détecté.</p></div>
        <span class="small text-muted">Source de consommation : <code>sales_log</code></span>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($dbError): ?><div class="alert alert-danger"><?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><div class="stock-kpi"><div class="label">Stock disponible</div><div class="value"><?= number_format($counts['available'],0,',',' ') ?></div><div class="stock-state available">Prêts à vendre</div></div></div>
        <div class="col-6 col-xl-3"><div class="stock-kpi"><div class="label">Utilisés</div><div class="value"><?= number_format($counts['used'],0,',',' ') ?></div><div class="stock-state used">Conservés dans l’historique</div></div></div>
        <div class="col-6 col-xl-3"><div class="stock-kpi"><div class="label">Total importé</div><div class="value"><?= number_format($counts['total'],0,',',' ') ?></div><div class="text-muted small">Disponible + utilisé</div></div></div>
        <div class="col-6 col-xl-3"><div class="stock-kpi"><div class="label">Consommés aujourd’hui</div><div class="value"><?= number_format($usedToday,0,',',' ') ?></div><div class="text-muted small">Logins détectés depuis minuit</div></div></div>
    </div>

    <?php if ($report): ?>
        <div class="alert alert-success"><strong>Import terminé.</strong> <?= (int)$report['valid'] ?> utilisateur(s) valides ont été ajoutés/mis à jour dans le stock.</div>
    <?php endif; ?>

    <?php if ($preview): ?>
        <div class="card card-custom mb-4">
            <div class="card-header card-header-custom">Prévisualisation — <?= htmlspecialchars((string)($preview['source_name'] ?? 'CSV'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="card-body">
                <?php if (!empty($preview['unknown_headers'])): ?><div class="alert alert-warning">Colonnes supplémentaires ignorées : <?= htmlspecialchars(implode(', ', $preview['unknown_headers']), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <div class="row g-3 mb-3">
                    <?php foreach ([['Total',$stats['total']],['Valides',$stats['valid']],['Invalides',$stats['invalid']]] as $kpi): ?><div class="col-4"><div class="border rounded p-3 text-center"><div class="h4 mb-0"><?= (int)$kpi[1] ?></div><small class="text-muted"><?= htmlspecialchars($kpi[0], ENT_QUOTES, 'UTF-8') ?></small></div></div><?php endforeach; ?>
                </div>
                <?php if ($previewErrors): ?><div class="alert alert-danger"><strong>Des lignes seront rejetées.</strong></div><div class="table-responsive mb-3"><table class="table table-sm"><thead><tr><th>Ligne</th><th>Username</th><th>Erreur</th></tr></thead><tbody><?php foreach ($previewErrors as $err): ?><tr><td><?= htmlspecialchars((string)$err['line']) ?></td><td><?= htmlspecialchars((string)$err['username']) ?></td><td><?= htmlspecialchars((string)$err['error']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
                <form method="post" class="d-flex gap-2"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><button class="btn btn-success" name="action" value="confirm" <?= $stats['valid'] === 0 ? 'disabled' : '' ?>>Confirmer l’import de <?= (int)$stats['valid'] ?> utilisateur(s)</button><button class="btn btn-outline-secondary" name="action" value="cancel">Annuler</button></form>
            </div>
        </div>
    <?php else: ?>
        <div class="card card-custom mb-4">
            <div class="card-header card-header-custom"><i class="bi bi-cloud-upload"></i> Import Mikhmon</div>
            <div class="card-body">
                <form method="post" action="stock.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="preview">
                    <div class="mb-3"><label class="form-label fw-semibold">CSV des utilisateurs générés dans Mikhmon</label><input type="file" class="form-control" name="csv_file" accept=".csv,text/csv" required></div>
                    <div class="alert alert-light border mb-3"><strong>Colonnes :</strong> <code>Username, Password, Profile, Time Limit, Data Limit, Comment</code><br><small>Le mot de passe n’est jamais affiché dans le stock.</small></div>
                    <button class="btn btn-orange" type="submit"><i class="bi bi-search"></i> Valider et prévisualiser</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="card card-custom">
        <div class="card-header card-header-custom d-flex justify-content-between align-items-center"><span><i class="bi bi-box-seam"></i> Stock disponible</span><span class="badge text-bg-light border"><?= number_format($counts['available'],0,',',' ') ?> restant(s)</span></div>
        <div class="card-body p-0">
            <?php if (!$available): ?><div class="text-center text-muted py-5">Le stock est vide. Importez les utilisateurs générés dans Mikhmon.</div>
            <?php else: ?><div class="table-responsive"><table class="table table-striped table-hover mb-0 stock-table"><thead class="table-dark"><tr><th>Username</th><th>Profil</th><th>Importé le</th><th>Source</th><th>État</th></tr></thead><tbody><?php foreach ($available as $item): ?><tr><td><code><?= htmlspecialchars((string)$item['username'], ENT_QUOTES, 'UTF-8') ?></code></td><td><?= htmlspecialchars((string)($item['profile'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)($item['imported_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)($item['source'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><td><span class="badge bg-success">Disponible</span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </div>
    </div>

    <div class="alert alert-info mt-3 mb-0"><i class="bi bi-info-circle"></i> Lorsqu’un utilisateur importé se connecte, son premier événement présent dans <code>sales_log</code> après l’import le fait passer automatiquement en <strong>Utilisé</strong>. L’utilisateur n’est pas supprimé de <code>hotspot_users</code> : cette table reste le miroir opérationnel MikroTik.</div>
</div>
<?php if (!$embedded) require __DIR__ . '/footer.php'; ?>