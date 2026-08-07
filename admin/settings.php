<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';

$pdo = ara_db($config);

// --- État des connexions ---
$tursoOK = false;
if (!empty($config['turso']['url']) && !empty($config['turso']['token'])) {
    try {
        // Test rapide Turso : lire le nombre de lignes dans sales_log (table légère)
        $results = turso_pipeline($config, [['sql' => 'SELECT COUNT(*) AS cnt FROM sales_log', 'args' => []]]);
        foreach ($results as $r) {
            if (!empty($r['response']['result']['cols'])) {
                $tursoOK = true;
                break;
            }
        }
    } catch (Throwable $e) {
        $tursoOK = false;
    }
} else {
    $tursoOK = false;
}

// Base locale
$localDBok = file_exists($config['db_path']);

// --- Changement de mot de passe ---
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $oldPass = $_POST['old_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $hash = getenv('ADMIN_PASSWORD_HASH') ?: $config['admin_password_hash'] ?? '';

    if (!password_verify($oldPass, $hash)) {
        $message = '<div class="alert alert-danger">Ancien mot de passe incorrect.</div>';
    } elseif (strlen($newPass) < 6) {
        $message = '<div class="alert alert-danger">Le nouveau mot de passe doit contenir au moins 6 caractères.</div>';
    } else {
        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
        // Afficher le hash pour mise à jour manuelle dans Render
        $message = '<div class="alert alert-success">
            <strong>Nouveau hash généré :</strong><br>
            <code>' . htmlspecialchars($newHash) . '</code><br>
            Copiez cette valeur dans la variable d\'environnement <code>ADMIN_PASSWORD_HASH</code> de Render, puis redéployez.
        </div>';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';
$pageTitle = 'Configuration - ARA Tech WiFi';
require __DIR__ . '/header.php';
<body>
    <nav class="navbar navbar-custom navbar-dark px-3">
        <a href="index.php" class="navbar-brand">⚡ ARA Tech WiFi Admin</a>
        <div class="ms-auto">
            <a href="logout.php" class="btn btn-outline-light btn-sm">Déconnexion</a>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <h2 class="mb-3">⚙️ Configuration du système</h2>

        <?= $message ?>

        <!-- État des connexions -->
        <div class="row">
            <div class="col-md-4">
                <div class="card card-custom text-center p-3">
                    <h5>Base de données Turso</h5>
                    <p class="<?= $tursoOK ? 'status-ok' : 'status-ko' ?>">
                        <?= $tursoOK ? '✅ Connectée' : '❌ Non connectée' ?>
                    </p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-custom text-center p-3">
                    <h5>Base locale SQLite</h5>
                    <p class="<?= $localDBok ? 'status-ok' : 'status-ko' ?>">
                        <?= $localDBok ? '✅ Présente' : '❌ Absente' ?>
                    </p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-custom text-center p-3">
                    <h5>Version PHP</h5>
                    <p><?= phpversion() ?></p>
                </div>
            </div>
        </div>

        <!-- Formulaire de changement de mot de passe -->
        <div class="card card-custom mt-3">
            <div class="card-header card-header-custom">Changer le mot de passe administrateur</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Ancien mot de passe</label>
                        <input type="password" class="form-control" name="old_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nouveau mot de passe (6+ caractères)</label>
                        <input type="password" class="form-control" name="new_password" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-orange">Générer le nouveau hash</button>
                </form>
                <div class="mt-3 text-muted small">
                    <i class="bi bi-info-circle"></i> Pour des raisons de sécurité, le mot de passe n'est pas modifié automatiquement. 
                    Le hash ci-dessus doit être copié dans la variable <code>ADMIN_PASSWORD_HASH</code> de votre service Render, puis un redéploiement est nécessaire.
                </div>
            </div>
        </div>

        <a href="index.php" class="btn btn-outline-secondary mt-3"><i class="bi bi-arrow-left"></i> Retour au tableau de bord</a>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
