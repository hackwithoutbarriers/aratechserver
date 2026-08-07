<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';   // <-- capture du tableau

// ------ Récupération des données ------
$pdo = ara_db($config);

// 1. Dernier snapshot (sessions actives)
$stmt = $pdo->query("SELECT * FROM hotspot_snapshots ORDER BY id DESC LIMIT 1");
$snapshot = $stmt->fetch(PDO::FETCH_ASSOC);
$activeUsers = $snapshot ? (int)$snapshot['active_count'] : 0;
$lastSnapshotTime = $snapshot ? ($snapshot['snapshot_date'] . ' ' . $snapshot['snapshot_time']) : null;

// 2. Utilisateurs enregistrés aujourd'hui (nombre de snapshots distincts de la journée)
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT id) FROM hotspot_snapshots WHERE snapshot_date = ?");
$stmt->execute([$today]);
$dailyRegistered = (int)$stmt->fetchColumn();

// 3. État du routeur : vert si dernier snapshot < 6 minutes
$routerOnline = false;
if ($lastSnapshotTime) {
    $last = DateTime::createFromFormat('Y-m-d H:i:s', $lastSnapshotTime, new DateTimeZone('UTC'));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($last && $now->getTimestamp() - $last->getTimestamp() < 360) {
        $routerOnline = true;
    }
}

// 4. Indicateurs financiers (en attente de l'intégration des ventes Turso)
$caDay = 0;
$caMonth = 0;

// 5. Tickets par profil (placeholder)
$profiles = ['10H', '24H', 'Week', 'Month', 'Abonnement'];
$profileStats = array_fill_keys($profiles, ['remaining' => 0, 'sold' => 0]);

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
        :root {
            --bleu-nuit: #0b2c82;
            --orange: #f5a623;
        }
        body {
            background: #f4f6f9;
            font-family: 'Segoe UI', sans-serif;
        }
        .navbar-custom {
            background: var(--bleu-nuit);
        }
        .navbar-brand {
            font-weight: 700;
            font-size: 1.3rem;
            color: #fff !important;
        }
        .card-custom {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
        }
        .card-header-custom {
            background: var(--bleu-nuit);
            color: #fff;
            border-radius: 12px 12px 0 0 !important;
            font-weight: 600;
        }
        .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--bleu-nuit);
        }
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
        }
        .btn-orange {
            background: var(--orange);
            border: none;
            color: #fff;
            font-weight: 600;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .btn-orange:hover {
            background: #e5941f;
            color: #fff;
        }
        .status-dot {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 6px;
        }
        .online { background: #28a745; }
        .offline { background: #dc3545; }
        .quick-link {
            text-decoration: none;
            color: var(--bleu-nuit);
            font-weight: 500;
        }
        .quick-link i {
            color: var(--orange);
        }
    </style>
</head>
<body>
    <!-- Barre de navigation -->
    <nav class="navbar navbar-custom navbar-dark px-3">
        <span class="navbar-brand">⚡ ARA Tech WiFi Admin</span>
        <div class="ms-auto">
            <a href="logout.php" class="btn btn-outline-light btn-sm">Déconnexion</a>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <h2 class="mb-4">Tableau de bord</h2>

        <!-- Rangée 1 : Indicateurs financiers -->
        <div class="row">
            <div class="col-md-3">
                <div class="card card-custom text-center p-3">
                    <div class="stat-value"><?= number_format($caDay, 0, ',', ' ') ?> FCFA</div>
                    <div class="stat-label">Chiffre d'affaires du jour</div>
                    <small class="text-muted">En construction</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-custom text-center p-3">
                    <div class="stat-value"><?= number_format($caMonth, 0, ',', ' ') ?> FCFA</div>
                    <div class="stat-label">Chiffre d'affaires du mois</div>
                    <small class="text-muted">En construction</small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card card-custom">
                    <div class="card-header card-header-custom">Tickets vendus / restants par profil</div>
                    <div class="card-body p-2">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Profil</th>
                                    <th>Vendus</th>
                                    <th>Restants</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profiles as $p): ?>
                                <tr>
                                    <td><?= $p ?></td>
                                    <td><?= $profileStats[$p]['sold'] ?></td>
                                    <td><?= $profileStats[$p]['remaining'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <small class="text-muted ms-2">Les données détaillées seront disponibles prochainement.</small>
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
                    <div class="stat-label">Utilisateurs enregistrés aujourd'hui</div>
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
                        <p class="text-muted mb-0">Aucune alerte pour le moment. Les notifications (redémarrages, pannes) apparaîtront ici.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Boutons rapides -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card card-custom">
                    <div class="card-header card-header-custom">Accès rapides</div>
                    <div class="card-body d-flex flex-wrap gap-3">
                        <a href="../status.php" class="quick-link"><i class="bi bi-wifi"></i> Statut Hotspot</a>
                        <a href="ads.php" class="quick-link"><i class="bi bi-megaphone"></i> Annonces</a>
                        <a href="logs.php" class="quick-link"><i class="bi bi-journal-text"></i> Logs</a>
                        <span class="quick-link disabled text-muted"><i class="bi bi-people"></i> Utilisateurs (bientôt)</span>
                        <span class="quick-link disabled text-muted"><i class="bi bi-graph-up"></i> Rapports (bientôt)</span>
                        <span class="quick-link disabled text-muted"><i class="bi bi-sliders"></i> Configuration (bientôt)</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
