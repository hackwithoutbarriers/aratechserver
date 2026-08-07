<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';

$pdo = ara_db($config);

// Créer la table hotspot_expiry si elle n'existe pas (sécurité)
$pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_expiry (
    user TEXT PRIMARY KEY,
    expiry TEXT NOT NULL,
    updated_at TEXT NOT NULL
)");

// Récupération du filtre recherche
$search = trim($_GET['search'] ?? '');

// Requête utilisateurs avec filtre éventuel
if ($search !== '') {
    $stmt = $pdo->prepare("SELECT user, expiry, updated_at FROM hotspot_expiry WHERE user LIKE ? ORDER BY expiry ASC");
    $stmt->execute(['%' . $search . '%']);
} else {
    $stmt = $pdo->query("SELECT user, expiry, updated_at FROM hotspot_expiry ORDER BY expiry ASC");
}
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer la liste des utilisateurs actifs depuis le dernier snapshot
$activeUsernames = [];
$snapshotStmt = $pdo->query("SELECT users_blob FROM hotspot_snapshots ORDER BY id DESC LIMIT 1");
$snapshot = $snapshotStmt->fetch(PDO::FETCH_ASSOC);
if ($snapshot && !empty($snapshot['users_blob'])) {
    $blob = $snapshot['users_blob'];
    // Format : user,mac,ip||user2,mac2,ip2||...
    foreach (explode('||', $blob) as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        $parts = explode(',', $entry, 3);
        if (count($parts) >= 1) {
            $activeUsernames[] = $parts[0];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Utilisateurs - ARA Tech WiFi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --bleu-nuit: #0b2c82; --orange: #f5a623; }
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .navbar-custom { background: var(--bleu-nuit); }
        .navbar-brand { font-weight: 700; font-size: 1.3rem; color: #fff !important; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .online-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #28a745; margin-right: 4px; }
        .offline-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #dc3545; margin-right: 4px; }
        .btn-orange { background: var(--orange); border: none; color: #fff; font-weight: 600; border-radius: 8px; }
        .btn-orange:hover { background: #e5941f; color: #fff; }
    </style>
</head>
<body>
    <nav class="navbar navbar-custom navbar-dark px-3">
        <a href="index.php" class="navbar-brand">⚡ ARA Tech WiFi Admin</a>
        <div class="ms-auto">
            <a href="logout.php" class="btn btn-outline-light btn-sm">Déconnexion</a>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <h2 class="mb-3">👥 Gestion des utilisateurs</h2>

        <!-- Barre de recherche -->
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
