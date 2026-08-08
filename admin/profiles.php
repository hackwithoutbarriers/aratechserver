<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/RouterosAPI.php';
require_once __DIR__ . '/../lib/hotspot.php';
$config = require __DIR__ . '/../config.php';

// Connexion au routeur
$hotspot = new Hotspot($config['mikrotik']);
if (!$hotspot->isConnected()) {
    echo '<div class="alert alert-danger">Connexion au routeur impossible.</div>';
    return;
}

// ---- Gestion des actions (add, edit, delete) ----
$action = $_GET['action'] ?? 'list';
$profileId = $_GET['id'] ?? null;

if ($action === 'delete' && $profileId) {
    $hotspot->removeProfile($profileId);
    // Supprimer aussi le scheduler associé (par précaution)
    $hotspot->removeProfileScheduler($profileId);
    header('Location: hotspot.php?tab=profiles');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupération des paramètres communs
    $name = preg_replace('/\s+/', '-', $_POST['name']);
    $params = [
        'name' => $name,
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
        $params['.id'] = $profileId;
        $hotspot->setProfile($profileId, $params);
    }

    header('Location: hotspot.php?tab=profiles');
    exit;
}

// ---- Récupération des données pour l'affichage ----
$profiles = $hotspot->getProfiles();
$countprofile = count($profiles);

// Pour le formulaire d'édition
$editProfile = null;
if ($action === 'edit' && $profileId) {
    $editProfile = $hotspot->getProfile($profileId);
}

// Pools IP et queues pour les listes déroulantes
$pools = $hotspot->getIpPools();
$queues = $hotspot->getStaticQueues();
$hotspot->disconnect();
?>

<!-- Affichage de la liste -->
<div class="card card-custom">
    <div class="card-header bg-dark text-white">
        <h3><i class="bi bi-pie-chart"></i> Profils 
            &nbsp; | &nbsp; <a href="hotspot.php?tab=profiles&action=add" class="btn btn-sm btn-orange"><i class="bi bi-plus-circle"></i> Ajouter</a>
        </h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover text-nowrap mb-0">
                <thead>
                    <tr>
                        <th class="text-center"><?= $countprofile ?> profil(s)</th>
                        <th>Nom</th>
                        <th>Utilisateurs partagés</th>
                        <th>Rate Limit</th>
                        <th>Mode d'expiration</th>
                        <th>Validité</th>
                        <th class="text-right">Prix (FCFA)</th>
                        <th class="text-right">Prix de vente (FCFA)</th>
                        <th>Verrouillage</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($profiles as $prof):
                    $ponlogin = $prof['on-login'] ?? '';
                    $parts = explode(',', $ponlogin);
                    $expmode = $parts[1] ?? '';
                    $price = $parts[2] ?? '0';
                    $sprice = $parts[4] ?? '0';
                    $validity = $parts[3] ?? '';
                    $lock = $parts[6] ?? 'Disable';
                ?>
                    <tr>
                        <td class="text-center">
                            <a href="hotspot.php?tab=profiles&action=delete&id=<?= urlencode($prof['.id']) ?>" 
                               onclick="return confirm('Supprimer le profil <?= htmlspecialchars($prof['name']) ?> ?');"
                               class="text-danger" title="Supprimer"><i class="bi bi-trash"></i></a>
                            &nbsp;
                            <a href="hotspot.php?tab=profiles&action=edit&id=<?= urlencode($prof['.id']) ?>" title="Modifier"><i class="bi bi-pencil"></i></a>
                        </td>
                        <td><?= htmlspecialchars($prof['name']) ?></td>
                        <td><?= htmlspecialchars($prof['shared-users'] ?? '') ?></td>
                        <td><?= htmlspecialchars($prof['rate-limit'] ?? '') ?></td>
                        <td><?= htmlspecialchars($expmode) ?></td>
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
<?php if ($action === 'add' || ($action === 'edit' && $editProfile)):
    $form = $action === 'edit' ? $editProfile : [];
    $formOnLogin = $form['on-login'] ?? '';
    $formParts = explode(',', $formOnLogin);
    $formExpMode = $formParts[1] ?? '0';
    $formPrice = $formParts[2] ?? '';
    $formSprice = $formParts[4] ?? '';
    $formValidity = $formParts[3] ?? '';
    $formLock = $formParts[6] ?? 'Disable';
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
                           value="<?= htmlspecialchars($form['name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Address Pool</label>
                    <select class="form-select" name="ppool">
                        <option value="none" <?= ($form['address-pool'] ?? '') == 'none' ? 'selected' : '' ?>>none</option>
                        <?php foreach ($pools as $pool):
                            $sel = ($pool['name'] === ($form['address-pool'] ?? '')) ? 'selected' : '';
                            echo "<option value=\"{$pool['name']}\" $sel>{$pool['name']}</option>";
                        endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Shared Users</label>
                    <input type="number" class="form-control" name="sharedusers" value="<?= htmlspecialchars($form['shared-users'] ?? '1') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Rate Limit (up/down)</label>
                    <input type="text" class="form-control" name="ratelimit" placeholder="512k/1M" 
                           value="<?= htmlspecialchars($form['rate-limit'] ?? '') ?>">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Mode d'expiration</label>
                    <select class="form-select" name="expmode">
                        <option value="0" <?= $formExpMode === '0' ? 'selected' : '' ?>>None</option>
                        <option value="rem" <?= $formExpMode === 'rem' ? 'selected' : '' ?>>Remove</option>
                        <option value="ntf" <?= $formExpMode === 'ntf' ? 'selected' : '' ?>>Notice</option>
                        <option value="remc" <?= $formExpMode === 'remc' ? 'selected' : '' ?>>Remove & Record</option>
                        <option value="ntfc" <?= $formExpMode === 'ntfc' ? 'selected' : '' ?>>Notice & Record</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Validité</label>
                    <input type="text" class="form-control" name="validity" placeholder="ex: 24h" 
                           value="<?= htmlspecialchars($formValidity) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Verrouillage</label>
                    <select class="form-select" name="lockunlock">
                        <option value="Disable" <?= $formLock === 'Disable' ? 'selected' : '' ?>>Disable</option>
                        <option value="Enable" <?= $formLock === 'Enable' ? 'selected' : '' ?>>Enable</option>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Prix (FCFA)</label>
                    <input type="number" class="form-control" name="price" value="<?= htmlspecialchars($formPrice) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Prix de vente (FCFA)</label>
                    <input type="number" class="form-control" name="sprice" value="<?= htmlspecialchars($formSprice) ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Parent Queue</label>
                <select class="form-select" name="parent">
                    <option value="none" <?= ($form['parent-queue'] ?? '') == 'none' ? 'selected' : '' ?>>none</option>
                    <?php foreach ($queues as $q):
                        $sel = ($q['name'] === ($form['parent-queue'] ?? '')) ? 'selected' : '';
                        echo "<option value=\"{$q['name']}\" $sel>{$q['name']}</option>";
                    endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-orange">Enregistrer</button>
            <a href="hotspot.php?tab=profiles" class="btn btn-secondary">Annuler</a>
        </form>
    </div>
</div>
<?php endif; ?>
