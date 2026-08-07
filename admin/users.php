<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';

$pageTitle = 'Utilisateurs - ARA Tech WiFi';

$pdo = ara_db($config);
$pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_expiry (
    user TEXT PRIMARY KEY,
    expiry TEXT NOT NULL,
    updated_at TEXT NOT NULL
)");

$search = trim($_GET['search'] ?? '');

if ($search !== '') {
    $stmt = $pdo->prepare("SELECT user, expiry, updated_at FROM hotspot_expiry WHERE user LIKE ? ORDER BY expiry ASC");
    $stmt->execute(['%' . $search . '%']);
} else {
    $stmt = $pdo->query("SELECT user, expiry, updated_at FROM hotspot_expiry ORDER BY expiry ASC");
}
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activeUsernames = [];
$snapshotStmt = $pdo->query("SELECT users_blob FROM hotspot_snapshots ORDER BY id DESC LIMIT 1");
$snapshot = $snapshotStmt->fetch(PDO::FETCH_ASSOC);
if ($snapshot && !empty($snapshot['users_blob'])) {
    $blob = $snapshot['users_blob'];
    foreach (explode('||', $blob) as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        $parts = explode(',', $entry, 3);
        if (count($parts) >= 1) {
            $activeUsernames[] = $parts[0];
        }
    }
}

require __DIR__ . '/header.php';
?>

<div class="container-fluid mt-4">
    <h2 class="mb-3">👥 Gestion des utilisateurs</h2>

    <form method="get" class="row g-2 mb-3">
        <div class="col-md-3">
            <input type="text" class="form-control" name="search" placeholder="Rechercher par nom..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-orange w-100"><i class="bi bi-search"></i> Filtrer</button>
        </div>
        <div class="col-md-7 text-end">
            <span class="text-muted"><?= count($users) ?> utilisateur(s) trouvé(s)</span>
        </div>
    </form>

    <div class="card card-custom">
        <div class="table-responsive">
            <table class="table table-striped mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Nom d'utilisateur</th>
                        <th>Expiration</th>
                        <th>Statut</th>
                        <th>Dernière synchronisation</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="4" class="text-center text-muted">Aucun utilisateur trouvé.</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): 
                            $isOnline = in_array($user['user'], $activeUsernames);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($user['user']) ?></td>
                            <td><?= htmlspecialchars($user['expiry']) ?></td>
                            <td>
                                <?php if ($isOnline): ?>
                                    <span class="online-dot"></span> En ligne
                                <?php else: ?>
                                    <span class="offline-dot"></span> Hors ligne
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($user['updated_at'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <a href="index.php" class="btn btn-outline-secondary mt-3"><i class="bi bi-arrow-left"></i> Retour au tableau de bord</a>
</div>

</body>
</html>
