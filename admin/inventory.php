<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/hotspot_csv_import.php';

$config = require __DIR__ . '/../config.php';
$pageTitle = 'Inventaire des codes WiFi - ARA Tech WiFi';

if (empty($_SESSION['hotspot_csv_csrf'])) {
    $_SESSION['hotspot_csv_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['hotspot_csv_csrf'];

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
            $_SESSION['hotspot_csv_preview'] = [
                'created_at' => time(),
                'source_name' => basename((string)$file['name']),
                'delimiter' => $parsed['delimiter'],
                'unknown_headers' => $parsed['unknown_headers'],
                'rows' => $parsed['rows'],
            ];
            header('Location: inventory.php?preview=1');
            exit;
        }

        if ($action === 'confirm') {
            $preview = $_SESSION['hotspot_csv_preview'] ?? null;
            if (!is_array($preview) || empty($preview['rows'])) {
                throw new RuntimeException('Aucune prévisualisation à confirmer.');
            }
            if ((int)($preview['created_at'] ?? 0) < time() - 1800) {
                unset($_SESSION['hotspot_csv_preview']);
                throw new RuntimeException('La prévisualisation a expiré. Veuillez sélectionner à nouveau le CSV.');
            }

            $pdo->beginTransaction();
            try {
                $pdo->query('SELECT 1 FROM hotspot_users LIMIT 1');
                $pdo->query('SELECT 1 FROM hotspot_profiles LIMIT 1');
                $pdo->query('SELECT 1 FROM hotspot_commands LIMIT 1');
                $report = hotspot_csv_import_rows($pdo, $preview['rows']);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            unset($_SESSION['hotspot_csv_preview']);
            $_SESSION['hotspot_csv_report'] = $report;
            header('Location: inventory.php?done=1');
            exit;
        }

        if ($action === 'cancel') {
            unset($_SESSION['hotspot_csv_preview']);
            header('Location: inventory.php');
            exit;
        }

        throw new RuntimeException('Action d’import inconnue.');
    } catch (Throwable $e) {
        $_SESSION['hotspot_csv_error'] = $e->getMessage();
        header('Location: inventory.php');
        exit;
    }
}

$preview = $_SESSION['hotspot_csv_preview'] ?? null;
$report = $_SESSION['hotspot_csv_report'] ?? null;
$error = $_SESSION['hotspot_csv_error'] ?? null;
unset($_SESSION['hotspot_csv_report'], $_SESSION['hotspot_csv_error']);

$stats = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'new' => 0, 'updated' => 0];
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
    try {
        $validUsernames = [];
        foreach ($preview['rows'] as $row) if (empty($row['errors'])) $validUsernames[] = $row['username'];
        $existing = hotspot_csv_lookup_existing(ara_db_supabase(), $validUsernames);
        foreach ($validUsernames as $username) {
            if (isset($existing[strtolower($username)])) $stats['updated']++; else $stats['new']++;
        }
    } catch (Throwable $e) {
        $previewErrors[] = ['line' => '—', 'username' => '—', 'error' => 'Vérification des utilisateurs existants impossible : ' . $e->getMessage()];
    }
}

$statusFilter = trim((string)($_GET['status'] ?? 'all'));
if (!in_array($statusFilter, ['all', 'active', 'disabled'], true)) $statusFilter = 'all';

$users = [];
$counts = ['all' => 0, 'active' => 0, 'disabled' => 0];
$dbError = '';
try {
    $pdo = ara_db_supabase();
    $counts['all'] = (int)$pdo->query('SELECT COUNT(*) FROM hotspot_users')->fetchColumn();
    $counts['disabled'] = (int)$pdo->query("SELECT COUNT(*) FROM hotspot_users WHERE LOWER(disabled) = 'true'")->fetchColumn();
    $counts['active'] = $counts['all'] - $counts['disabled'];

    $sql = 'SELECT username, profile, comment, disabled, limit_uptime, limit_bytes_total, last_sync FROM hotspot_users';
    if ($statusFilter === 'disabled') $sql .= " WHERE LOWER(disabled) = 'true'";
    if ($statusFilter === 'active') $sql .= " WHERE LOWER(disabled) <> 'true' OR disabled IS NULL";
    $sql .= ' ORDER BY username ASC LIMIT 500';
    $stmt = $pdo->query($sql);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dbError = 'Connexion à la base de données impossible : ' . $e->getMessage();
}

require_once __DIR__ . '/header.php';
?>

<!-- UI intentionally preserved: Phase Operations only hardens dependency loading and embedding. -->
<?php
// The original inventory view is preserved below; the header is now safe when
// this file is rendered inside operations.php with embed=1.
?>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h2 class="mb-1">📦 Inventaire des codes WiFi</h2>
            <div class="text-muted">Une seule page pour importer et consulter les utilisateurs Hotspot.</div>
        </div>
        <a class="btn btn-outline-primary" href="operations.php?tab=hotspot">👥 Gérer les utilisateurs</a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($report): ?>
        <div class="alert alert-success"><strong>Import terminé.</strong> Les utilisateurs valides sont enregistrés dans Supabase et les commandes MikroTik sont en file d’attente.</div>
        <div class="row g-3 mb-3">
            <?php foreach ([['Total',$report['total'],'primary'],['Valides',$report['valid'],'success'],['Invalides',$report['invalid'],'danger'],['Nouveaux',$report['new'],'info'],['Mis à jour',$report['updated'],'warning'],['Commandes',count($report['commands'] ?? []),'secondary']] as $kpi): ?>
                <div class="col-6 col-md-2"><div class="card card-custom p-3 text-center"><div class="h3 mb-0 text-<?= $kpi[2] ?>"><?= (int)$kpi[1] ?></div><small class="text-muted"><?= htmlspecialchars($kpi[0]) ?></small></div></div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($report['errors'])): ?><div class="card card-custom mb-3"><div class="card-header card-header-custom">Lignes rejetées</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Ligne</th><th>Username</th><th>Erreur</th></tr></thead><tbody><?php foreach ($report['errors'] as $err): ?><tr><td><?= htmlspecialchars((string)$err['line']) ?></td><td><?= htmlspecialchars((string)$err['username']) ?></td><td><?= htmlspecialchars((string)$err['error']) ?></td></tr><?php endforeach; ?></tbody></table></div></div></div><?php endif; ?>
        <div class="alert alert-info">Les commandes sont initialement <strong>PENDING</strong> et passent à <strong>EXECUTED</strong> après ACK du worker MikroTik.</div>
    <?php elseif ($preview): ?>
        <div class="card card-custom mb-3">
            <div class="card-header card-header-custom">Prévisualisation — <?= htmlspecialchars((string)($preview['source_name'] ?? 'CSV')) ?></div>
            <div class="card-body">
                <?php if (!empty($preview['unknown_headers'])): ?><div class="alert alert-warning">Colonnes supplémentaires ignorées : <?= htmlspecialchars(implode(', ', $preview['unknown_headers'])) ?></div><?php endif; ?>
                <div class="row g-3 mb-3">
                    <?php foreach ([['Total',$stats['total']],['Valides',$stats['valid']],['Invalides',$stats['invalid']],['Nouveaux',$stats['new']],['Existants',$stats['updated']]] as $kpi): ?><div class="col-6 col-md"><div class="border rounded p-3 text-center"><div class="h4 mb-0"><?= (int)$kpi[1] ?></div><small class="text-muted"><?= htmlspecialchars($kpi[0]) ?></small></div></div><?php endforeach; ?>
                </div>
                <?php if ($previewErrors): ?><div class="alert alert-danger"><strong>Le fichier contient des lignes invalides.</strong> Elles ne seront pas importées.</div><div class="table-responsive mb-3"><table class="table table-sm table-bordered"><thead><tr><th>Ligne</th><th>Username</th><th>Erreur</th></tr></thead><tbody><?php foreach ($previewErrors as $err): ?><tr><td><?= htmlspecialchars((string)$err['line']) ?></td><td><?= htmlspecialchars((string)$err['username']) ?></td><td><?= htmlspecialchars((string)$err['error']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
                <p class="text-muted">Les mots de passe sont masqués et ne sont jamais affichés dans le rapport.</p>
                <div class="table-responsive" style="max-height:420px"><table class="table table-sm table-hover"><thead class="table-light"><tr><th>Ligne</th><th>Username</th><th>Password</th><th>Profile</th><th>Time Limit</th><th>Data Limit</th><th>Comment</th><th>État</th></tr></thead><tbody><?php foreach ($preview['rows'] as $row): ?><tr><td><?= (int)$row['line'] ?></td><td><?= htmlspecialchars((string)$row['username']) ?></td><td>********</td><td><?= htmlspecialchars((string)$row['profile']) ?></td><td><?= htmlspecialchars((string)$row['time_limit']) ?></td><td><?= htmlspecialchars($row['data_limit'] === null ? '' : (string)$row['data_limit']) ?></td><td><?= htmlspecialchars((string)$row['comment']) ?></td><td><?= empty($row['errors']) ? '<span class="badge bg-success">Valide</span>' : '<span class="badge bg-danger">Rejetée</span>' ?></td></tr><?php endforeach; ?></tbody></table></div>
                <form method="post" class="d-flex gap-2 mt-3"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><button class="btn btn-success" name="action" value="confirm" <?= $stats['valid'] === 0 ? 'disabled' : '' ?>>✓ Confirmer l’import des <?= (int)$stats['valid'] ?> lignes valides</button><button class="btn btn-outline-secondary" name="action" value="cancel">Annuler</button></form>
            </div>
        </div>
    <?php else: ?>
        <div class="card card-custom mb-3">
            <div class="card-header card-header-custom"><i class="bi bi-upload"></i> Importer des codes WiFi</div>
            <div class="card-body">
                <form method="post" action="inventory.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="preview">
                    <div class="mb-3"><label class="form-label fw-semibold">Fichier CSV exporté depuis Mikhmon</label><input type="file" class="form-control" name="csv_file" accept=".csv,text/csv" required></div>
                    <div class="alert alert-light border"><strong>En-tête attendu :</strong><br><code>Username, Password, Profile, Time Limit, Data Limit, Comment</code><br><small>UTF-8 ou UTF-8 BOM · séparateur <code>,</code> ou <code>;</code> · maximum 2 MiB et <?= HOTSPOT_CSV_MAX_ROWS ?> lignes.</small></div>
                    <button class="btn btn-orange" type="submit"><i class="bi bi-search"></i> Valider et prévisualiser</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="card card-custom text-center p-3"><div class="h3 mb-0"><?= $counts['all'] ?></div><small class="text-muted">Tous les codes</small></div></div>
        <div class="col-md-4"><div class="card card-custom text-center p-3"><div class="h3 text-success mb-0"><?= $counts['active'] ?></div><small class="text-muted">Actifs</small></div></div>
        <div class="col-md-4"><div class="card card-custom text-center p-3"><div class="h3 text-secondary mb-0"><?= $counts['disabled'] ?></div><small class="text-muted">Désactivés</small></div></div>
    </div>

    <?php if ($dbError): ?><div class="alert alert-danger"><?= htmlspecialchars($dbError) ?></div><?php endif; ?>

    <div class="card card-custom">
        <div class="card-header card-header-custom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>📋 Codes WiFi importés</span>
            <div class="btn-group btn-group-sm">
                <a class="btn <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-outline-primary' ?>" href="operations.php?tab=inventory">Tous</a>
                <a class="btn <?= $statusFilter === 'active' ? 'btn-success' : 'btn-outline-success' ?>" href="operations.php?tab=inventory&amp;status=active">Actifs</a>
                <a class="btn <?= $statusFilter === 'disabled' ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="operations.php?tab=inventory&amp;status=disabled">Désactivés</a>
            </div>
        </div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-striped table-hover mb-0"><thead class="table-dark"><tr><th>Username</th><th>Profile</th><th>Time Limit</th><th>Data Limit</th><th>Comment</th><th>Statut</th><th>Dernière synchro</th></tr></thead><tbody>
        <?php if (!$users): ?><tr><td colspan="7" class="text-center text-muted py-4">Aucun code WiFi trouvé.</td></tr><?php else: foreach ($users as $user): $disabled = strtolower((string)$user['disabled']) === 'true'; ?><tr><td><code><?= htmlspecialchars((string)$user['username']) ?></code></td><td><?= htmlspecialchars((string)($user['profile'] ?? '')) ?></td><td><?= htmlspecialchars((string)($user['limit_uptime'] ?? '')) ?></td><td><?= htmlspecialchars((string)($user['limit_bytes_total'] ?? '')) ?></td><td><?= htmlspecialchars((string)($user['comment'] ?? '')) ?></td><td><span class="badge bg-<?= $disabled ? 'secondary' : 'success' ?>"><?= $disabled ? 'Désactivé' : 'Actif' ?></span></td><td><?= htmlspecialchars((string)($user['last_sync'] ?? '—')) ?></td></tr><?php endforeach; endif; ?>
        </tbody></table></div></div>
    </div>

    <a href="operations.php" class="btn btn-outline-secondary mt-3"><i class="bi bi-arrow-left"></i> Retour aux opérations</a>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>