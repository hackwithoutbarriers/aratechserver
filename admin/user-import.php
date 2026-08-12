<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/hotspot_csv_import.php';

$config = require __DIR__ . '/../config.php';
$pageTitle = 'Import CSV Hotspot - ARA Tech WiFi';

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
            header('Location: user-import.php?preview=1');
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

            $rows = $preview['rows'];
            $pdo->beginTransaction();
            try {
                // Phase 4 writes only against the Phase 2 schema. No table is created here.
                $pdo->query('SELECT 1 FROM hotspot_users LIMIT 1');
                $pdo->query('SELECT 1 FROM hotspot_profiles LIMIT 1');
                $pdo->query('SELECT 1 FROM hotspot_commands LIMIT 1');
                $report = hotspot_csv_import_rows($pdo, $rows);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            unset($_SESSION['hotspot_csv_preview']);
            $_SESSION['hotspot_csv_report'] = $report;
            header('Location: user-import.php?done=1');
            exit;
        }

        if ($action === 'cancel') {
            unset($_SESSION['hotspot_csv_preview']);
            header('Location: user-import.php');
            exit;
        }

        throw new RuntimeException('Action d’import inconnue.');
    } catch (Throwable $e) {
        $_SESSION['hotspot_csv_error'] = $e->getMessage();
        header('Location: user-import.php');
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
            $previewErrors[] = ['line' => $row['line'], 'username' => $row['username'], 'error' => implode(' ', $row['errors'])];
        } else {
            $stats['valid']++;
        }
    }
    try {
        $pdo = ara_db_supabase();
        $validUsernames = [];
        foreach ($preview['rows'] as $row) if (empty($row['errors'])) $validUsernames[] = $row['username'];
        $existing = hotspot_csv_lookup_existing($pdo, $validUsernames);
        foreach ($validUsernames as $username) {
            if (isset($existing[strtolower($username)])) $stats['updated']++; else $stats['new']++;
        }
    } catch (Throwable $e) {
        $previewErrors[] = ['line' => '—', 'username' => '—', 'error' => 'Impossible de vérifier les utilisateurs existants : ' . $e->getMessage()];
    }
}

require __DIR__ . '/header.php';
?>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h2 class="mb-1">📥 Importer des utilisateurs Hotspot</h2>
            <div class="text-muted">Import CSV Mikhmon/MikroTik vers Supabase puis <code>hotspot_commands</code>.</div>
        </div>
        <a class="btn btn-outline-primary" href="users.php">← Retour aux utilisateurs</a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($report): ?>
        <div class="alert alert-success"><strong>Import terminé.</strong> Les commandes sont mises en file ; le statut MikroTik sera confirmé par le worker.</div>
        <div class="row g-3 mb-3">
            <?php foreach ([['Total',$report['total'],'primary'],['Valides',$report['valid'],'success'],['Invalides',$report['invalid'],'danger'],['Nouveaux',$report['new'],'info'],['Mis à jour',$report['updated'],'warning'],['Commandes',$report['commands'] ? count($report['commands']) : 0,'secondary']] as $kpi): ?>
                <div class="col-6 col-md-2"><div class="card card-custom p-3 text-center"><div class="h3 mb-0 text-<?= $kpi[2] ?>"><?= (int)$kpi[1] ?></div><small class="text-muted"><?= htmlspecialchars($kpi[0]) ?></small></div></div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($report['errors'])): ?>
            <div class="card card-custom"><div class="card-header card-header-custom">Erreurs</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Ligne</th><th>Username</th><th>Erreur</th></tr></thead><tbody>
                <?php foreach ($report['errors'] as $err): ?><tr><td><?= htmlspecialchars((string)$err['line']) ?></td><td><?= htmlspecialchars((string)$err['username']) ?></td><td><?= htmlspecialchars((string)$err['error']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div></div>
        <?php endif; ?>
        <div class="card card-custom"><div class="card-header card-header-custom">État des commandes</div><div class="card-body"><p class="mb-0">Les commandes sont initialement <strong>PENDING / En attente</strong>. Elles deviennent <strong>EXECUTED / Synchronisé</strong> uniquement après ACK du worker MikroTik. Les erreurs du worker apparaîtront comme <strong>FAILED</strong>.</p></div></div>
    <?php elseif ($preview): ?>
        <div class="card card-custom">
            <div class="card-header card-header-custom">Prévisualisation — <?= htmlspecialchars((string)($preview['source_name'] ?? 'CSV')) ?></div>
            <div class="card-body">
                <?php if (!empty($preview['unknown_headers'])): ?><div class="alert alert-warning">Colonnes supplémentaires ignorées : <?= htmlspecialchars(implode(', ', $preview['unknown_headers'])) ?></div><?php endif; ?>
                <div class="row g-3 mb-3">
                    <?php foreach ([['Nombre total de lignes',$stats['total']],['Lignes valides',$stats['valid']],['Lignes invalides',$stats['invalid']],['Nouveaux utilisateurs',$stats['new']],['Utilisateurs existants',$stats['updated']]] as $kpi): ?><div class="col-6 col-md"><div class="border rounded p-3 text-center"><div class="h4 mb-0"><?= (int)$kpi[1] ?></div><small class="text-muted"><?= htmlspecialchars($kpi[0]) ?></small></div></div><?php endforeach; ?>
                </div>
                <?php if ($previewErrors): ?><div class="alert alert-danger"><strong>Le fichier contient des lignes invalides.</strong> Elles ne seront pas importées.</div><div class="table-responsive mb-3"><table class="table table-sm table-bordered"><thead><tr><th>Ligne</th><th>Username</th><th>Erreur</th></tr></thead><tbody><?php foreach ($previewErrors as $err): ?><tr><td><?= htmlspecialchars((string)$err['line']) ?></td><td><?= htmlspecialchars((string)$err['username']) ?></td><td><?= htmlspecialchars((string)$err['error']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
                <p class="text-muted">Les mots de passe sont masqués dans la prévisualisation et ne sont jamais affichés dans le rapport.</p>
                <div class="table-responsive" style="max-height:420px"><table class="table table-sm table-hover"><thead class="table-light"><tr><th>Ligne</th><th>Username</th><th>Password</th><th>Profile</th><th>Time Limit</th><th>Data Limit</th><th>Comment</th><th>État</th></tr></thead><tbody>
                <?php foreach ($preview['rows'] as $row): ?><tr><td><?= (int)$row['line'] ?></td><td><?= htmlspecialchars((string)$row['username']) ?></td><td>********</td><td><?= htmlspecialchars((string)$row['profile']) ?></td><td><?= htmlspecialchars((string)$row['time_limit']) ?></td><td><?= htmlspecialchars($row['data_limit'] === null ? '' : (string)$row['data_limit']) ?></td><td><?= htmlspecialchars((string)$row['comment']) ?></td><td><?= empty($row['errors']) ? '<span class="badge bg-success">Valide</span>' : '<span class="badge bg-danger">Rejetée</span>' ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
                <form method="post" class="d-flex gap-2 mt-3"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><button class="btn btn-success" name="action" value="confirm" <?= $stats['valid'] === 0 ? 'disabled' : '' ?>>✓ Confirmer l’import des <?= (int)$stats['valid'] ?> lignes valides</button><button class="btn btn-outline-secondary" name="action" value="cancel">Annuler</button></form>
            </div>
        </div>
    <?php else: ?>
        <div class="card card-custom">
            <div class="card-header card-header-custom"><i class="bi bi-upload"></i> Fichier CSV</div>
            <div class="card-body">
                <form method="post" action="user-import.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="preview">
                    <div class="mb-3"><label class="form-label fw-semibold">Choisir un fichier CSV</label><input type="file" class="form-control" name="csv_file" accept=".csv,text/csv" required></div>
                    <div class="alert alert-light border"><strong>Format attendu :</strong><br><code>Username, Password, Profile, Time Limit, Data Limit, Comment</code><br><small>UTF-8 ou UTF-8 BOM. Séparateur <code>,</code> ou <code>;</code>. Maximum 2 MiB et <?= HOTSPOT_CSV_MAX_ROWS ?> lignes.</small></div>
                    <button class="btn btn-orange" type="submit"><i class="bi bi-search"></i> Valider et prévisualiser</button>
                </form>
            </div>
        </div>
        <div class="card card-custom"><div class="card-header card-header-custom">Flux</div><div class="card-body"><div class="row text-center"><div class="col-md-3"><strong>1. CSV</strong><br><small>Lecture et encodage</small></div><div class="col-md-3"><strong>2. Validation</strong><br><small>Colonnes, profils, limites, doublons</small></div><div class="col-md-3"><strong>3. Supabase</strong><br><small>Insert/update transactionnel</small></div><div class="col-md-3"><strong>4. hotspot_commands</strong><br><small>Worker MikroTik</small></div></div></div></div>
    <?php endif; ?>
</div>
</body></html>
