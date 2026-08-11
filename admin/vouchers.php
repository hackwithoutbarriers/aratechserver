<?php
declare(strict_types=1);
// Inclus comme onglet par admin/hotspot.php (auth.php déjà chargé par hotspot.php).
// Reste défensif si jamais ce fichier est un jour appelé isolément.
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    require_once __DIR__ . '/auth.php';
}
require_once __DIR__ . '/../lib/api_client.php';

// NOTE MIGRATION : l'ancienne implémentation (classe Voucher, connexion directe
// au routeur via lib/voucher.php) a été retirée du dépôt lors du passage à
// l'architecture Supabase-mirror + hotspot_commands (Phases 3-5). Le fichier
// lib/voucher.php et le dossier vouchers/ n'existent plus, ce qui provoquait une
// erreur fatale PHP à chaque ouverture de cet onglet. Cette version lit désormais
// les vouchers via la route API hotspot-vouchers (déjà implémentée et alimentée
// par le miroir hotspot_users), au lieu de parler au routeur en direct.

$search  = trim((string)($_GET['search'] ?? ''));
$profile = trim((string)($_GET['profile'] ?? 'all'));
$page    = max(1, (int)($_GET['page'] ?? 1));

$query = ['page' => $page, 'limit' => 25];
if ($search !== '')        $query['search']  = $search;
if ($profile !== 'all')    $query['profile'] = $profile;

$result   = ara_api_call($config, 'hotspot-vouchers', $query);
$vouchers = $result['success'] ? ($result['data']['items'] ?? []) : [];
$total    = $result['success'] ? ($result['data']['total'] ?? 0) : 0;
$error    = $result['success'] ? null : $result['message'];
?>

<form method="get" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="tab" value="vouchers">
    <div class="col-md-4">
        <label class="form-label">Rechercher (utilisateur ou commentaire)</label>
        <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>">
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-orange w-100"><i class="bi bi-search"></i> Filtrer</button>
    </div>
    <div class="col-md-6 text-end">
        <button class="btn btn-outline-secondary" disabled title="Disponible dans une prochaine phase (route API pas encore branchée au routeur)">
            <i class="bi bi-ticket-perforated"></i> Générer des vouchers
        </button>
    </div>
</form>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php else: ?>
    <div class="card card-custom">
        <div class="card-header card-header-custom">
            Vouchers actifs (<?= (int)$total ?>)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead>
                        <tr>
                            <th>Utilisateur</th>
                            <th>Profil</th>
                            <th>Commentaire</th>
                            <th>Expiration</th>
                            <th>État</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vouchers)): ?>
                            <tr><td colspan="5" class="text-center text-muted">Aucun voucher trouvé.</td></tr>
                        <?php else: ?>
                            <?php foreach ($vouchers as $v): ?>
                            <tr>
                                <td><?= htmlspecialchars($v['username'] ?? '') ?></td>
                                <td><?= htmlspecialchars($v['profile'] ?? '') ?></td>
                                <td><?= htmlspecialchars($v['comment'] ?? '') ?></td>
                                <td><?= htmlspecialchars($v['expiry'] ?? '—') ?></td>
                                <td>
                                    <?php if (!empty($v['disabled'])): ?>
                                        <span class="badge bg-secondary">Désactivé</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Actif</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
