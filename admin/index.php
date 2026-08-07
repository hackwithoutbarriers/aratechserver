<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/lib_turso.php';   // indispensable

$pdo = ara_db($config);
$pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    snapshot_date TEXT NOT NULL,
    snapshot_time TEXT NOT NULL,
    active_count INTEGER NOT NULL,
    users_blob TEXT,
    received_at TEXT NOT NULL
)");

// Restaurer depuis Turso si la locale est vide (dernier snapshot)
restore_from_turso_if_empty(
    $pdo, $config,
    'hotspot_snapshots',
    'SELECT snapshot_date, snapshot_time, active_count, users_blob, received_at FROM hotspot_snapshots ORDER BY id DESC LIMIT 1',
    [],
    'INSERT INTO hotspot_snapshots (snapshot_date, snapshot_time, active_count, users_blob, received_at) VALUES (?, ?, ?, ?, ?)'
);

// --- Sessions actives ---
$stmt = $pdo->query("SELECT * FROM hotspot_snapshots ORDER BY id DESC LIMIT 1");
$snapshot = $stmt->fetch(PDO::FETCH_ASSOC);
$activeUsers = $snapshot ? (int)$snapshot['active_count'] : 0;
$lastSnapshotTime = $snapshot ? ($snapshot['snapshot_date'] . ' ' . $snapshot['snapshot_time']) : null;

// --- État du routeur ---
$routerOnline = false;
if ($lastSnapshotTime) {
    $last = DateTime::createFromFormat('Y-m-d H:i:s', $lastSnapshotTime, new DateTimeZone('UTC'));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($last && $now->getTimestamp() - $last->getTimestamp() < 360) {
        $routerOnline = true;
    }
}

// --- Indicateurs de ventes (via API interne) ---
$adminToken = $config['admin']['token'] ?? getenv('ADMIN_TOKEN');
$apiBase = 'https://' . $_SERVER['HTTP_HOST'] . '/api.php';

function fetchSalesData(string $apiBase, string $token, string $start, string $end): ?array {
    $url = $apiBase . '?route=get-sales&token=' . urlencode($token) . '&start=' . urlencode($start) . '&end=' . urlencode($end);
    try {
        $response = file_get_contents($url);
        $data = json_decode($response, true);
        if ($data && ($data['success'] ?? false)) {
            return $data;
        }
    } catch (Throwable $e) {}
    return null;
}

$today = date('Y-m-d');
$salesDay = fetchSalesData($apiBase, $adminToken, $today, $today);
$caDay = $salesDay ? (int)$salesDay['total_ca'] : 0;
$ticketsDay = $salesDay ? (int)$salesDay['total_tickets'] : 0;

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-d');
$salesMonth = fetchSalesData($apiBase, $adminToken, $monthStart, $monthEnd);
$caMonth = $salesMonth ? (int)$salesMonth['total_ca'] : 0;
$profileStatsMonth = $salesMonth ? ($salesMonth['profile_stats'] ?? []) : [];

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT id) FROM hotspot_snapshots WHERE snapshot_date = ?");
$stmt->execute([$today]);
$dailyRegistered = (int)$stmt->fetchColumn();

$pageTitle = 'Tableau de bord - ARA Tech WiFi';
require __DIR__ . '/header.php';
?>

<div class="container-fluid mt-4">
    <h2 class="mb-4">Tableau de bord</h2>

    <!-- Rangée 1 : Indicateurs financiers -->
    <div class="row">
        <div class="col-md-3">
            <div class="card card-custom text-center p-3">
                <div class="stat-value"><?= number_format($caDay, 0, ',', ' ') ?> FCFA</div>
                <div class="stat-label">Chiffre d'affaires du jour</div>
                <div class="small-text text-muted"><?= $ticketsDay ?> ticket(s)</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-custom text-center p-3">
                <div class="stat-value"><?= number_format($caMonth, 0, ',', ' ') ?> FCFA</div>
                <div class="stat-label">Chiffre d'affaires du mois</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card card-custom">
                <div class="card-header card-header-custom">Tickets vendus par profil (mois)</div>
                <div class="card-body p-0">
                    <?php if (empty($profileStatsMonth)): ?>
                        <p class="text-muted text-center my-3">Aucune vente enregistrée ce mois-ci.</p>
                    <?php else: ?>
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                                <tr><th>Profil</th><th>Vendus</th><th>CA</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profileStatsMonth as $p): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p['profile'] ?? 'Inconnu') ?></td>
                                    <td><?= $p['nb'] ?? 0 ?></td>
                                    <td><?= number_format((int)($p['ca'] ?? 0), 0, ',', ' ') ?> FCFA</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Rangée 2 : Suivi connexions et trafic -->
    <div class="row mt-3">
        <div class="col-md-4">
            <div class="card card-custom text-center p-3">
                <div class="stat-value"><?= $activeUsers ?></div>
                <div class="stat-label">Sessions actives</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-custom text-center p-3">
                <div class="stat-value"><?= $dailyRegistered ?></div>
                <div class="stat-label">Snapshots reçus aujourd'hui</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-custom text-center p-3">
                <div class="stat-value">
                    <span class="status-dot <?= $routerOnline ? 'online' : 'offline' ?>"></span>
                    <?= $routerOnline ? 'En ligne' : 'Hors ligne' ?>
                </div>
                <div class="stat-label">État du routeur</div>
                <?php if ($lastSnapshotTime): ?>
                    <small class="text-muted">Dernier snapshot : <?= htmlspecialchars($lastSnapshotTime) ?></small>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Graphique ventes par profil -->
    <?php if (!empty($profileStatsMonth)): ?>
    <div class="row mt-3">
        <div class="col-12">
            <div class="card card-custom">
                <div class="card-header card-header-custom">Ventes du mois par profil</div>
                <div class="card-body">
                    <canvas id="salesChart" height="100"></canvas>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('salesChart').getContext('2d');
        const profileData = <?= json_encode($profileStatsMonth) ?>;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: profileData.map(p => p.profile || 'Inconnu'),
                datasets: [{
                    label: 'Nombre de tickets',
                    data: profileData.map(p => p.nb || 0),
                    backgroundColor: '#f5a623',
                    borderColor: '#0b2c82',
                    borderWidth: 1
                }]
            },
            options: {
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
                plugins: { legend: { display: false } }
            }
        });
    });
    </script>
    <?php endif; ?>

    <!-- Rangée 3 : Alertes système -->
    <div class="row mt-3">
        <div class="col-12">
            <div class="card card-custom">
                <div class="card-header card-header-custom">Alertes système</div>
                <div class="card-body">
                    <p class="text-muted mb-0">Aucune alerte pour le moment. Les notifications apparaîtront ici.</p>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
