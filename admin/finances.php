<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';

$config = require __DIR__ . '/../config.php';
$pageTitle = 'Finances - ARA Tech WiFi';

const FINANCE_CATEGORIES = [
    'Internet',
    'Électricité',
    'Matériel',
    'Loyer',
    'Autre',
];

if (empty($_SESSION['finances_csrf'])) {
    $_SESSION['finances_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['finances_csrf'];

$periode = (string)($_GET['periode'] ?? $_POST['periode'] ?? 'mois_courant');
if (!in_array($periode, ['mois_courant', 'mois_dernier', 'global'], true)) {
    $periode = 'mois_courant';
}

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
    $_SESSION['finances_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

$flash = $_SESSION['finances_flash'] ?? null;
unset($_SESSION['finances_flash']);

$error = null;

/*
 * --------------------------------------------------------------------------
 * POST : ajout / suppression d'une dépense
 * --------------------------------------------------------------------------
 */
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
            $amountRaw = trim((string)($_POST['amount'] ?? ''));
            $expenseDate = trim((string)($_POST['expense_date'] ?? ''));

            if ($description === '') {
                throw new InvalidArgumentException('La description est obligatoire.');
            }

            if (mb_strlen($description) > 500) {
                throw new InvalidArgumentException('La description est trop longue.');
            }

            if (!in_array($category, FINANCE_CATEGORIES, true)) {
                throw new InvalidArgumentException('Catégorie de dépense invalide.');
            }

            $amount = filter_var(
                $amountRaw,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($amount === false) {
                throw new InvalidArgumentException(
                    'Le montant doit être un entier strictement positif.'
                );
            }

            if (!finances_valid_date($expenseDate)) {
                throw new InvalidArgumentException('La date de dépense est invalide.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO expenses
                    (description, category, amount, expense_date)
                 VALUES
                    (:description, :category, :amount, :expense_date)'
            );

            $stmt->execute([
                ':description' => $description,
                ':category' => $category,
                ':amount' => $amount,
                ':expense_date' => $expenseDate,
            ]);

            finances_flash('success', 'Dépense enregistrée avec succès.');
            finances_redirect($periode);
        }

        if ($action === 'delete') {
            $id = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($id === false) {
                throw new InvalidArgumentException(
                    'Identifiant de dépense invalide.'
                );
            }

            $stmt = $pdo->prepare(
                'DELETE FROM expenses WHERE id = :id'
            );

            $stmt->execute([
                ':id' => $id,
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'La dépense demandée est introuvable ou a déjà été supprimée.'
                );
            }

            finances_flash('success', 'Dépense supprimée avec succès.');
            finances_redirect($periode);
        }

        throw new InvalidArgumentException('Action financière inconnue.');
    } catch (Throwable $e) {
        error_log('[Finances] ' . $e->getMessage());

        $error = 'Impossible de traiter la demande financière.';

        if (!empty($config['debug'])) {
            $error .= ' [debug] ' . $e->getMessage();
        }
    }
}

/*
 * --------------------------------------------------------------------------
 * Période sélectionnée
 * --------------------------------------------------------------------------
 */
$periodStart = null;
$periodEnd = null;

$today = new DateTimeImmutable('today');

if ($periode === 'mois_courant') {
    $periodStart = $today
        ->modify('first day of this month')
        ->format('Y-m-d');

    $periodEnd = $today->format('Y-m-d');
} elseif ($periode === 'mois_dernier') {
    $periodStart = $today
        ->modify('first day of last month')
        ->format('Y-m-d');

    $periodEnd = $today
        ->modify('last day of last month')
        ->format('Y-m-d');
}

/*
 * --------------------------------------------------------------------------
 * Lecture Supabase / PostgreSQL
 * --------------------------------------------------------------------------
 */
$expenses = [];
$totalDepenses = 0;
$totalRecettes = 0;

try {
    $pdo = ara_db_supabase();

    if ($periodStart !== null && $periodEnd !== null) {
        /*
         * Dépenses de la période
         */
        $expenseStmt = $pdo->prepare(
            'SELECT
                id,
                description,
                category,
                amount,
                expense_date
             FROM expenses
             WHERE expense_date BETWEEN :start_date AND :end_date
             ORDER BY expense_date DESC, id DESC'
        );

        $expenseStmt->execute([
            ':start_date' => $periodStart,
            ':end_date' => $periodEnd,
        ]);

        /*
         * Total dépenses
         */
        $expenseTotalStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0)
             FROM expenses
             WHERE expense_date BETWEEN :start_date AND :end_date'
        );

        $expenseTotalStmt->execute([
            ':start_date' => $periodStart,
            ':end_date' => $periodEnd,
        ]);

        /*
         * Total recettes réel depuis sales_log.
         * sale_date est stocké en TEXT dans le schéma actuel,
         * comme le fait déjà get-sales.
         */
        $salesStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0)
             FROM sales_log
             WHERE sale_date BETWEEN :start_date AND :end_date'
        );

        $salesStmt->execute([
            ':start_date' => $periodStart,
            ':end_date' => $periodEnd,
        ]);
    } else {
        /*
         * Vue globale
         */
        $expenseStmt = $pdo->query(
            'SELECT
                id,
                description,
                category,
                amount,
                expense_date
             FROM expenses
             ORDER BY expense_date DESC, id DESC'
        );

        $expenseTotalStmt = $pdo->query(
            'SELECT COALESCE(SUM(amount), 0)
             FROM expenses'
        );

        $salesStmt = $pdo->query(
            'SELECT COALESCE(SUM(amount), 0)
             FROM sales_log'
        );
    }

    $expenses = $expenseStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalDepenses = (int)$expenseTotalStmt->fetchColumn();
    $totalRecettes = (int)$salesStmt->fetchColumn();
} catch (Throwable $e) {
    error_log(
        '[Finances] Lecture Supabase échouée : ' . $e->getMessage()
    );

    $error = 'Impossible de charger les données financières depuis Supabase.';

    if (!empty($config['debug'])) {
        $error .= ' [debug] ' . $e->getMessage();
    }
}

$beneficeReel = $totalRecettes - $totalDepenses;

$categoryBadge = [
    'Internet' => 'primary',
    'Électricité' => 'warning',
    'Matériel' => 'info',
    'Loyer' => 'dark',
    'Autre' => 'secondary',
];

require __DIR__ . '/header.php';
?>

<div class="container-fluid mt-4">
    <h2 class="mb-3">💰 Dépenses &amp; Bénéfices</h2>

    <?php if ($flash && isset($flash['message'])): ?>
        <div
            class="alert alert-<?= ($flash['type'] ?? 'success') === 'success'
                ? 'success'
                : 'danger' ?>"
            role="alert"
        >
            <?= htmlspecialchars(
                (string)$flash['message'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert">
            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>
    <?php endif; ?>

    <!-- Formulaire d'enregistrement -->
    <div class="card card-custom">
        <div class="card-header card-header-custom">
            <i class="bi bi-plus-circle"></i>
            Enregistrer une dépense
        </div>

        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="add">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $csrf,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="periode"
                    value="<?= htmlspecialchars(
                        $periode,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <div class="col-md-4">
                    <label class="form-label" for="description">
                        Description
                    </label>

                    <input
                        type="text"
                        class="form-control"
                        id="description"
                        name="description"
                        maxlength="500"
                        required
                    >
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="category">
                        Catégorie
                    </label>

                    <select
                        class="form-select"
                        id="category"
                        name="category"
                        required
                    >
                        <option value="" selected disabled>
                            Choisir…
                        </option>

                        <?php foreach (FINANCE_CATEGORIES as $category): ?>
                            <option
                                value="<?= htmlspecialchars(
                                    $category,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >
                                <?= htmlspecialchars(
                                    $category,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="amount">
                        Montant (FCFA)
                    </label>

                    <input
                        type="number"
                        class="form-control"
                        id="amount"
                        name="amount"
                        min="1"
                        step="1"
                        required
                    >
                </div>

                <div class="col-md-2">
                    <label class="form-label" for="expense_date">
                        Date
                    </label>

                    <input
                        type="date"
                        class="form-control"
                        id="expense_date"
                        name="expense_date"
                        value="<?= date('Y-m-d') ?>"
                        required
                    >
                </div>

                <div class="col-md-2">
                    <button
                        type="submit"
                        class="btn btn-orange w-100"
                    >
                        <i class="bi bi-save"></i>
                        Enregistrer la dépense
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Filtre de période -->
    <form
        method="get"
        class="row g-2 align-items-end mt-3 mb-1"
    >
        <div class="col-md-3">
            <label class="form-label" for="periode">
                Période
            </label>

            <select
                class="form-select"
                id="periode"
                name="periode"
                onchange="this.form.submit()"
            >
                <option
                    value="mois_courant"
                    <?= $periode === 'mois_courant'
                        ? 'selected'
                        : '' ?>
                >
                    Mois en cours
                </option>

                <option
                    value="mois_dernier"
                    <?= $periode === 'mois_dernier'
                        ? 'selected'
                        : '' ?>
                >
                    Mois dernier
                </option>

                <option
                    value="global"
                    <?= $periode === 'global'
                        ? 'selected'
                        : '' ?>
                >
                    Vue globale
                </option>
            </select>
        </div>
    </form>

    <!-- Indicateurs financiers -->
    <div class="row mt-2">
        <div class="col-md-4">
            <div class="card card-custom text-center p-3">
                <div class="stat-value text-success">
                    <?= number_format(
                        $totalRecettes,
                        0,
                        ',',
                        ' '
                    ) ?>
                    FCFA
                </div>

                <div class="stat-label">
                    Recettes totales
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card card-custom text-center p-3">
                <div class="stat-value text-danger">
                    <?= number_format(
                        $totalDepenses,
                        0,
                        ',',
                        ' '
                    ) ?>
                    FCFA
                </div>

                <div class="stat-label">
                    Dépenses totales
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card card-custom text-center p-3">
                <div
                    class="stat-value <?= $beneficeReel >= 0
                        ? 'text-success'
                        : 'text-danger' ?>"
                >
                    <?= number_format(
                        $beneficeReel,
                        0,
                        ',',
                        ' '
                    ) ?>
                    FCFA
                </div>

                <div class="stat-label">
                    Bénéfice réel
                </div>
            </div>
        </div>
    </div>

    <!-- Historique -->
    <div class="card card-custom mt-3">
        <div class="card-header card-header-custom">
            <i class="bi bi-clock-history"></i>
            Historique des dépenses
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table
                    class="table table-striped mb-0"
                    id="expensesTable"
                >
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Catégorie</th>
                            <th>Montant</th>
                            <th style="width: 130px">
                                Action
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($expenses)): ?>
                            <tr>
                                <td
                                    colspan="5"
                                    class="text-center text-muted py-4"
                                >
                                    Aucune dépense pour cette période.
                                </td>
                            </tr>
                        <?php else: ?>

                            <?php foreach ($expenses as $expense): ?>

                                <?php
                                $expenseId =
                                    (int)$expense['id'];

                                $expenseCategory =
                                    (string)$expense['category'];

                                $expenseAmount =
                                    (int)$expense['amount'];
                                ?>

                                <tr>
                                    <td>
                                        <?= htmlspecialchars(
                                            (string)$expense['expense_date'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string)$expense['description'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>

                                    <td>
                                        <span
                                            class="badge bg-<?= htmlspecialchars(
                                                $categoryBadge[
                                                    $expenseCategory
                                                ] ?? 'secondary',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                        >
                                            <?= htmlspecialchars(
                                                $expenseCategory,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= number_format(
                                            $expenseAmount,
                                            0,
                                            ',',
                                            ' '
                                        ) ?>
                                        FCFA
                                    </td>

                                    <td>
                                        <form
                                            method="post"
                                            class="d-inline"
                                            onsubmit="
                                                return confirm(
                                                    'Confirmer la suppression de cette dépense ?'
                                                );
                                            "
                                        >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= $expenseId ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= htmlspecialchars(
                                                    $csrf,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="periode"
                                                value="<?= htmlspecialchars(
                                                    $periode,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                                title="Supprimer"
                                            >
                                                <i class="bi bi-trash"></i>
                                                Supprimer
                                            </button>
                                        </form>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <a
        href="index.php"
        class="btn btn-outline-secondary mt-3"
    >
        <i class="bi bi-arrow-left"></i>
        Retour au tableau de bord
    </a>
</div>

</body>
</html>
