<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/RouterosAPI.php';
require_once __DIR__ . '/../lib/hotspot.php';
require_once __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';

// Connexion Supabase
$supaAvailable = false;
try {
    $pdoSupa = ara_db_supabase();
    $supaAvailable = true;
} catch (Throwable $e) {
    $pdoSupa = null;
}

// Traitement des actions (add, edit, delete)
$action = $_GET['action'] ?? 'list';
$profileId = $_GET['id'] ?? null;

if ($action === 'delete' && $profileId) {
    // On utilise le routeur pour la suppression (car Supabase est un miroir)
    $hotspot = new Hotspot($config['mikrotik']);
    if ($hotspot->isConnected()) {
        $hotspot->removeProfile($profileId);
        $hotspot->removeProfileScheduler($profileId);
        $hotspot->disconnect();
    }
    // On supprime aussi de Supabase pour rester synchro
    if ($supaAvailable) {
        try {
            $pdoSupa->prepare("DELETE FROM hotspot_profiles WHERE profile_name = ?")->execute([$profileId]);
        } catch (Throwable $e) {}
    }
    header('Location: hotspot.php?tab=profiles');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ajout/édition via le routeur (comme avant)
    $hotspot = new Hotspot($config['mikrotik']);
    if (!$hotspot->isConnected()) {
        echo '<div class="alert alert-danger">Connexion au routeur impossible.</div>';
        return;
    }
    $params = [
        'name' => preg_replace('/\s+/', '-', $_POST['name']),
        'address-pool' => $_POST['ppool'] ?? 'none',
        'rate-limit' => $_POST['ratelimit'] ?? '',
        'shared-users' => $_POST['sharedusers'] ?? '1',
        'status-autorefresh' => '1m',
        'parent-queue' => $_POST['parent'] ?? 'none',
        'on-login' => ':put (",' . ($_POST['expmode'] ?? '0') . ',' . ($_POST['price'] ?? '0') . ',' . ($_POST['validity'] ?? '') . ',' . ($_POST['sprice'] ?? '0') . ',,' . (($_POST['lockunlock'] ?? 'Disable') === 'Enable' ? 'Enable' : 'Disable') . ',");',
    ];
    if ($action === 'add') {
        $hotspot->addProfile($params);
    } elseif ($action === 'edit' && $profileId) {
        $hotspot->setProfile($profileId, $params);
    }
    $hotspot->disconnect();
    // Synchroniser vers Supabase sera fait lors du prochain passage du scheduler
    header('Location: hotspot.php?tab=profiles');
    exit;
}

// Lecture des profils (priorité Supabase)
$profiles = [];
$fromSupa = false;
if ($supaAvailable) {
    try {
        $stmt = $pdoSupa->query("SELECT * FROM hotspot_profiles ORDER BY profile_name ASC");
        $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($profiles)) {
            $fromSupa = true;
        }
    } catch (Throwable $e) {}
}

if (!$fromSupa) {
    // Fallback routeur
    $hotspot = new Hotspot($config['mikrotik']);
    if ($hotspot->isConnected()) {
        $rawProfiles = $hotspot->getProfiles();
        $profiles = array_map(function ($p) {
            return [
                'profile_name' => $p['name'] ?? '',
                'shared_users' => $p['shared-users'] ?? '1',
                'rate_limit'   => $p['rate-limit'] ?? '',
                'on_login'     => $p['on-login'] ?? '',
                'address_pool' => $p['address-pool'] ?? '',
            ];
        }, $rawProfiles);
        $hotspot->disconnect();
    }
}

// Pools et queues (toujours depuis le routeur car ça ne change pas souvent)
$hotspot2 = new Hotspot($config['mikrotik']);
$pools = $hotspot2->isConnected() ? $hotspot2->getIpPools() : [];
$queues = $hotspot2->isConnected() ? $hotspot2->getStaticQueues() : [];
$hotspot2->disconnect();
?>

<!-- Liste des profils -->
<div class="card card-custom">
    <div class="card-header bg-dark text-white">
        <h3><i class="bi bi-pie-chart"></i> Profils (<?= count($profiles) ?>) <?= $fromSupa ? '<small class="text-muted">via Supabase</small>' : '' ?>
            &nbsp; | &nbsp; <a href="hotspot.php?tab=profiles&action=add" class="btn btn-sm btn-orange"><i class="bi bi-plus-circle"></i> Ajouter</a>
        </h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover text-nowrap mb-0">
                <thead>
                    <tr>
                        <th>Actions</th>
                        <th>Nom</th>
                        <th>Shared Users</th>
                        <th>Rate Limit</th>
                        <th>Mode d'expiration</th>
                        <th>Validité</th>
                        <th>Prix (FCFA)</th>
                        <th>Prix de vente (FCFA)</th>
                        <th>Verrouillage</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($profiles as $p):
                    $onLogin = $p['on_login'] ?? '';
                    $parts = explode(',', $onLogin);
                    $expMode = $parts[1] ?? '';
                    $price = $parts[2] ?? '0';
                    $sprice = $parts[4] ?? '0';
                    $validity = $parts[3] ?? '';
                    $lock = $parts[6] ?? 'Disable';
                    $pname = $p['profile_name'] ?? $p['name'] ?? '';
                ?>
                    <tr>
                        <td>
                            <a href="hotspot.php?tab=profiles&action=delete&id=<?= urlencode($pname) ?>" 
                               onclick="return confirm('Supprimer le profil <?= htmlspecialchars($pname) ?> ?');"
                               class="text-danger"><i class="bi bi-trash"></i></a>
                            &nbsp;
                            <a href="hotspot.php?tab=profiles&action=edit&id=<?= urlencode($pname) ?>" title="Modifier"><i class="bi bi-pencil"></i></a>
                        </td>
                        <td><?= htmlspecialchars($pname) ?></td>
                        <td><?= htmlspecialchars($p['shared_users'] ?? '') ?></td>
                        <td><?= htmlspecialchars($p['rate_limit'] ?? '') ?></td>
                        <td><?= htmlspecialchars($expMode) ?></td>
                        <td><?= htmlspecialchars($validity) ?></td>
                        <td class="text-end"><?= number_format((float)$price, 0, ',', ' ') ?></td>
                        <td class="text-end"><?= number_format((float)$sprice, 0, ',', ' ') ?></td>
                        <td><?= htmlspecialchars($lock) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Formulaire d'ajout / édition -->
<?php if ($action === 'add' || ($action === 'edit' && $profileId)):
    // Récupérer le profil à éditer (depuis le routeur ou Supabase)
    $editProfile = null;
    if ($supaAvailable) {
        try {
            $stmt = $pdoSupa->prepare("SELECT * FROM hotspot_profiles WHERE profile_name = ?");
            $stmt->execute([$profileId]);
            $editProfile = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }
    if (!$editProfile) {
        $hotspot3 = new Hotspot($config['mikrotik']);
        if ($hotspot3->isConnected()) {
            $raw = $hotspot3->getProfile($profileId);
            $hotspot3->disconnect();
            if ($raw) {
                $editProfile = [
                    'profile_name'  => $raw['name'] ?? '',
                    'shared_users'  => $raw['shared-users'] ?? '1',
                    'rate_limit'    => $raw['rate-limit'] ?? '',
                    'on_login'      => $raw['on-login'] ?? '',
                    'address_pool'  => $raw['address-pool'] ?? '',
                    'parent_queue'  => $raw['parent-queue'] ?? 'none',
                ];
            }
        }
    }
    // Extraire les champs du on-login
    $onLogin = $editProfile['on_login'] ?? '';
    $parts = explode(',', $onLogin);
    $expMode = $parts[1] ?? '0';
    $price = $parts[2] ?? '';
    $sprice = $parts[4] ?? '';
    $validity = $parts[3] ?? '';
    $lock = $parts[6] ?? 'Disable';
?>
<div class="card card-custom mt-3">
    <div class="card-header bg-dark text-white">
        <h3><i class="bi bi-<?= $action === 'add' ? 'plus' : 'pencil' ?>"></i> 
            <?= $action === 'add' ? 'Ajouter un profil' : 'Modifier le profil' ?></h3>
    </div>
    <div class="card-body">
        <form method="post" action="">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Nom</label>
                    <input type="text" class="form-control" name="name" required 
                           value="<?= htmlspecialchars($editProfile['profile_name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Address Pool</label>
                    <select class="form-select" name="ppool">
                        <option value="none" <?= ($editProfile['address_pool'] ?? '') == 'none' ? 'selected' : '' ?>>none</option>
                        <?php foreach ($pools as $pool): ?>
                            <option value="<?= $pool['name'] ?>" <?= ($pool['name'] === ($editProfile['address_pool'] ?? '')) ? 'selected' : '' ?>>
                                <?= $pool['name'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Shared Users</label>
                    <input type="number" class="form-control" name="sharedusers" value="<?= htmlspecialchars($editProfile['shared_users'] ?? '1') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Rate Limit (up/down)</label>
                    <input type="text" class="form-control" name="ratelimit" placeholder="512k/1M" 
                           value="<?= htmlspecialchars($editProfile['rate_limit'] ?? '') ?>">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Mode d'expiration</label>
                    <select class="form-select" name="expmode">
                        <option value="0" <?= $expMode === '0' ? 'selected' : '' ?>>None</option>
                        <option value="rem" <?= $expMode === 'rem' ? 'selected' : '' ?>>Remove</option>
                        <option value="ntf" <?= $expMode === 'ntf' ? 'selected' : '' ?>>Notice</option>
                        <option value="remc" <?= $expMode === 'remc' ? 'selected' : '' ?>>Remove & Record</option>
                        <option value="ntfc" <?= $expMode === 'ntfc' ? 'selected' : '' ?>>Notice & Record</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Validité</label>
                    <input type="text" class="form-control" name="validity" placeholder="ex: 24h" 
                           value="<?= htmlspecialchars($validity) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Verrouillage</label>
                    <select class="form-select" name="lockunlock">
                        <option value="Disable" <?= $lock === 'Disable' ? 'selected' : '' ?>>Disable</option>
                        <option value="Enable" <?= $lock === 'Enable' ? 'selected' : '' ?>>Enable</option>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Prix (FCFA)</label>
                    <input type="number" class="form-control" name="price" value="<?= htmlspecialchars($price) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Prix de vente (FCFA)</label>
                    <input type="number" class="form-control" name="sprice" value="<?= htmlspecialchars($sprice) ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Parent Queue</label>
                <select class="form-select" name="parent">
                    <option value="none" <?= ($editProfile['parent_queue'] ?? '') == 'none' ? 'selected' : '' ?>>none</option>
                    <?php foreach ($queues as $q): ?>
                        <option value="<?= $q['name'] ?>" <?= ($q['name'] === ($editProfile['parent_queue'] ?? '')) ? 'selected' : '' ?>>
                            <?= $q['name'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-orange">Enregistrer</button>
            <a href="hotspot.php?tab=profiles" class="btn btn-secondary">Annuler</a>
        </form>
    </div>
</div>
<?php endif; ?>
