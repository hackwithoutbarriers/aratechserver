<?php
declare(strict_types=1);
// Ne pas inclure auth.php ici – la page hôte (hotspot.php) l'a déjà fait.
require_once __DIR__ . '/../lib/RouterosAPI.php';
require_once __DIR__ . '/../lib/hotspot.php';
$config = require __DIR__ . '/../config.php';

$hotspot = new Hotspot($config['mikrotik']);
if (!$hotspot->isConnected()) {
    echo '<div class="alert alert-danger">Connexion au routeur impossible.</div>';
    return;
}

// Actions sur les utilisateurs
if (isset($_GET['remove-user'])) {
    $hotspot->removeUser($_GET['remove-user']);
    header('Location: hotspot.php?tab=active');
    exit;
}
if (isset($_GET['enable-user'])) {
    $hotspot->enableUser($_GET['enable-user']);
    header('Location: hotspot.php?tab=active');
    exit;
}
if (isset($_GET['disable-user'])) {
    $hotspot->disableUser($_GET['disable-user']);
    header('Location: hotspot.php?tab=active');
    exit;
}
if (isset($_GET['remove-active'])) {
    $hotspot->removeActiveSession($_GET['remove-active']);
    header('Location: hotspot.php?tab=active');
    exit;
}

// Onglet "Sessions actives" – accessible via ?tab=active&view=sessions
$view = $_GET['view'] ?? 'users'; // 'users' ou 'sessions'

if ($view === 'sessions') {
    $activeSessions = $hotspot->getActiveSessions();
    ?>
    <div class="card card-custom">
        <div class="card-header bg-dark text-white">
            <h3><i class="bi bi-wifi"></i> Sessions actives (<?= count($activeSessions) ?>)</h3>
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
                    <?php foreach ($activeSessions as $s):
                        $uptime = isset($s['uptime']) ? formatDTM($s['uptime']) : '';
                        $bytesi = isset($s['bytes-in']) ? formatBytes($s['bytes-in'], 2) : '';
                        $byteso = isset($s['bytes-out']) ? formatBytes($s['bytes-out'], 2) : '';
                        $timeLeft = isset($s['session-time-left']) ? formatDTM($s['session-time-left']) : '';
                    ?>
                        <tr>
                            <td>
                                <a href="hotspot.php?tab=active&view=sessions&remove-active=<?= urlencode($s['.id']) ?>" 
                                   class="text-danger" onclick="return confirm('Déconnecter <?= htmlspecialchars($s['user'] ?? '') ?> ?');">
                                    <i class="bi bi-x-circle"></i>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($s['server'] ?? '') ?></td>
                            <td><?= htmlspecialchars($s['user'] ?? '') ?></td>
                            <td><?= htmlspecialchars($s['address'] ?? '') ?></td>
                            <td><?= htmlspecialchars($s['mac-address'] ?? '') ?></td>
                            <td class="text-end"><?= $uptime ?></td>
                            <td class="text-end"><?= $bytesi ?></td>
                            <td class="text-end"><?= $byteso ?></td>
                            <td class="text-end"><?= $timeLeft ?></td>
                            <td><?= htmlspecialchars($s['login-by'] ?? '') ?></td>
                            <td><?= htmlspecialchars($s['comment'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
} else {
    // Onglet "Utilisateurs enregistrés"
    $profileFilter = $_GET['profile'] ?? 'all';
    $commentFilter = $_GET['comment'] ?? '';
    $users = $hotspot->getUsers([
        'profile' => ($profileFilter !== 'all' ? $profileFilter : ''),
        'comment' => $commentFilter,
    ]);

    $profiles = $hotspot->getProfiles();
    ?>
    <div class="card card-custom">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <h3><i class="bi bi-people-fill"></i> Utilisateurs (<?= count($users) ?>)</h3>
            <a href="hotspot.php?tab=active&view=sessions" class="btn btn-sm btn-orange"><i class="bi bi-wifi"></i> Sessions actives</a>
        </div>
        <div class="card-body p-0">
            <!-- Filtres rapides -->
            <form method="get" class="p-2 bg-light border-bottom">
                <input type="hidden" name="tab" value="active">
                <div class="row g-2">
                    <div class="col-md-3">
                        <select class="form-select form-select-sm" name="profile" onchange="this.form.submit()">
                            <option value="all">Tous les profils</option>
                            <?php foreach ($profiles as $p):
                                $sel = ($p['name'] === $profileFilter) ? 'selected' : '';
                                echo "<option value=\"{$p['name']}\" $sel>{$p['name']}</option>";
                            endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="text" class="form-control form-control-sm" name="comment" placeholder="Filtrer par commentaire" value="<?= htmlspecialchars($commentFilter) ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-orange w-100"><i class="bi bi-funnel"></i> Filtrer</button>
                    </div>
                </div>
            </form>

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
                        $uid = $u['.id'] ?? '';
                        $uname = $u['name'] ?? '';
                        $uprofile = $u['profile'] ?? '';
                        $umac = $u['mac-address'] ?? '';
                        $uuptime = isset($u['uptime']) ? formatDTM($u['uptime']) : '';
                        $ubytesi = isset($u['bytes-in']) ? formatBytes($u['bytes-in'], 2) : '';
                        $ubyteso = isset($u['bytes-out']) ? formatBytes($u['bytes-out'], 2) : '';
                        $ucomment = $u['comment'] ?? '';
                        $udisabled = ($u['disabled'] ?? '') === 'true';
                    ?>
                        <tr>
                            <td>
                                <a href="hotspot.php?tab=active&remove-user=<?= urlencode($uid) ?>" class="text-danger" 
                                   onclick="return confirm('Supprimer l\'utilisateur <?= htmlspecialchars($uname) ?> ?');">
                                    <i class="bi bi-trash"></i>
                                </a>
                                <?php if ($udisabled): ?>
                                    <a href="hotspot.php?tab=active&enable-user=<?= urlencode($uid) ?>" class="text-warning" title="Activer">
                                        <i class="bi bi-lock-fill"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="hotspot.php?tab=active&disable-user=<?= urlencode($uid) ?>" title="Désactiver">
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
    <?php
}

$hotspot->disconnect();

// Helpers de formatage (à remplacer par l'inclusion de lib/format.php plus tard)
function formatDTM($val) {
    if (is_string($val)) {
        $parts = explode(':', $val);
        if (count($parts) === 3) {
            $sec = (int)$parts[0]*3600 + (int)$parts[1]*60 + (int)$parts[2];
        } else return $val;
    } else $sec = (int)$val;
    if ($sec < 0) $sec = 0;
    $d = floor($sec/86400); $h = floor(($sec%86400)/3600); $m = floor(($sec%3600)/60); $s = $sec%60;
    return ($d>0 ? "{$d}j " : "") . sprintf('%02d:%02d:%02d', $h, $m, $s);
}
function formatBytes($bytes, $prec=2) {
    if ($bytes <= 0) return '0 B';
    $units = ['B','KB','MB','GB','TB'];
    $i = floor(log($bytes,1024));
    return round($bytes/pow(1024,$i), $prec).' '.$units[$i];
}
