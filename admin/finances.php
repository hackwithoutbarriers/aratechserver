<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/business_sales.php';

$config = require __DIR__ . '/../config.php';
$pageTitle = 'Finances - ARA Tech WiFi';

const FINANCE_CATEGORIES = ['Internet', 'Électricité', 'Matériel', 'Loyer', 'Autre'];

if (empty($_SESSION['finances_csrf'])) {
    $_SESSION['finances_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['finances_csrf'];

$periode = (string)($_GET['periode'] ?? $_POST['periode'] ?? 'mois_courant');
if (!in_array($periode, ['mois_courant', 'mois_dernier', 'global'], true)) $periode = 'mois_courant';

function finances_redirect(string $periode): never
{
    header('Location: finances.php?periode=' . rawurlencode($periode));
    exit;
}

function finances_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function finances_flash(string $type, string $message): void
{
    $_SESSION['finances_flash'] = ['type' => $type, 'message' => $message];
}

$flash = $_SESSION['finances_flash'] ?? null;
unset($_SESSION['finances_flash']);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Requête refusée : token CSRF invalide.');
        }

        $action = (string)($_POST['action'] ?? '');
        $pdo = ara_db_supabase();

        if ($action === 'add') {
            $description = trim((string)($_POST['description'] ?? ''));
            $category = trim((string)($_POST['category'] ?? ''));
            $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $expenseDate = trim((string)($_POST['expense_date'] ?? ''));

            if ($description === '' || mb_strlen($description) > 500) throw new InvalidArgumentException('Description invalide.');
            if (!in_array($category, FINANCE_CATEGORIES, true)) throw new InvalidArgumentException('Catégorie invalide.');
            if ($amount === false) throw new InvalidArgumentException('Le montant doit être un entier strictement positif.');
            if (!finances_valid_date($expenseDate)) throw new InvalidArgumentException('Date invalide.');

            $stmt = $pdo->prepare('INSERT INTO expenses (description, category, amount, expense_date) VALUES (:description,:category,:amount,:expense_date)');
            $stmt->execute([':description'=>$description, ':category'=>$category, ':amount'=>$amount, ':expense_date'=>$expenseDate]);
            finances_flash('success', 'Dépense enregistrée avec succès.');
            finances_redirect($periode);
        }

        if ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new InvalidArgumentException('Identifiant de dépense invalide.');
            $stmt = $pdo->prepare('DELETE FROM expenses WHERE id = :id');
            $stmt->execute([':id'=>$id]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Dépense introuvable.');
            finances_flash('success', 'Dépense supprimée avec succès.');
            finances_redirect($periode);
        }

        throw new InvalidArgumentException('Action financière inconnue.');
    } catch (Throwable $e) {
        error_log('[Finances] ' . $e->getMessage());
        $error = !empty($config['debug']) ? 'Impossible de traiter la demande. [debug] ' . $e->getMessage() : 'Impossible de traiter la demande financière.';
    }
}

$today = new DateTimeImmutable('today');
if ($periode === 'mois_courant') {
    $periodStart = $today->modify('first day of this month')->format('Y-m-d');
    $periodEnd = $today->format('Y-m-d');
    $periodLabel = 'Mois en cours';
} elseif ($periode === 'mois_dernier') {
    $periodStart = $today->modify('first day of last month')->format('Y-m-d');
    $periodEnd = $today->modify('last day of last month')->format('Y-m-d');
    $periodLabel = 'Mois dernier';
} else {
    $periodStart = '1970-01-01';
    $periodEnd = $today->format('Y-m-d');
    $periodLabel = 'Vue globale';
}

$expenses = [];
$totalDepenses = 0;
$totalRecettes = 0;
$rawRows = 0;
$duplicatesRemoved = 0;
$sourceLabel = 'Activations Hotspot dédupliquées';

try {
    $pdo = ara_db_supabase();
    $expenseStmt = $pdo->prepare('SELECT id, description, category, amount, expense_date FROM expenses WHERE expense_date BETWEEN :start_date AND :end_date ORDER BY expense_date DESC, id DESC');
    $expenseStmt->execute([':start_date'=>$periodStart, ':end_date'=>$periodEnd]);
    $expenses = $expenseStmt->fetchAll(PDO::FETCH_ASSOC);

    $expenseTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN :start_date AND :end_date');
    $expenseTotalStmt->execute([':start_date'=>$periodStart, ':end_date'=>$periodEnd]);
    $totalDepenses = (int)$expenseTotalStmt->fetchColumn();

    $sales = ara_business_sales($pdo, $periodStart, $periodEnd);
    $totalRecettes = (int)$sales['revenue'];
    $rawRows = (int)$sales['raw_rows'];
    $duplicatesRemoved = (int)$sales['duplicates_removed'];
    $sourceLabel = (string)$sales['source_label'];
} catch (Throwable $e) {
    error_log('[Finances] Lecture Supabase échouée : ' . $e->getMessage());
    $error = !empty($config['debug']) ? 'Impossible de charger les données financières. [debug] ' . $e->getMessage() : 'Impossible de charger les données financières depuis Supabase.';
}

$beneficeReel = $totalRecettes - $totalDepenses;
$margin = $totalRecettes > 0 ? ($beneficeReel / $totalRecettes) * 100 : null;
$categoryBadge = ['Internet'=>'primary','Électricité'=>'warning','Matériel'=>'info','Loyer'=>'dark','Autre'=>'secondary'];
require __DIR__ . '/header.php';
?>
<div class="container-fluid mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><div class="text-uppercase small fw-semibold text-muted">Business Intelligence</div><h2 class="mb-1">Finances</h2><p class="text-muted mb-0">Dépenses et résultat basés sur la même source commerciale que Business et Rapports.</p></div>
        <form method="get"><select class="form-select form-select-sm" name="periode" onchange="this.form.submit()"><option value="mois_courant" <?= $periode==='mois_courant'?'selected':'' ?>>Mois en cours</option><option value="mois_dernier" <?= $periode==='mois_dernier'?'selected':'' ?>>Mois dernier</option><option value="global" <?= $periode==='global'?'selected':'' ?>>Vue globale</option></select></form>
    </div>
    <?php if ($flash && isset($flash['message'])): ?><div class="alert alert-<?= ($flash['type'] ?? 'success') === 'success' ? 'success' : 'danger' ?>"><?= htmlspecialchars((string)$flash['message'],ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
    <div class="alert alert-info py-2"><i class="bi bi-info-circle"></i> Source recettes : <strong><?= htmlspecialchars($sourceLabel,ENT_QUOTES,'UTF-8') ?></strong>. Les événements `log-sale` sont issus du `on-login` MikroTik.</div>

    <div class="card card-custom">
        <div class="card-header card-header-custom"><i class="bi bi-plus-circle"></i> Enregistrer une dépense</div>
        <div class="card-body"><form method="post" class="row g-2 align-items-end"><input type="hidden" name="action" value="add"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="periode" value="<?= htmlspecialchars($periode,ENT_QUOTES,'UTF-8') ?>">
            <div class="col-md-4"><label class="form-label">Description</label><input type="text" class="form-control" name="description" maxlength="500" required></div>
            <div class="col-md-2"><label class="form-label">Catégorie</label><select class="form-select" name="category" required><option value="" disabled selected>Choisir…</option><?php foreach(FINANCE_CATEGORIES as $category): ?><option value="<?= htmlspecialchars($category,ENT_QUOTES,'UTF-8') ?>"><?= htmlspecialchars($category,ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Montant (FCFA)</label><input type="number" class="form-control" name="amount" min="1" step="1" required></div>
            <div class="col-md-2"><label class="form-label">Date</label><input type="date" class="form-control" name="expense_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-2"><button type="submit" class="btn btn-orange w-100"><i class="bi bi-save"></i> Enregistrer</button></div>
        </form></div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-12 col-md-4"><div class="card card-custom p-3 h-100"><div class="stat-value text-success"><?= number_format($totalRecettes,0,',',' ') ?> FCFA</div><div class="stat-label">Montants journalisés · <?= htmlspecialchars($periodLabel) ?></div></div></div>
        <div class="col-12 col-md-4"><div class="card card-custom p-3 h-100"><div class="stat-value text-danger"><?= number_format($totalDepenses,0,',',' ') ?> FCFA</div><div class="stat-label">Dépenses · <?= htmlspecialchars($periodLabel) ?></div></div></div>
        <div class="col-12 col-md-4"><div class="card card-custom p-3 h-100"><div class="stat-value <?= $beneficeReel >= 0 ? 'text-success':'text-danger' ?>"><?= number_format($beneficeReel,0,',',' ') ?> FCFA</div><div class="stat-label">Résultat · Marge <?= $margin===null?'—':number_format($margin,1,',',' ').' %' ?></div></div></div>
    </div>

    <div class="card card-custom mt-3"><div class="card-header card-header-custom"><i class="bi bi-shield-check"></i> Qualité de la source commerciale</div><div class="card-body"><div class="row g-3"><div class="col-6 col-md-4"><strong><?= number_format($rawRows,0,',',' ') ?></strong><div class="text-muted small">Événements techniques</div></div><div class="col-6 col-md-4"><strong><?= number_format(max(0,$rawRows-$duplicatesRemoved),0,',',' ') ?></strong><div class="text-muted small">Activations retenues</div></div><div class="col-6 col-md-4"><strong><?= number_format($duplicatesRemoved,0,',',' ') ?></strong><div class="text-muted small">Reconnexions ignorées</div></div></div></div></div>

    <div class="card card-custom mt-3"><div class="card-header card-header-custom"><i class="bi bi-clock-history"></i> Historique des dépenses</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-striped mb-0"><thead class="table-dark"><tr><th>Date</th><th>Description</th><th>Catégorie</th><th>Montant</th><th>Action</th></tr></thead><tbody><?php if(!$expenses): ?><tr><td colspan="5" class="text-center text-muted py-4">Aucune dépense pour cette période.</td></tr><?php else: foreach($expenses as $expense): $cat=(string)$expense['category']; ?><tr><td><?= htmlspecialchars((string)$expense['expense_date'],ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)$expense['description'],ENT_QUOTES,'UTF-8') ?></td><td><span class="badge bg-<?= htmlspecialchars($categoryBadge[$cat]??'secondary',ENT_QUOTES,'UTF-8') ?>"><?= htmlspecialchars($cat,ENT_QUOTES,'UTF-8') ?></span></td><td><?= number_format((int)$expense['amount'],0,',',' ') ?> FCFA</td><td><form method="post" class="d-inline" onsubmit="return confirm('Confirmer la suppression de cette dépense ?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$expense['id'] ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="periode" value="<?= htmlspecialchars($periode,ENT_QUOTES,'UTF-8') ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach; endif; ?></tbody></table></div></div></div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
