<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
$config = require __DIR__ . '/../config.php';

$adminToken = $config['admin']['token'] ?? getenv('ADMIN_TOKEN');
$apiBase = 'https://' . $_SERVER['HTTP_HOST'] . '/api.php';

$startDate = $_GET['start'] ?? date('Y-m-01');
$endDate   = $_GET['end']   ?? date('Y-m-d');

$error = '';
$totalCA = 0;
$totalTickets = 0;
$profileStats = [];
$salesDetails = [];

try {
    $url = $apiBase . '?route=get-sales&token=' . urlencode($adminToken) . '&start=' . urlencode($startDate) . '&end=' . urlencode($endDate);
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    if ($data && ($data['success'] ?? false)) {
        $totalCA = $data['total_ca'] ?? 0;
        $totalTickets = $data['total_tickets'] ?? 0;
        $profileStats = $data['profile_stats'] ?? [];
        $salesDetails = $data['sales'] ?? [];
    } else {
        $error = $data['message'] ?? 'Erreur inconnue.';
    }
} catch (Throwable $e) {
    $error = 'Impossible de contacter l\'API : ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapports de ventes - ARA Tech WiFi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --bleu-nuit: #0b2c82; --orange: #f5a623; }
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .navbar-custom { background: var(--bleu-nuit); }
        .navbar-brand { font-weight: 700; font-size: 1.3rem; color: #fff !important; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 1.5rem; }
        .card-header-custom { background: var(--bleu-nuit); color: #fff; border-radius: 12px 12px 0 0 !important; font-weight: 600; }
        .stat-value { font-size: 2rem; font-weight: 700; color: var(--bleu-nuit); }
        .stat-label { font-size: 0.9rem; color: #6c757d; }
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
        <h2 class="mb-3">💰 Rapports de ventes</h2>

        <!-- Filtres -->
        <form method="get" class="row g-2 align-items-end mb-4">
            <div class="col-md-3">
                <label class="form-label">Date début</label>
                <input type="date" class="form-control" name="start" value="<?= htmlspecialchars($startDate) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Date fin</label>
                <input type="date" class="form-control" name="end" value="<?= htmlspecialchars($endDate) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-orange w-100"><i class="bi bi-funnel"></i> Appliquer</button>
            </div>
            <div class="col-md-4 text-end">
                <span class="text-muted">Période : <?= htmlspecialchars($startDate) ?> → <?= htmlspecialchars($endDate) ?></span>
            </div>
        </form>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php else: ?>
            <!-- Indicateurs globaux -->
            <div class="row">
                <div class="col-md-4">
                    <div class="card card-custom text-center p-3">
                        <div class="stat-value"><?= number_format($totalCA, 0, ',', ' ') ?> FCFA</div>
                        <div class="stat-label">Chiffre d'affaires total</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-custom text-center p-3">
                        <div class="stat-value"><?= $totalTickets ?></div>
                        <div class="stat-label">Tickets vendus</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card card-custom text-center p-3">
                        <div class="stat-value"><?= $totalTickets > 0 ? number_format($totalCA / $totalTickets, 0, ',', ' ') : 0 ?> FCFA</div>
                        <div class="stat-label">Panier moyen</div>
                    </div>
                </div>
            </div>

            <!-- Répartition par profil -->
            <?php if (!empty($profileStats)): ?>
                <div class="card card-custom mt-3">
                    <div class="card-header card-header-custom">Par profil</div>
                    <div class="card-body p-0">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr><th>Profil</th><th>Nombre</th><th>CA</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profileStats as $p): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p['profile'] ?? 'Inconnu') ?></td>
                                    <td><?= $p['nb'] ?? 0 ?></td>
                                    <td><?= number_format((int)($p['ca'] ?? 0), 0, ',', ' ') ?> FCFA</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Détail des dernières ventes -->
            <div class="card card-custom mt-3">
                <div class="card-header card-header-custom">Dernières ventes</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr><th>Date</th><th>Heure</th><th>Utilisateur</th><th>Profil</th><th>Montant</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($salesDetails)): ?>
                                    <tr><td colspan="5" class="text-center text-muted">Aucune vente sur cette période.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($salesDetails as $sale): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($sale['sale_date'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($sale['sale_time'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($sale['username'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($sale['profile'] ?? '') ?></td>
                                        <td><?= number_format((int)($sale['amount'] ?? 0), 0, ',', ' ') ?> FCFA</td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <a href="index.php" class="btn btn-outline-secondary mt-3"><i class="bi bi-arrow-left"></i> Retour au tableau de bord</a>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
