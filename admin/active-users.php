<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../lib/RouterosAPI.php';
$config = require __DIR__ . '/../config.php';

// Connexion au routeur
$API = new RouterosAPI();
$connected = $API->connect(
    $config['mikrotik']['host'],
    $config['mikrotik']['api_user'],
    $config['mikrotik']['api_password'],
    (int)$config['mikrotik']['api_port']
);
if (!$connected) {
    die('<div class="alert alert-danger">Impossible de se connecter au routeur.</div>');
}

// Actions sur les utilisateurs
if (isset($_GET['remove-user'])) {
    $API->comm('/ip/hotspot/user/remove', ['.id' => $_GET['remove-user']]);
    header('Location: active-users.php');
    exit;
}
if (isset($_GET['enable-user'])) {
    $API->comm('/ip/hotspot/user/enable', ['.id' => $_GET['enable-user']]);
    header('Location: active-users.php');
    exit;
}
if (isset($_GET['disable-user'])) {
    $API->comm('/ip/hotspot/user/disable', ['.id' => $_GET['disable-user']]);
    header('Location: active-users.php');
    exit;
}

// Paramètres
$session = $_GET['session'] ?? '';
$serveractive = $_GET['server'] ?? '';
$prof = $_GET['profile'] ?? 'all';
$comm = $_GET['comment'] ?? '';
$exp = $_GET['exp'] ?? '';

// ---- PARTIE 1 : Sessions actives ----
if (isset($_GET['active'])) {
    if ($serveractive !== '') {
        $activeUsers = $API->comm('/ip/hotspot/active/print', ['?server' => $serveractive]);
        $countActive = $API->comm('/ip/hotspot/active/print', ['count-only' => '', '?server' => $serveractive]);
    } else {
        $activeUsers = $API->comm('/ip/hotspot/active/print');
        $countActive = $API->comm('/ip/hotspot/active/print', ['count-only' => '']);
    }

    // Affichage sessions actives
    ?>
    <div class="container-fluid mt-4">
        <h2 class="mb-3"><i class="bi bi-wifi"></i> Sessions actives</h2>
        <div class="card card-custom">
            <div class="card-header bg-dark text-white">
                <h3><?= htmlspecialchars($serveractive ?: 'Tous les serveurs') ?> - <?= $countActive ?> session(s)</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover text-nowrap mb-0">
                        <thead>
                            <tr>
                                <th>Actions</th>
                                <th>Serveur</th>
                                <th>Utilisateur</th>
                                <th>Adresse IP</th>
                                <th>MAC</th>
                                <th>Uptime</th>
                                <th>Bytes In</th>
                                <th>Bytes Out</th>
                                <th>Temps restant</th>
                                <th>Login By</th>
                                <th>Commentaire</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeUsers as $user): 
                            $id = $user['.id'];
                            $server = $user['server'] ?? '';
                            $username = $user['user'] ?? '';
                            $address = $user['address'] ?? '';
                            $mac = $user['mac-address'] ?? '';
                            $uptime = formatDTM($user['uptime'] ?? '');
                            $bytesi = formatBytes($user['bytes-in'] ?? 0, 2);
                            $byteso = formatBytes($user['bytes-out'] ?? 0, 2);
                            $sessionTimeLeft = formatDTM($user['session-time-left'] ?? '');
                            $loginby = $user['login-by'] ?? '';
                            $comment = $user['comment'] ?? '';
                        ?>
                            <tr>
                                <td>
                                    <a href="?active=1&remove=<?= urlencode($id) ?>" class="text-danger" 
                                       onclick="return confirm('Supprimer la session de <?= htmlspecialchars($username) ?> ?');">
                                        <i class="bi bi-x-circle"></i>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($server) ?></td>
                                <td><?= htmlspecialchars($username) ?></td>
                                <td><?= htmlspecialchars($address) ?></td>
                                <td><?= htmlspecialchars($mac) ?></td>
                                <td class="text-end"><?= $uptime ?></td>
                                <td class="text-end"><?= $bytesi ?></td>
                                <td class="text-end"><?= $byteso ?></td>
                                <td class="text-end"><?= $sessionTimeLeft ?></td>
                                <td><?= htmlspecialchars($loginby) ?></td>
                                <td><?= htmlspecialchars($comment) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <a href="active-users.php" class="btn btn-outline-secondary mt-3"><i class="bi bi-arrow-left"></i> Tous les utilisateurs</a>
    </div>
    <?php
    exit;
}

// Gestion suppression session active
if (isset($_GET['remove'])) {
    $API->comm('/ip/hotspot/active/remove', ['.id' => $_GET['remove']]);
    header('Location: active-users.php?active=1');
    exit;
}

// ---- PARTIE 2 : Utilisateurs enregistrés ----

// Récupération des utilisateurs selon les filtres
if ($comm !== '') {
    $users = $API->comm('/ip/hotspot/user/print', ['?comment' => $comm]);
} elseif ($exp === '1') {
    $users = $API->comm('/ip/hotspot/user/print', ['?limit-uptime' => '1s']);
} elseif ($prof !== 'all') {
    $users = $API->comm('/ip/hotspot/user/print', ['?profile' => $prof]);
} else {
    $users = $API->comm('/ip/hotspot/user/print');
}
$totalUsers = count($users);

// Profils disponibles pour le filtre
$profiles = $API->comm('/ip/hotspot/user/profile/print');

$API->disconnect();

// Helpers (à déplacer dans lib/format.php)
function formatDTM($seconds) {
    if (!is_numeric($seconds)) return $seconds;
    $d = floor($seconds / 86400);
    $h = floor(($seconds % 86400) / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return ($d > 0 ? $d.'j ' : '') . sprintf('%02d:%02d:%02d', $h, $m, $s);
}
function formatBytes($bytes, $precision = 2) {
    if ($bytes == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), $precision) . ' ' . $units[$i];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Utilisateurs - ARA Tech WiFi</title>
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
    <h2 class="mb-3"><i class="bi bi-people-fill"></i> Utilisateurs</h2>
    
    <!-- Barre de filtres -->
    <div class="row mb-3">
        <div class="col-md-3">
            <form method="get" class="input-group">
                <input type="text" class="form-control" placeholder="Rechercher..." id="filterTable">
            </form>
        </div>
        <div class="col-md-3">
            <select class="form-select" onchange="location = this.value;">
                <option value="active-users.php">Tous les profils</option>
                <?php foreach ($profiles as $p): 
                    $sel = ($p['name'] === $prof) ? 'selected' : '';
                ?>
                <option value="active-users.php?profile=<?= urlencode($p['name']) ?>" <?= $sel ?>><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <a href="active-users.php?active=1" class="btn btn-orange"><i class="bi bi-wifi"></i> Sessions actives</a>
        </div>
    </div>

    <div class="card card-custom">
        <div class="card-header bg-dark text-white">
            <h3><?= $totalUsers ?> utilisateur(s)</h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover text-nowrap mb-0">
                    <thead>
                        <tr>
                            <th>Actions</th>
                            <th>Nom</th>
                            <th>Profil</th>
                            <th>MAC</th>
                            <th class="text-end">Uptime</th>
                            <th class="text-end">Bytes In</th>
                            <th class="text-end">Bytes Out</th>
                            <th>Commentaire</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u): 
                        $uid = $u['.id'];
                        $uname = $u['name'] ?? '';
                        $uprofile = $u['profile'] ?? '';
                        $umac = $u['mac-address'] ?? '';
                        $uuptime = formatDTM($u['uptime'] ?? '');
                        $ubytesi = formatBytes($u['bytes-in'] ?? 0, 2);
                        $ubyteso = formatBytes($u['bytes-out'] ?? 0, 2);
                        $ucomment = $u['comment'] ?? '';
                        $udisabled = ($u['disabled'] ?? '') === 'true';
                    ?>
                        <tr>
                            <td>
                                <a href="?remove-user=<?= urlencode($uid) ?>" class="text-danger" 
                                   onclick="return confirm('Supprimer l\'utilisateur <?= htmlspecialchars($uname) ?> ?');">
                                    <i class="bi bi-trash"></i>
                                </a>
                                <?php if ($udisabled): ?>
                                    <a href="?enable-user=<?= urlencode($uid) ?>" class="text-warning" title="Activer">
                                        <i class="bi bi-lock-fill"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="?disable-user=<?= urlencode($uid) ?>" title="Désactiver">
                                        <i class="bi bi-unlock-fill"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($uname) ?></td>
                            <td><?= htmlspecialchars($uprofile) ?></td>
                            <td><?= htmlspecialchars($umac) ?></td>
                            <td class="text-end"><?= $uuptime ?></td>
                            <td class="text-end"><?= $ubytesi ?></td>
                            <td class="text-end"><?= $ubyteso ?></td>
                            <td><?= htmlspecialchars($ucomment) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <a href="index.php" class="btn btn-outline-secondary mt-3"><i class="bi bi-arrow-left"></i> Retour</a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Filtre rapide
document.getElementById('filterTable')?.addEventListener('keyup', function() {
    const filter = this.value.toUpperCase();
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach(row => {
        row.style.display = row.textContent.toUpperCase().includes(filter) ? '' : 'none';
    });
});
</script>
</body>
</html>
