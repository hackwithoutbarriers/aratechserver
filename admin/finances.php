<?php
/**
 * ARA Tech / ARA Shop - Gestion Financière
 * Version Dynamique liée à Supabase (PostgreSQL)
 */

require_once '../config.php';
require_once 'auth.php';

// Protection stricte de la session admin
requireAdmin();

// Récupération de la période (par défaut le mois en cours)
$filter = isset($_GET['period']) ? $_GET['period'] : 'current_month';

$startDate = null;
$endDate = null;

switch ($filter) {
    case 'current_month':
        $startDate = date('Y-m-01 00:00:00');
        $endDate = date('Y-m-t 23:59:59');
        break;
    case 'last_month':
        $startDate = date('Y-m-01 00:00:00', strtotime('first day of last month'));
        $endDate = date('Y-m-t 23:59:59', strtotime('last day of last month'));
        break;
    case 'global':
    default:
        $startDate = '1970-01-01 00:00:00';
        $endDate = date('Y-m-d H:i:s');
        break;
}

try {
    // 1. Calcul dynamique des recettes (Chiffre d'Affaires) depuis sales_log
    $stmtSales = $pdo->prepare("
        SELECT COALESCE(SUM(price), 0) as total_sales 
        FROM sales_log 
        WHERE created_at BETWEEN :start AND :end
    ");
    $stmtSales->execute(['start' => $startDate, 'end' => $endDate]);
    $totalRecettes = (float) $stmtSales->fetch(PDO::FETCH_ASSOC)['total_sales'];

    // 2. Récupération des dépenses réelles depuis la table expenses
    $stmtExpenses = $pdo->prepare("
        SELECT id, description, amount, category, created_at 
        FROM expenses 
        WHERE created_at BETWEEN :start AND :end 
        ORDER BY created_at DESC
    ");
    $stmtExpenses->execute(['start' => $startDate, 'end' => $endDate]);
    $expenses = $stmtExpenses->fetchAll(PDO::FETCH_ASSOC);

    // 3. Calcul du total des dépenses
    $totalDepenses = 0;
    foreach ($expenses as $exp) {
        $totalDepenses += (float) $exp['amount'];
    }

    // 4. Calcul du bénéfice net
    $beneficeNet = $totalRecettes - $totalDepenses;

} catch (PDOException $e) {
    die("Erreur de base de données : " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finances - ARA Tech</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f6f9; }
        .card-kpi { border-radius: 12px; transition: transform 0.2s; }
        .card-kpi:hover { transform: translateY(-3px); }
    </style>
</head>
<body>

<div class="container-fluid py-4">
    <!-- En-tête -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">Gestion Financière</h1>
            <p class="text-muted mb-0">Suivi des recettes du Hotspot et des charges de la boutique</p>
        </div>
        
        <!-- Sélecteur de Période -->
        <div>
            <form method="GET" action="" class="d-flex gap-2">
                <select name="period" class="form-select" onchange="this.form.submit()">
                    <option value="current_month" <?= $filter === 'current_month' ? 'selected' : '' ?>>Mois en cours</option>
                    <option value="last_month" <?= $filter === 'last_month' ? 'selected' : '' ?>>Mois dernier</option>
                    <option value="global" <?= $filter === 'global' ? 'selected' : '' ?>>Toutes les données</option>
                </select>
            </form>
        </div>
    </div>

    <!-- Section KPI -->
    <div class="row g-3 mb-4">
        <!-- Recettes -->
        <div class="col-md-4">
            <div class="card card-kpi bg-success text-white border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-uppercase text-white-50 small">Recettes (Ventes Hotspot)</h6>
                        <h2 class="mb-0 fw-bold"><?= number_format($totalRecettes, 0, ',', ' ') ?> <small>FCFA</small></h2>
                    </div>
                    <i class="bi bi-graph-up-arrow fs-1 text-white-50"></i>
                </div>
            </div>
        </div>
        <!-- Dépenses -->
        <div class="col-md-4">
            <div class="card card-kpi bg-danger text-white border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-uppercase text-white-50 small">Dépenses / Charges</h6>
                        <h2 class="mb-0 fw-bold" id="kpi-depenses"><?= number_format($totalDepenses, 0, ',', ' ') ?> <small>FCFA</small></h2>
                    </div>
                    <i class="bi bi-graph-down-arrow fs-1 text-white-50"></i>
                </div>
            </div>
        </div>
        <!-- Bénéfice Net -->
        <div class="col-md-4">
            <div class="card card-kpi <?= $beneficeNet >= 0 ? 'bg-primary' : 'bg-warning text-dark' ?> text-white border-0 shadow-sm">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-uppercase <?= $beneficeNet >= 0 ? 'text-white-50' : 'text-dark-50' ?> small">Bénéfice Net Temporel</h6>
                        <h2 class="mb-0 fw-bold" id="kpi-benefice"><?= number_format($beneficeNet, 0, ',', ' ') ?> <small>FCFA</small></h2>
                    </div>
                    <i class="bi bi-wallet2 fs-1 <?= $beneficeNet >= 0 ? 'text-white-50' : 'text-dark-50' ?>"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Corps de la page -->
    <div class="row g-4">
        <!-- Formulaire d'Ajout de Charge -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-plus-circle me-2 text-primary"></i>Enregistrer une charge</h5>
                </div>
                <div class="card-body pt-0">
                    <form id="form-expense">
                        <div class="mb-3">
                            <label class="form-label font-monospace small">Description / Motif</label>
                            <input type="text" id="exp-description" class="form-control" placeholder="Ex: Achat carburant groupe ou Remplacement câble" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label font-monospace small">Montant (FCFA)</label>
                            <input type="number" id="exp-amount" class="form-control" min="1" placeholder="Ex: 5000" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label font-monospace small">Catégorie</label>
                            <select id="exp-category" class="form-select">
                                <option value="Infrastructure">Infrastructure / Réseau</option>
                                <option value="Énergie">Énergie / Électricité</option>
                                <option value="Marketing">Marketing / Bannières</option>
                                <option value="Divers">Divers / Imprévus</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm">
                            <i class="bi bi-check-circle me-1"></i> Valider l'écriture
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Liste des écritures de charges -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-list-task me-2 text-danger"></i>Journal des Charges</h5>
                    <span class="badge bg-secondary rounded-pill"><?= count($expenses) ?> ligne(s)</span>
                </div>
                <div class="table-responsive px-3 pb-3">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light font-monospace small">
                            <tr>
                                <th>Date</th>
                                <th>Catégorie</th>
                                <th>Motif / Libellé</th>
                                <th class="text-end">Montant</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="table-body-expenses">
                            <?php if (empty($expenses)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">Aucune dépense enregistrée sur cette période.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($expenses as $expense): ?>
                                    <tr id="row-<?= $expense['id'] ?>">
                                        <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($expense['created_at'])) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($expense['category']) ?></span></td>
                                        <td class="fw-semibold text-secondary"><?= htmlspecialchars($expense['description']) ?></td>
                                        <td class="text-end fw-bold text-danger"><?= number_format($expense['amount'], 0, ',', ' ') ?> F</td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-link text-danger p-0" onclick="deleteExpense(<?= $expense['id'] ?>, <?= $expense['amount'] ?>)">
                                                <i class="bi bi-trash3 fs-5"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Token d'administration injecté de manière sécurisée depuis la session PHP
const ADMIN_TOKEN = "<?= $_SESSION['admin_token'] ?? '' ?>";
let currentRecettes = <?= $totalRecettes ?>;
let currentDepenses = <?= $totalDepenses ?>;

// Configuration universelle des en-têtes API
const API_HEADERS = {
    'Content-Type': 'application/json',
    'X-Admin-Token': ADMIN_TOKEN
};

// Fonction utilitaire pour le formatage monétaire monnaie locale
function formatFCFA(amount) {
    return new Intl.NumberFormat('fr-FR').format(amount) + ' <small>FCFA</small>';
}

// Recalculateur dynamique des KPI en local suite à des modifications Javascript/Fetch
function refreshKPI() {
    let net = currentRecettes - currentDepenses;
    document.getElementById('kpi-depenses').innerHTML = formatFCFA(currentDepenses);
    
    let netKpiCard = document.getElementById('kpi-benefice');
    netKpiCard.innerHTML = formatFCFA(net);
    
    let parentCard = netKpiCard.closest('.card-kpi');
    if (net >= 0) {
        parentCard.className = "card card-kpi bg-primary text-white border-0 shadow-sm";
    } else {
        parentCard.className = "card card-kpi bg-warning text-dark border-0 shadow-sm";
    }
}

// Soumission asynchrone d'une nouvelle dépense
document.getElementById('form-expense').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const description = document.getElementById('exp-description').value.trim();
    const amount = parseFloat(document.getElementById('exp-amount').value);
    const category = document.getElementById('exp-category').value;

    if(!description || isNaN(amount) || amount <= 0) return;

    try {
        const response = await fetch('api.php?action=save_expense', {
            method: 'POST',
            headers: API_HEADERS,
            body: JSON.stringify({ description, amount, category })
        });

        const result = await response.json();

        if (result.success) {
            // Rechargement léger de la page pour réordonner correctement par date PostgreSQL ou insertion dynamique
            window.location.reload();
        } else {
            alert("Erreur lors de la validation : " + (result.message || "inconnue"));
        }
    } catch (err) {
        console.error(err);
        alert("Impossible de joindre le serveur API.");
    }
});

// Suppression asynchrone d'une charge
async function deleteExpense(id, amount) {
    if (!confirm("Voulez-vous vraiment supprimer cette ligne d'écriture comptable ?")) return;

    try {
        const response = await fetch('api.php?action=delete_expense', {
            method: 'POST',
            headers: API_HEADERS,
            body: JSON.stringify({ id })
        });

        const result = await response.json();

        if (result.success) {
            // Suppression visuelle instantanée de la ligne
            const row = document.getElementById(`row-${id}`);
            if(row) row.remove();
            
            // Ajustement des KPI en temps réel
            currentDepenses -= amount;
            refreshKPI();
        } else {
            alert("Erreur lors de la suppression : " + (result.message || "inconnue"));
        }
    } catch (err) {
        console.error(err);
        alert("Erreur réseau lors de la suppression.");
    }
}
</script>

</body>
</html>
