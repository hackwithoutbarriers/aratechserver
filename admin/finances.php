<?php
declare(strict_types=1);

$pageTitle = 'Finances - ARA Tech WiFi';
require_once __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';

const FINANCE_CATEGORIES = ['Internet', 'Électricité', 'Matériel', 'Loyer', 'Autre'];

if (empty($_SESSION['finances_csrf'])) {
    $_SESSION['finances_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['finances_csrf'];
$periode = (string)($_GET['periode'] ?? $_POST['periode'] ?? 'mois_courant');
if (!in_array($periode, ['mois_courant', 'mois_dernier', 'global'], true)) $periode = 'mois_courant';

function finances_redirect(string $periode): never { header('Location: finances.php?periode=' . rawurlencode($periode)); exit; }
function finances_flash(string $type, string $message): void { $_SESSION['finances_flash'] = ['type'=>$type,'message'=>$message]; }

$flash = $_SESSION['finances_flash'] ?? null;
unset($_SESSION['finances_flash']);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Token CSRF invalide.');
        $pdo = ara_db_supabase();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add') {
            $description = trim((string)($_POST['description'] ?? ''));
            $category = trim((string)($_POST['category'] ?? ''));
            $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $expenseDate = trim((string)($_POST['expense_date'] ?? ''));
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expenseDate);
            if ($description === '' || mb_strlen($description) > 500) throw new InvalidArgumentException('Description invalide.');
            if (!in_array($category, FINANCE_CATEGORIES, true)) throw new InvalidArgumentException('Catégorie invalide.');
            if ($amount === false) throw new InvalidArgumentException('Montant invalide.');
            if ($date === false || $date->format('Y-m-d') !== $expenseDate) throw new InvalidArgumentException('Date invalide.');
            $stmt = $pdo->prepare('INSERT INTO expenses (description, category, amount, expense_date) VALUES (?, ?, ?, ?)');
            $stmt->execute([$description, $category, $amount, $expenseDate]);
            finances_flash('success', 'Dépense enregistrée avec succès.');
            finances_redirect($periode);
        }

        if ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($id === false) throw new InvalidArgumentException('Identifiant invalide.');
            $stmt = $pdo->prepare('DELETE FROM expenses WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Dépense introuvable.');
            finances_flash('success', 'Dépense supprimée avec succès.');
            finances_redirect($periode);
        }
        throw new InvalidArgumentException('Action inconnue.');
    } catch (Throwable $e) {
        error_log('[Finances] ' . $e->getMessage());
        $error = 'Impossible de traiter la demande financière.';
        if (!empty($config['debug'])) $error .= ' [debug] ' . $e->getMessage();
    }
}

$today = new DateTimeImmutable('today');
$start = $end = null;
if ($periode === 'mois_courant') { $start = $today->modify('first day of this month')->format('Y-m-d'); $end = $today->format('Y-m-d'); }
elseif ($periode === 'mois_dernier') { $start = $today->modify('first day of last month')->format('Y-m-d'); $end = $today->modify('last day of last month')->format('Y-m-d'); }

$expenses = [];
$totalDepenses = 0;
$totalRecettes = 0;
try {
    $pdo = ara_db_supabase();
    if ($start !== null) {
        $stmt = $pdo->prepare('SELECT id, description, category, amount, expense_date FROM expenses WHERE expense_date BETWEEN ? AND ? ORDER BY expense_date DESC, id DESC');
        $stmt->execute([$start, $end]);
        $expenseTotal = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?');
        $expenseTotal->execute([$start, $end]);
        $sales = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM sales_log WHERE sale_date BETWEEN ? AND ?');
        $sales->execute([$start, $end]);
    } else {
        $stmt = $pdo->query('SELECT id, description, category, amount, expense_date FROM expenses ORDER BY expense_date DESC, id DESC');
        $expenseTotal = $pdo->query('SELECT COALESCE(SUM(amount),0) FROM expenses');
        $sales = $pdo->query('SELECT COALESCE(SUM(amount),0) FROM sales_log');
    }
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalDepenses = (int)$expenseTotal->fetchColumn();
    $totalRecettes = (int)$sales->fetchColumn();
} catch (Throwable $e) {
    error_log('[Finances] Supabase: ' . $e->getMessage());
    $error = 'Impossible de charger les données financières.';
    if (!empty($config['debug'])) $error .= ' [debug] ' . $e->getMessage();
}

$beneficeReel = $totalRecettes - $totalDepenses;
$categoryBadge = ['Internet'=>'primary','Électricité'=>'warning','Matériel'=>'info','Loyer'=>'dark','Autre'=>'secondary'];

include_once __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-12"><h2 class="mb-0">💰 Dépenses &amp; Bénéfices</h2></div>

    <?php if ($flash): ?><div class="col-12"><div class="alert alert-<?= ($flash['type'] ?? 'success') === 'success' ? 'success' : 'danger' ?>"><?= htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8') ?></div></div><?php endif; ?>
    <?php if ($error): ?><div class="col-12"><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div></div><?php endif; ?>

    <div class="col-12"><div class="card card-custom"><div class="card-header card-header-custom"><i class="bi bi-plus-circle"></i> Enregistrer une dépense</div><div class="card-body">
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="add"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="periode" value="<?= htmlspecialchars($periode, ENT_QUOTES, 'UTF-8') ?>">
            <div class="col-md-4"><label class="form-label" for="description">Description</label><input type="text" class="form-control" id="description" name="description" maxlength="500" required></div>
            <div class="col-md-2"><label class="form-label" for="category">Catégorie</label><select class="form-select" id="category" name="category" required><option value="" selected disabled>Choisir…</option><?php foreach (FINANCE_CATEGORIES as $category): ?><option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label" for="amount">Montant (FCFA)</label><input type="number" class="form-control" id="amount" name="amount" min="1" step="1" required></div>
            <div class="col-md-2"><label class="form-label" for="expense_date">Date</label><input type="date" class="form-control" id="expense_date" name="expense_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-2"><button class="btn btn-orange w-100" type="submit"><i class="bi bi-save"></i> Enregistrer</button></div>
        </form>
    </div></div></div>

    <div class="col-12"><form method="get" class="row g-2 align-items-end"><div class="col-md-3"><label class="form-label" for="periode">Période</label><select class="form-select" id="periode" name="periode" onchange="this.form.submit()"><option value="mois_courant" <?= $periode === 'mois_courant' ? 'selected' : '' ?>>Mois en cours</option><option value="mois_dernier" <?= $periode === 'mois_dernier' ? 'selected' : '' ?>>Mois dernier</option><option value="global" <?= $periode === 'global' ? 'selected' : '' ?>>Vue globale</option></select></div></form></div>

    <div class="col-md-4"><div class="card card-custom text-center p-3 h-100"><div class="stat-value text-success"><?= number_format($totalRecettes,0,',',' ') ?> FCFA</div><div class="stat-label">Recettes totales</div></div></div>
    <div class="col-md-4"><div class="card card-custom text-center p-3 h-100"><div class="stat-value text-danger"><?= number_format($totalDepenses,0,',',' ') ?> FCFA</div><div class="stat-label">Dépenses totales</div></div></div>
    <div class="col-md-4"><div class="card card-custom text-center p-3 h-100"><div class="stat-value <?= $beneficeReel >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($beneficeReel,0,',',' ') ?> FCFA</div><div class="stat-label">Bénéfice réel</div></div></div>

    <div class="col-12"><div class="card card-custom"><div class="card-header card-header-custom"><i class="bi bi-clock-history"></i> Historique des dépenses</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead class="table-dark"><tr><th>Date</th><th>Description</th><th>Catégorie</th><th>Montant</th><th>Action</th></tr></thead><tbody>
        <?php if (!$expenses): ?><tr><td colspan="5" class="text-center text-muted py-4">Aucune dépense pour cette période.</td></tr><?php else: foreach ($expenses as $expense): $cat = (string)$expense['category']; ?><tr><td><?= htmlspecialchars((string)$expense['expense_date'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)$expense['description'], ENT_QUOTES, 'UTF-8') ?></td><td><span class="badge bg-<?= htmlspecialchars($categoryBadge[$cat] ?? 'secondary', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></span></td><td><?= number_format((int)$expense['amount'],0,',',' ') ?> FCFA</td><td><form method="post" class="d-inline" onsubmit="return confirm('Confirmer la suppression de cette dépense ?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$expense['id'] ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="periode" value="<?= htmlspecialchars($periode, ENT_QUOTES, 'UTF-8') ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Supprimer</button></form></td></tr><?php endforeach; endif; ?>
    </tbody></table></div></div></div></div>
</div>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
