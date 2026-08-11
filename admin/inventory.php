<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';

$pageTitle = 'Gestion des Stocks - ARA Tech WiFi';

$statusMeta = [
    'Disponible' => ['badge' => 'success',   'dot' => '🟢'],
    'Vendu'      => ['badge' => 'danger',    'dot' => '🔴'],
    'Expiré'     => ['badge' => 'secondary', 'dot' => '⚪'],
];

// ============================================================
// TRAITEMENT DE L'IMPORTATION CSV (POST)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    $file = $_FILES['csv_file'] ?? null;
    $maxUploadBytes = 1024 * 1024; // 1 MiB — protection minimale, métier inchangé.

    try {
        if (!$file || !isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])) {
            throw new RuntimeException('Fichier téléversé manquant.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Erreur lors du téléversement du fichier.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Source de téléversement invalide.');
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > $maxUploadBytes) {
            throw new RuntimeException('Fichier trop volumineux ou vide (maximum 1 MiB).');
        }
        if (strtolower((string)pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            throw new RuntimeException('Le fichier doit être au format .csv.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowedMimes = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
        if ($mime === false || !in_array($mime, $allowedMimes, true)) {
            throw new RuntimeException('Type MIME CSV non autorisé.');
        }

        $pdoSupa = ara_db_supabase();
        ara_ensure_finance_tables($pdoSupa);

        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            throw new RuntimeException('Impossible de lire le fichier téléversé.');
        }

        // Détection du délimiteur (Mikhmon exporte parfois en ';')
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            throw new RuntimeException('Fichier CSV vide.');
        }
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter);
        if ($header === false || $header === null) {
            fclose($handle);
            throw new RuntimeException('En-tête CSV illisible.');
        }
        $header[0] = preg_replace('/^\x{FEFF}/u', '', (string)$header[0]);
        $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);

        $codeIdx = array_search('code', $header, true);
        if ($codeIdx === false) {
            $codeIdx = array_search('username', $header, true);
        }
        $profileIdx = array_search('profile', $header, true);

        if ($codeIdx === false || $profileIdx === false) {
            fclose($handle);
            throw new RuntimeException("Colonnes 'code'/'username' et 'profile' introuvables dans l'en-tête du CSV.");
        }

        $profileMap = [];
        foreach ($pdoSupa->query("SELECT id, name FROM profiles") as $row) {
            $profileMap[strtolower(trim((string)$row['name']))] = (int)$row['id'];
        }

        $insertStmt = $pdoSupa->prepare(
            "INSERT INTO tickets (profile_id, code, status) VALUES (?, ?, 'Disponible')
             ON CONFLICT (code) DO NOTHING"
        );

        $imported = 0;
        $skippedNoProfile = 0;
        $skippedEmpty = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($row === [null] || count($row) <= max($codeIdx, $profileIdx)) {
                continue;
            }
            $code = trim((string)($row[$codeIdx] ?? ''));
            $profileName = trim((string)($row[$profileIdx] ?? ''));

            if ($code === '') {
                $skippedEmpty++;
                continue;
            }
            $profileId = $profileMap[strtolower($profileName)] ?? null;
            if ($profileId === null) {
                $skippedNoProfile++;
                continue;
            }
            $insertStmt->execute([$profileId, $code]);
            if ($insertStmt->rowCount() > 0) {
                $imported++;
            }
        }
        fclose($handle);

        $msg = "$imported ticket(s) importé(s) avec succès.";
        if ($skippedNoProfile > 0) {
            $msg .= " $skippedNoProfile ligne(s) ignorée(s) (profil inconnu dans la table profiles).";
        }
        if ($skippedEmpty > 0) {
            $msg .= " $skippedEmpty ligne(s) ignorée(s) (code vide).";
        }
        $_SESSION['flash_success'] = $msg;
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = "Erreur d'importation : " . $e->getMessage();
    }

    header('Location: inventory.php');
    exit;
}

$statusFilter = $_GET['status'] ?? 'all';
$allowedStatus = array_keys($statusMeta);
if (!in_array($statusFilter, $allowedStatus, true)) {
    $statusFilter = 'all';
}

$tickets = [];
$counts = ['Disponible' => 0, 'Vendu' => 0, 'Expiré' => 0];
$dbError = '';

try {
    $pdoSupa = ara_db_supabase();
    ara_ensure_finance_tables($pdoSupa);
    foreach ($pdoSupa->query("SELECT status, COUNT(*) AS cnt FROM tickets GROUP BY status") as $row) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int)$row['cnt'];
        }
    }

    $sql = "SELECT t.code, COALESCE(p.name, '—') AS profile_name, t.imported_at, t.status
            FROM tickets t
            LEFT JOIN profiles p ON p.id = t.profile_id";
    $params = [];
    if ($statusFilter !== 'all') {
        $sql .= " WHERE t.status = ?";
        $params[] = $statusFilter;
    }
    $sql .= " ORDER BY t.imported_at DESC LIMIT 300";
    $stmt = $pdoSupa->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();
} catch (Throwable $e) {
    $dbError = "Connexion à la base de données impossible : " . $e->getMessage();
}

require __DIR__ . '/header.php';
?>

<div class="container-fluid mt-4">
    <h2 class="mb-3">📦 Gestion des Stocks</h2>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['flash_success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['flash_error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>
    <?php if ($dbError): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <div class="card card-custom">
        <div class="card-header card-header-custom"><i class="bi bi-upload"></i> Importer des codes WiFi</div>
        <div class="card-body">
            <form method="post" action="inventory.php" enctype="multipart/form-data" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="import_csv">
                <div class="col-md-6">
                    <label class="form-label">Fichier CSV (export Mikhmon)</label>
                    <input type="file" class="form-control" name="csv_file" accept=".csv,text/csv" required>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-orange w-100"><i class="bi bi-file-earmark-arrow-up"></i> Importer les codes</button>
                </div>
            </form>
            <div class="form-text mt-2">CSV uniquement, 1 MiB maximum. Le fichier doit contenir une colonne <code>code</code> (ou <code>username</code>) et une colonne <code>profile</code>.</div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-md-4"><div class="card card-custom text-center p-3"><div class="stat-value text-success"><?= $counts['Disponible'] ?></div><div class="stat-label">🟢 Tickets disponibles</div></div></div>
        <div class="col-md-4"><div class="card card-custom text-center p-3"><div class="stat-value text-danger"><?= $counts['Vendu'] ?></div><div class="stat-label">🔴 Tickets vendus</div></div></div>
        <div class="col-md-4"><div class="card card-custom text-center p-3"><div class="stat-value text-secondary"><?= $counts['Expiré'] ?></div><div class="stat-label">⚪ Tickets expirés</div></div></div>
    </div>

    <div class="card card-custom mt-3">
        <div class="card-header card-header-custom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-ticket-perforated"></i> Répertoire des tickets</span>
            <ul class="nav nav-pills" id="statusTabs">
                <li class="nav-item"><a class="nav-link <?= $statusFilter === 'all' ? 'active' : '' ?>" href="inventory.php">Tous</a></li>
                <li class="nav-item"><a class="nav-link <?= $statusFilter === 'Disponible' ? 'active' : '' ?>" href="inventory.php?status=Disponible">🟢 Disponible</a></li>
                <li class="nav-item"><a class="nav-link <?= $statusFilter === 'Vendu' ? 'active' : '' ?>" href="inventory.php?status=Vendu">🔴 Vendu</a></li>
                <li class="nav-item"><a class="nav-link <?= $statusFilter === 'Expiré' ? 'active' : '' ?>" href="inventory.php?status=Expiré">⚪ Expiré</a></li>
            </ul>
        </div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-striped mb-0">
            <thead class="table-dark"><tr><th>Code WiFi</th><th>Profil</th><th>Date d'import</th><th>Statut</th></tr></thead>
            <tbody>
            <?php if (empty($tickets)): ?>
                <tr><td colspan="4" class="text-center text-muted">Aucun ticket trouvé.</td></tr>
            <?php else: ?>
                <?php foreach ($tickets as $t): $meta = $statusMeta[$t['status']] ?? ['badge' => 'light', 'dot' => '']; ?>
                <tr><td><code><?= htmlspecialchars($t['code']) ?></code></td><td><?= htmlspecialchars($t['profile_name']) ?></td><td><?= htmlspecialchars((string)$t['imported_at']) ?></td><td><span class="badge bg-<?= $meta['badge'] ?>"><?= $meta['dot'] ?> <?= htmlspecialchars($t['status']) ?></span></td></tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table></div></div>
    </div>

    <a href="index.php" class="btn btn-outline-secondary mt-3"><i class="bi bi-arrow-left"></i> Retour au tableau de bord</a>
</div>

<style>
#statusTabs .nav-link { color: var(--bleu-nuit); border-radius: 20px; padding: 0.3rem 0.9rem; font-size: 0.85rem; }
#statusTabs .nav-link.active { background: var(--orange); color: #fff; }
</style>

</body>
</html>
