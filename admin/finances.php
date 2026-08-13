<?php
declare(strict_types=1);

/**
 * admin/finances.php — converti au layout partagé (includes/header.php +
 * includes/footer.php). La garde de session (auth.php) et le chargement de
 * $config sont désormais assurés par includes/header.php ; cette page ne
 * s'occupe plus que de sa propre logique et de son propre contenu.
 */

$pageTitle = 'Finances - ARA Tech WiFi';

// ============================================================
// DONNÉES STATIQUES (MOCK) — à remplacer par l'appel API/DB
// à l'étape suivante (liaison Backend + Supabase, migration 013).
// ============================================================
$periode = $_GET['periode'] ?? 'mois_courant';

$expenses = [
    ['date' => '2026-08-08', 'description' => 'Recharge Internet CanalBox', 'category' => 'Internet',    'amount' => 15000],
    ['date' => '2026-08-06', 'description' => 'Achat rallonge électrique',   'category' => 'Matériel',    'amount' => 3500],
    ['date' => '2026-08-03', 'description' => 'Facture CEET',                'category' => 'Électricité', 'amount' => 8000],
    ['date' => '2026-08-01', 'description' => 'Loyer local technique',       'category' => 'Loyer',       'amount' => 15000],
    ['date' => '2026-07-28', 'description' => 'Impression affiches pub',     'category' => 'Autre',       'amount' => 3500],
];

$totalRecettes = 45000;
$totalDepenses = array_sum(array_column($expenses, 'amount'));
$beneficeReel  = $totalRecettes - $totalDepenses;

$categoryBadge = [
    'Internet'    => 'primary',
    'Électricité' => 'warning',
    'Matériel'    => 'info',
    'Loyer'       => 'dark',
    'Autre'       => 'secondary',
];

include_once 'includes/header.php';
?>

<h2 class="mb-3">💰 Dépenses &amp; Bénéfices</h2>

<!-- Formulaire d'enregistrement des charges -->
<div class="card card-custom">
    <div class="card-header card-header-custom"><i class="bi bi-plus-circle"></i> Enregistrer une dépense</div>
    <div class="card-body">
        <form id="expenseForm" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Description</label>
                <input type="text" class="form-control" id="description" name="description" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Catégorie</label>
                <select class="form-select" id="category" name="category">
                    <option value="Internet">Internet</option>
                    <option value="Électricité">Électricité</option>
                    <option value="Matériel">Matériel</option>
                    <option value="Loyer">Loyer</option>
                    <option value="Autre">Autre</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Montant (FCFA)</label>
                <input type="number" class="form-control" id="amount" name="amount" min="0" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Date</label>
                <input type="date" class="form-control" id="expense_date" name="expense_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-orange w-100"><i class="bi bi-save"></i> Enregistrer la dépense</button>
            </div>
        </form>
    </div>
</div>

<!-- Filtre de période -->
<form method="get" class="row g-2 align-items-end mt-3 mb-1">
    <div class="col-md-3">
        <label class="form-label">Période</label>
        <select class="form-select" name="periode" onchange="this.form.submit()">
            <option value="mois_courant" <?= $periode === 'mois_courant' ? 'selected' : '' ?>>Mois en cours</option>
            <option value="mois_dernier" <?= $periode === 'mois_dernier' ? 'selected' : '' ?>>Mois dernier</option>
            <option value="global" <?= $periode === 'global' ? 'selected' : '' ?>>Vue globale</option>
        </select>
    </div>
</form>

<!-- Indicateurs financiers -->
<div class="row mt-2">
    <div class="col-md-4">
        <div class="card card-custom text-center p-3">
            <div class="stat-value text-success"><?= number_format($totalRecettes, 0, ',', ' ') ?> FCFA</div>
            <div class="stat-label">Recettes totales</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-custom text-center p-3">
            <div class="stat-value text-danger"><?= number_format($totalDepenses, 0, ',', ' ') ?> FCFA</div>
            <div class="stat-label">Dépenses totales</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-custom text-center p-3">
            <div class="stat-value <?= $beneficeReel >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($beneficeReel, 0, ',', ' ') ?> FCFA</div>
            <div class="stat-label">Bénéfice réel</div>
        </div>
    </div>
</div>

<!-- Historique des dépenses -->
<div class="card card-custom mt-3">
    <div class="card-header card-header-custom"><i class="bi bi-clock-history"></i> Historique des dépenses</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0" id="expensesTable">
                <thead class="table-dark">
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Catégorie</th>
                        <th>Montant</th>
                        <th style="width: 100px">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $e): ?>
                    <tr>
                        <td><?= htmlspecialchars($e['date']) ?></td>
                        <td><?= htmlspecialchars($e['description']) ?></td>
                        <td><span class="badge bg-<?= $categoryBadge[$e['category']] ?? 'secondary' ?>"><?= htmlspecialchars($e['category']) ?></span></td>
                        <td><?= number_format($e['amount'], 0, ',', ' ') ?> FCFA</td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteExpenseRow(this)" title="Supprimer">
                                <i class="bi bi-trash"></i> Supprimer
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<a href="index.php" class="btn btn-outline-secondary mt-3"><i class="bi bi-arrow-left"></i> Retour au tableau de bord</a>

<script>
// Simulation d'enregistrement (frontend uniquement — pas encore de liaison backend)
document.getElementById('expenseForm').addEventListener('submit', function (e) {
    e.preventDefault();
    alert('Dépense enregistrée (Simulation d\'enregistrement)');
    this.reset();
    document.getElementById('expense_date').value = new Date().toISOString().slice(0, 10);
});

// Suppression visuelle d'une ligne (future gestion des erreurs)
function deleteExpenseRow(btn) {
    if (!confirm('Confirmer la suppression de cette dépense ?')) return;
    btn.closest('tr').remove();
}
</script>

<?php include_once 'includes/footer.php'; ?>
