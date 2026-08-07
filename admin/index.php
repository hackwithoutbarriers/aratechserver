<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';

$pdo = ara_db($config);
$pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    snapshot_date TEXT NOT NULL,
    snapshot_time TEXT NOT NULL,
    active_count INTEGER NOT NULL,
    users_blob TEXT,
    received_at TEXT NOT NULL
)");

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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord - ARA Tech WiFi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --bleu-nuit: #0b2c82; --orange: #f5a623; }
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; padding-bottom: 60px; }
        .navbar-custom { background: var(--bleu-nuit); }
        .navbar-brand { font-weight: 700; font-size: 1.3rem; color: #fff !important; }
        .nav-link { color: rgba(255,255,255,0.85) !important; }
        .nav-link:hover { color: #fff !important; }
        .dropdown-menu { border-radius: 8px; }
        .dropdown-item i { color: var(--orange); margin-right: 6px; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 1.5rem; }
        .card-header-custom { background: var(--bleu-nuit); color: #fff; border-radius: 12px 12px 0 0 !important; font-weight: 600; }
        .stat-value { font-size: 2rem; font-weight: 700; color: var(--bleu-nuit); }
        .stat-label { font-size: 0.9rem; color: #6c757d; }
        .status-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 6px; }
        .online { background: #28a745; }
        .offline { background: #dc3545; }
        .small-text { font-size: 0.8rem; }
        #backToTop {
            position: fixed; bottom: 30px; right: 30px; display: none;
            width: 48px; height: 48px; border-radius: 50%;
            background: var(--orange); color: #fff; border: none;
            font-size: 1.5rem; cursor: pointer; box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            transition: background 0.2s; z-index: 1000;
        }
        #backToTop:hover { background: #e5941f; }
    </style>
</head>
<body>
    <!-- Barre de navigation avec dropdown Navigation rapide -->
    <nav class="navbar navbar-custom navbar-dark navbar-expand-lg px-3">
        <a href="index.php" class="navbar-brand">⚡ ARA Tech WiFi Admin</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-compass"></i> Navigation rapide
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="../status.php"><i class="bi bi-wifi"></i> Statut Hotspot</a></li>
                        <li><a class="dropdown-item" href="ads.php"><i class="bi bi-megaphone"></i> Annonces</a></li>
                        <li><a class="dropdown-item" href="logs.php"><i class="bi bi-journal-text"></i> Logs</a></li>
                        <li><a class="dropdown-item" href="users.php"><i class="bi bi-people"></i> Utilisateurs</a></li>
                        <li><a class="dropdown-item" href="reports.php"><i class="bi bi-graph-up"></i> Rapports</a></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="bi bi-sliders"></i> Configuration</a></li>
                    </ul>
                </li>
            </ul>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Déconnexion</a>
        </div>
    </nav>

    <!-- Bouton retour en haut -->
    <button id="backToTop" title="Retour en haut"><i class="bi bi-arrow-up"></i></button>

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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gestion du bouton "retour en haut"
        const backToTopBtn = document.getElementById('backToTop');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 300) {
                backToTopBtn.style.display = 'block';
            } else {
                backToTopBtn.style.display = 'none';
            }
        });
        backToTopBtn.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    </script>
</body>
</html>
