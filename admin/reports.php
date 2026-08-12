<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../lib/api_client.php';
$config = require __DIR__ . '/../config.php';

$pageTitle = 'Rapports de ventes - ARA Tech WiFi';

$startDate = $_GET['start'] ?? date('Y-m-01');
$endDate   = $_GET['end']   ?? date('Y-m-d');

$error = '';
$totalCA = 0;
$totalTickets = 0;
$profileStats = [];
$salesDetails = [];

$result = ara_api_call($config, 'get-sales', ['start' => $startDate, 'end' => $endDate]);
if ($result['success']) {
    $totalCA      = $result['data']['total_ca'] ?? 0;
    $totalTickets = $result['data']['total_tickets'] ?? 0;
    $profileStats = $result['data']['profile_stats'] ?? [];
    $salesDetails = $result['data']['sales'] ?? [];
} else {
    $error = $result['message'];
}

// Ventes par jour pour le graphique d'évolution
$dailySales = [];
if (!$error) {
    $dailyResult = ara_api_call($config, 'get-sales-daily', ['start' => $startDate, 'end' => $endDate]);
    if ($dailyResult['success']) {
        $dailySales = $dailyResult['data']['daily'] ?? [];
    }
}

require __DIR__ . '/header.php';
?>

<div class="container-fluid mt-4">
    <h2 class="mb-3">💰 Rapports de ventes</h2>

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

        <?php if (!empty($profileStats)): ?>
            <div class="card card-custom mt-3">
                <div class="card-header card-header-custom">Par profil</div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <thead><tr><th>Profil</th><th>Nombre</th><th>CA</th></tr></thead>
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

        <div class="card card-custom mt-3">
            <div class="card-header card-header-custom">Dernières ventes</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead><tr><th>Date</th><th>Heure</th><th>Utilisateur</th><th>Profil</th><th>Montant</th></tr></thead>
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

    <?php if (!empty($dailySales)): ?>
    <div class="card card-custom mt-3">
        <div class="card-header card-header-custom">Évolution du chiffre d'affaires</div>
        <div class="card-body">
            <canvas id="dailySalesChart" height="100"></canvas>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('dailySalesChart').getContext('2d');
        const dailyData = <?= json_encode($dailySales) ?>;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: dailyData.map(d => d.sale_date),
                datasets: [{
                    label: 'Chiffre d\'affaires (FCFA)',
                    data: dailyData.map(d => d.total),
                    backgroundColor: 'rgba(245,166,35,0.2)',
                    borderColor: '#f5a623',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: '#0b2c82'
                }]
            },
            options: { scales: { y: { beginAtZero: true } } }
        });
    });
    </script>
    <?php endif; ?>

    <a href="index.php" class="btn btn-outline-secondary mt-3"><i class="bi bi-arrow-left"></i> Retour au tableau de bord</a>
</div>

</body>
</html>
