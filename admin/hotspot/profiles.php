<?php
declare(strict_types=1);
// Inclus comme onglet par admin/hotspot.php (auth.php déjà chargé par hotspot.php).
// Reste défensif si jamais ce fichier est un jour appelé isolément.
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    require_once __DIR__ . '/auth.php';
}
require_once __DIR__ . '/../lib/api_client.php';

$search = trim((string)($_GET['search'] ?? ''));

$result = ara_api_call($config, 'hotspot-profiles', $search !== '' ? ['search' => $search] : []);
$profiles = $result['success'] ? ($result['data']['items'] ?? []) : [];
$error    = $result['success'] ? null : $result['message'];
?>

<form method="get" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="tab" value="profiles">
    <div class="col-md-4">
        <label class="form-label">Rechercher un profil</label>
        <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Nom du profil">
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-orange w-100"><i class="bi bi-search"></i> Filtrer</button>
    </div>
</form>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php else: ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i>
        La création et la modification de profils depuis cette interface arrivent dans une prochaine phase
        (l'API confirme le contrat mais l'exécution routeur n'est pas encore branchée).
        Cet onglet affiche l'état actuellement synchronisé depuis MikroTik.
    </div>

    <div class="card card-custom">
        <div class="card-header card-header-custom">
            Profils Hotspot (<?= count($profiles) ?>)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Utilisateurs partagés</th>
                            <th>Limite de débit</th>
                            <th>Pool d'adresses</th>
                            <th>Script on-login</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($profiles)): ?>
                            <tr><td colspan="5" class="text-center text-muted">Aucun profil trouvé.</td></tr>
                        <?php else: ?>
                            <?php foreach ($profiles as $p): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($p['profile_name'] ?? '') ?></strong></td>
                                <td><?= (int)($p['shared_users'] ?? 1) ?></td>
                                <td><?= htmlspecialchars($p['rate_limit'] ?? '') ?></td>
                                <td><?= htmlspecialchars($p['address_pool'] ?? '') ?></td>
                                <td><code><?= htmlspecialchars($p['on_login'] ?? '') ?></code></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
