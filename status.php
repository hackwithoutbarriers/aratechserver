<?php
declare(strict_types=1);
require __DIR__ . '/admin/auth.php';   // garde la protection par mot de passe
require_once __DIR__ . '/db.php';
$config = require __DIR__ . '/config.php';

$pageTitle = 'Statut Hotspot - ARA Tech WiFi';

$pdo = ara_db($config);

// Créer la table si elle n'existe pas
$pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    snapshot_date TEXT NOT NULL,
    snapshot_time TEXT NOT NULL,
    active_count INTEGER NOT NULL,
    users_blob TEXT,
    received_at TEXT NOT NULL
)");

// Dernier snapshot
$stmt = $pdo->query("SELECT * FROM hotspot_snapshots ORDER BY id DESC LIMIT 1");
$snapshot = $stmt->fetch(PDO::FETCH_ASSOC);

$activeCount = $snapshot ? (int)$snapshot['active_count'] : 0;
$usersBlob   = $snapshot ? ($snapshot['users_blob'] ?? '') : '';
$lastDate    = $snapshot ? ($snapshot['snapshot_date'] ?? '') : '';
$lastTime    = $snapshot ? ($snapshot['snapshot_time'] ?? '') : '';

// Déterminer l'état du routeur (en ligne si le snapshot date de moins de 6 minutes)
$routerOnline = false;
if ($snapshot) {
    $last = DateTime::createFromFormat('Y-m-d H:i:s', $lastDate . ' ' . $lastTime, new DateTimeZone('UTC'));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($last && $now->getTimestamp() - $last->getTimestamp() < 360) {
        $routerOnline = true;
    }
}

// Parser la liste des utilisateurs (format : user,mac,ip||user2,mac2,ip2||...)
$users = [];
if ($usersBlob !== '') {
    foreach (explode('||', $usersBlob) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') continue;
        $parts = explode(',', $chunk, 3);
        if (count($parts) === 3) {
            $users[] = ['user' => $parts[0], 'mac' => $parts[1], 'ip' => $parts[2]];
        }
    }
}

require __DIR__ . '/admin/header.php';
?>

<div class="container-fluid mt-4">
    <h2 class="mb-4">📡 Statut du Hotspot</h2>

    <!-- Carte d'état général -->
    <div class="row">
        <div class="col-md-6">
            <div class="card card-custom">
                <div class="card-header card-header-custom">
                    <i class="bi bi-router"></i> État du routeur
                </div>
                <div class="card-body text-center">
                    <div class="stat-value">
                        <span class="status-dot <?= $routerOnline ? 'online' : 'offline' ?>"></span>
                        <?= $routerOnline ? 'En ligne' : 'Hors ligne' ?>
                    </div>
                    <?php if ($snapshot): ?>
                        <small class="text-muted">Dernier snapshot : <?= htmlspecialchars($lastDate . ' ' . $lastTime) ?></small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card card-custom">
                <div class="card-header card-header-custom">
                    <i class="bi bi-people-fill"></i> Utilisateurs actifs
                </div>
                <div class="card-body text-center">
                    <div class="stat-value"><?= $activeCount ?></div>
                    <div class="stat-label">sessions en cours</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Liste des utilisateurs -->
    <?php if (!empty($users)): ?>
    <div class="card card-custom mt-4">
        <div class="card-header card-header-custom">
            <i class="bi bi-list-ul"></i> Utilisateurs connectés
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Utilisateur</th>
                            <th>Adresse MAC</th>
                            <th>Adresse IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $i => $u): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($u['user']) ?></td>
                            <td><?= htmlspecialchars($u['mac']) ?></td>
                            <td><?= htmlspecialchars($u['ip']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php elseif ($snapshot): ?>
        <div class="alert alert-info mt-3">Aucun utilisateur connecté pour le moment.</div>
    <?php endif; ?>

    <?php if (!$snapshot): ?>
        <div class="alert alert-warning mt-3">
            <i class="bi bi-exclamation-triangle"></i> Aucun snapshot reçu. Le routeur n'a pas encore envoyé de données.
        </div>
    <?php endif; ?>

    <!-- Auto-rafraîchissement -->
    <div class="text-end mt-3">
        <small class="text-muted">Dernière mise à jour : <?= date('Y-m-d H:i:s') ?> (rafraîchit toutes les 30s)</small>
    </div>
</div>

<meta http-equiv="refresh" content="30">

</body>
</html>
