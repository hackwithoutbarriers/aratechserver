<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';                // protection admin
require_once __DIR__ . '/../lib/RouterosAPI.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/hotspot.php';

// Connexion au routeur
$hotspot = new Hotspot($config['mikrotik']);
if (!$hotspot->isConnected()) {
    die('<div class="alert alert-danger">Connexion au routeur impossible.</div>');
}

// ---- Gestion des actions (add, edit, delete) ----
$action = $_GET['action'] ?? 'list';
$profileId = $_GET['id'] ?? null;

if ($action === 'delete' && $profileId) {
    // Suppression d'un profil
    $API->comm('/ip/hotspot/user/profile/remove', ['.id' => $profileId]);
    // Supprimer aussi le scheduler associé si nécessaire
    $API->comm('/system/scheduler/remove', ['.id' => $profileId]); // optionnel
    header('Location: profiles.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ajout ou édition
    $name = preg_replace('/\s+/', '-', $_POST['name']);
    $sharedusers = $_POST['sharedusers'] ?? '1';
    $ratelimit = $_POST['ratelimit'] ?? '';
    $expmode = $_POST['expmode'] ?? '0';
    $validity = $_POST['validity'] ?? '';
    $price = $_POST['price'] ?? '0';
    $sprice = $_POST['sprice'] ?? '0';
    $addrpool = $_POST['ppool'] ?? 'none';
    $lock = ($_POST['lockunlock'] ?? 'Disable') === 'Enable' ? '; ...' : ''; // à adapter
    $parent = $_POST['parent'] ?? 'none';

    // Construction des scripts on-login et bgservice (conservé depuis Mikhmon, simplifié)
    $record = ''; // on ne fait plus d'enregistrement de script (l'expiration est gérée autrement)
    $onlogin = ':put (",'.$expmode.',' . $price . ',' . $validity . ','.$sprice.',,' . ($lock ? 'Enable' : 'Disable') . ',");'; // script simplifié

    // Ajout ou modification
    if ($action === 'add') {
        $API->comm('/ip/hotspot/user/profile/add', [
            'name' => $name,
            'address-pool' => $addrpool,
            'rate-limit' => $ratelimit,
            'shared-users' => $sharedusers,
            'status-autorefresh' => '1m',
            'on-login' => $onlogin,
            'parent-queue' => $parent,
        ]);
    } elseif ($action === 'edit' && $profileId) {
        $API->comm('/ip/hotspot/user/profile/set', [
            '.id' => $profileId,
            'name' => $name,
            'address-pool' => $addrpool,
            'rate-limit' => $ratelimit,
            'shared-users' => $sharedusers,
            'status-autorefresh' => '1m',
            'on-login' => $onlogin,
            'parent-queue' => $parent,
        ]);
    }

    exit;
}

// ---- Récupération des données pour l'affichage ----
$profiles = $API->comm('/ip/hotspot/user/profile/print');
$TotalReg = count($profiles);
$countprofile = $API->comm('/ip/hotspot/user/profile/print', ['count-only' => '']);

// Pour le formulaire d'édition
$editProfile = null;
if ($action === 'edit' && $profileId) {
    $getprofile = $API->comm('/ip/hotspot/user/profile/print', ['.id' => $profileId]);
    if (!empty($getprofile)) {
        $editProfile = $getprofile[0];
    }
}

$API->disconnect();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Profils - ARA Tech WiFi</title>
    <!-- Intégration du header commun plus tard -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --bleu-nuit: #0b2c82; --orange: #f5a623; }
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .navbar-custom { background: var(--bleu-nuit); }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .btn-orange { background: var(--orange); border: none; color: #fff; font-weight: 600; border-radius: 8px; }
        .btn-orange:hover { background: #e5941f; color: #fff; }
    </style>
</head>
<body>
<nav class="navbar navbar-custom navbar-dark px-3">
    <a href="index.php" class="navbar-brand">⚡ ARA Tech WiFi Admin</a>
    <div class="ms-auto">
        <a href="logout.php" class="btn btn-outline-light btn-sm">Déconnexion</a>
    </div>
</nav>

<div class="container-fluid mt-4">
    <h2 class="mb-3">📋 Profils utilisateurs</h2>

    <!-- Liste des profils -->
    <div class="card card-custom">
        <div class="card-header bg-dark text-white">
            <h3><i class="bi bi-pie-chart"></i> Profils 
                &nbsp; | &nbsp; <a href="profiles.php?action=add" class="btn btn-sm btn-orange"><i class="bi bi-plus-circle"></i> Ajouter</a>
            </h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover text-nowrap mb-0">
                    <thead>
                    <tr>
                        <th style="min-width:50px;" class="text-center"><?= $countprofile ?> profil(s)</th>
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
                        $pid = $prof['.id'];
                        $pname = $prof['name'];
                        $psharedu = $prof['shared-users'] ?? '';
                        $pratelimit = $prof['rate-limit'] ?? '';
                        $ponlogin = $prof['on-login'] ?? '';
                        // Extraire les infos (simplifié)
                        $expmode = explode(',', $ponlogin)[1] ?? '';
                        $price = explode(',', $ponlogin)[2] ?? '0';
                        $sprice = explode(',', $ponlogin)[4] ?? '0';
                        $validity = explode(',', $ponlogin)[3] ?? '';
                        $lock = explode(',', $ponlogin)[6] ?? 'Disable';
                        // Couleur statut (via scheduler - optionnel)
                        $moncolor = 'text-green'; // simplifié
                    ?>
                    <tr>
                        <td class="text-center">
                            <a href="profiles.php?action=delete&id=<?= urlencode($pid) ?>" 
                               onclick="return confirm('Supprimer le profil <?= htmlspecialchars($pname) ?> ?');"
                               class="text-danger" title="Supprimer">
                                <i class="bi bi-trash"></i>
                            </a>
                            &nbsp;
                            <a href="profiles.php?action=edit&id=<?= urlencode($pid) ?>" title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($pname) ?></td>
                        <td><?= htmlspecialchars($psharedu) ?></td>
                        <td><?= htmlspecialchars($pratelimit) ?></td>
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
        $formProfile = $action === 'edit' ? $editProfile : [];
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
                               value="<?= htmlspecialchars($formProfile['name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Address Pool</label>
                        <select class="form-select" name="ppool">
                            <option <?= ($formProfile['address-pool'] ?? '') == 'none' ? 'selected' : '' ?>>none</option>
                            <?php
                            $pools = $API->comm('/ip/pool/print');
                            foreach ($pools as $pool) {
                                $selected = ($pool['name'] === ($formProfile['address-pool'] ?? '')) ? 'selected' : '';
                                echo "<option $selected>{$pool['name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Shared Users</label>
                        <input type="number" class="form-control" name="sharedusers" value="<?= htmlspecialchars($formProfile['shared-users'] ?? '1') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Rate Limit (up/down)</label>
                        <input type="text" class="form-control" name="ratelimit" placeholder="512k/1M" 
                               value="<?= htmlspecialchars($formProfile['rate-limit'] ?? '') ?>">
                    </div>
                </div>
                <!-- simplifié : autres champs (exp mode, validity, price, etc.) -->
                <button type="submit" class="btn btn-orange">Enregistrer</button>
                <a href="profiles.php" class="btn btn-secondary">Annuler</a>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
