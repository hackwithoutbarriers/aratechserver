<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
$config = require __DIR__ . '/../config.php';

$pageTitle = 'Gestion des Stocks - ARA Tech WiFi';

// ============================================================
// DONNÉES STATIQUES (MOCK) — à remplacer par l'appel API/DB
// à l'étape suivante (liaison Backend + Mikhmon).
// ============================================================
$tickets = [
    ['code' => 'VC7f3ka2', 'profile' => '10H',  'imported_at' => '2026-08-05', 'status' => 'disponible'],
    ['code' => 'VC9m1lp0', 'profile' => '10H',  'imported_at' => '2026-08-05', 'status' => 'disponible'],
    ['code' => 'VCa2bme7', 'profile' => '24H',  'imported_at' => '2026-08-05', 'status' => 'vendu'],
    ['code' => 'VCq8xtr4', 'profile' => '24H',  'imported_at' => '2026-08-06', 'status' => 'vendu'],
    ['code' => 'VCk5wnz1', 'profile' => 'Week', 'imported_at' => '2026-08-01', 'status' => 'expire'],
    ['code' => 'VCd4tbo9', 'profile' => 'Week', 'imported_at' => '2026-08-01', 'status' => 'disponible'],
    ['code' => 'VCz0hqy6', 'profile' => 'Month','imported_at' => '2026-07-20', 'status' => 'vendu'],
    ['code' => 'VCr6ujv3', 'profile' => '10H',  'imported_at' => '2026-08-07', 'status' => 'disponible'],
    ['code' => 'VCn2gse8', 'profile' => '24H',  'imported_at' => '2026-08-02', 'status' => 'expire'],
    ['code' => 'VCw9ipf5', 'profile' => '10H',  'imported_at' => '2026-08-07', 'status' => 'vendu'],
];

$countDisponible = count(array_filter($tickets, fn($t) => $t['status'] === 'disponible'));
$countVendu      = count(array_filter($tickets, fn($t) => $t['status'] === 'vendu'));
$countExpire     = count(array_filter($tickets, fn($t) => $t['status'] === 'expire'));

$statusMeta = [
    'disponible' => ['label' => 'Disponible', 'badge' => 'success', 'dot' => '🟢'],
    'vendu'      => ['label' => 'Vendu',      'badge' => 'danger',  'dot' => '🔴'],
    'expire'     => ['label' => 'Expiré',     'badge' => 'secondary','dot' => '⚪'],
];

require __DIR__ . '/header.php';
?>

<div class="container-fluid mt-4">
    <h2 class="mb-3">📦 Gestion des Stocks</h2>

    <!-- Zone d'importation CSV -->
    <div class="card card-custom">
        <div class="card-header card-header-custom"><i class="bi bi-upload"></i> Importer des codes WiFi</div>
        <div class="card-body">
            <form id="importForm" class="row g-2 align-items-end" enctype="multipart/form-data">
                <div class="col-md-6">
                    <label class="form-label">Fichier CSV (export Mikhmon)</label>
                    <input type="file" class="form-control" name="csv_file" id="csv_file" accept=".csv" required>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-orange w-100"><i class="bi bi-file-earmark-arrow-up"></i> Importer les codes</button>
                </div>
            </form>
            <div class="form-text mt-2">Le fichier doit contenir les colonnes Code, Profil et Date d'import générées par Mikhmon.</div>
        </div>
    </div>

    <!-- Indicateurs de statut -->
    <div class="row mt-3">
        <div class="col-md-4">
            <div class="card card-custom text-center p-3">
                <div class="stat-value text-success"><?= $countDisponible ?></div>
                <div class="stat-label">🟢 Tickets disponibles</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-custom text-center p-3">
                <div class="stat-value text-danger"><?= $countVendu ?></div>
                <div class="stat-label">🔴 Tickets vendus</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-custom text-center p-3">
                <div class="stat-value text-secondary"><?= $countExpire ?></div>
                <div class="stat-label">⚪ Tickets expirés</div>
            </div>
        </div>
    </div>

    <!-- Répertoire des tickets -->
    <div class="card card-custom mt-3">
        <div class="card-header card-header-custom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-ticket-perforated"></i> Répertoire des tickets</span>
            <div class="d-flex align-items-center gap-2">
                <ul class="nav nav-pills" id="statusTabs">
                    <li class="nav-item"><button type="button" class="nav-link active" data-filter="all">Tous</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-filter="disponible">🟢 Disponible</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-filter="vendu">🔴 Vendu</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-filter="expire">⚪ Expiré</button></li>
                </ul>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0" id="ticketsTable">
                    <thead class="table-dark">
                        <tr>
                            <th>Code WiFi</th>
                            <th>Profil</th>
                            <th>Date d'import</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $t): $meta = $statusMeta[$t['status']]; ?>
                        <tr data-status="<?= $t['status'] ?>">
                            <td><code><?= htmlspecialchars($t['code']) ?></code></td>
                            <td><?= htmlspecialchars($t['profile']) ?></td>
                            <td><?= htmlspecialchars($t['imported_at']) ?></td>
                            <td><span class="badge bg-<?= $meta['badge'] ?>"><?= $meta['dot'] ?> <?= $meta['label'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <a href="index.php" class="btn btn-outline-secondary mt-3"><i class="bi bi-arrow-left"></i> Retour au tableau de bord</a>
</div>

<style>
    #statusTabs .nav-link { color: var(--bleu-nuit); cursor: pointer; border-radius: 20px; padding: 0.3rem 0.9rem; font-size: 0.85rem; }
    #statusTabs .nav-link.active { background: var(--orange); color: #fff; }
</style>

<script>
// Simulation d'importation (frontend uniquement — pas encore de liaison backend)
document.getElementById('importForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const fileInput = document.getElementById('csv_file');
    if (!fileInput.files.length) return;
    alert('Fichier reçu (Simulation d\'importation)');
    this.reset();
});

// Filtrage visuel du tableau par statut
document.querySelectorAll('#statusTabs .nav-link').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.querySelectorAll('#statusTabs .nav-link').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const filter = this.dataset.filter;
        document.querySelectorAll('#ticketsTable tbody tr').forEach(function (row) {
            row.style.display = (filter === 'all' || row.dataset.status === filter) ? '' : 'none';
        });
    });
});
</script>

</body>
</html>
