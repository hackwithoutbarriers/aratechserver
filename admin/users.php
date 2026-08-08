<?php
declare(strict_types=1);
// auth.php déjà chargé par hotspot.php – ne pas le remettre

require_once __DIR__ . '/../lib/RouterosAPI.php';
require_once __DIR__ . '/../lib/hotspot.php';
require_once __DIR__ . '/../lib/format.php';        // formatBytes, formatDTM, etc.
$config = require __DIR__ . '/../config.php';

$hotspot = new Hotspot($config['mikrotik']);
if (!$hotspot->isConnected()) {
    echo '<div class="alert alert-danger">Connexion au routeur impossible.</div>';
    return;
}

// Paramètres de filtre (transmis par l'URL)
$profileFilter = $_GET['profile'] ?? 'all';
$commentFilter = $_GET['comment'] ?? '';

$filters = [];
if ($profileFilter !== 'all') {
    $filters['profile'] = $profileFilter;
}
if ($commentFilter !== '') {
    $filters['comment'] = $commentFilter;
}

$users = $hotspot->getUsers($filters);
$profiles = $hotspot->getProfiles();           // pour le menu déroulant des profils
$hotspot->disconnect();

// Récupération des utilisateurs actifs (pour le point vert)
$activeUsernames = [];
$pdo = ara_db($config);   // connexion locale SQLite – on peut la garder pour les snapshots
$pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    snapshot_date TEXT NOT NULL,
    snapshot_time TEXT NOT NULL,
    active_count INTEGER NOT NULL,
    users_blob TEXT,
    received_at TEXT NOT NULL
)");
$snapshotStmt = $pdo->query("SELECT users_blob FROM hotspot_snapshots ORDER BY id DESC LIMIT 1");
$snapshot = $snapshotStmt->fetch(PDO::FETCH_ASSOC);
if ($snapshot && !empty($snapshot['users_blob'])) {
    foreach (explode('||', $snapshot['users_blob']) as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        $parts = explode(',', $entry, 3);
        if (count($parts) >= 1) {
            $activeUsernames[] = $parts[0];
        }
    }
}
?>

<div class="card card-custom">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h3><i class="bi bi-people"></i> Utilisateurs (<?= count($users) ?>)</h3>
    </div>
    <div class="card-body p-0">
        <!-- Barre de filtres -->
        <form method="get" class="p-2 bg-light border-bottom">
            <input type="hidden" name="tab" value="users">
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

        <!-- Tableau des utilisateurs -->
        <div class="table-responsive">
            <table class="table table-bordered table-hover text-nowrap mb-0">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Profil</th>
                        <th>MAC</th>
                        <th>Statut</th>
                        <th>Expiration</th>
                        <th class="text-end">Uptime</th>
                        <th class="text-end">Bytes In</th>
                        <th class="text-end">Bytes Out</th>
                        <th>Commentaire</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="9" class="text-center text-muted">Aucun utilisateur trouvé.</td></tr>
                <?php else:
                    foreach ($users as $u):
                        $uid = $u['.id'] ?? '';
                        $uname = $u['name'] ?? '';
                        $uprofile = $u['profile'] ?? '';
                        $umac = $u['mac-address'] ?? '';
                        $uuptime = isset($u['uptime']) ? formatDTM($u['uptime']) : '';
                        $ubytesi = isset($u['bytes-in']) ? formatBytes($u['bytes-in'], 2) : '';
                        $ubyteso = isset($u['bytes-out']) ? formatBytes($u['bytes-out'], 2) : '';
                        $ucomment = $u['comment'] ?? '';
                        $isOnline = in_array($uname, $activeUsernames);
                        // Expiration (peut être récupérée depuis la locale ou Supabase – ici on peut afficher le commentaire s'il est une date)
                        $expiry = '';
                        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ucomment)) {
                            $expiry = $ucomment;
                        }
                ?>
                        <tr>
                            <td><?= htmlspecialchars($uname) ?></td>
                            <td><?= htmlspecialchars($uprofile) ?></td>
                            <td><?= htmlspecialchars($umac) ?></td>
                            <td>
                                <?php if ($isOnline): ?>
                                    <span class="online-dot"></span> En ligne
                                <?php else: ?>
                                    <span class="offline-dot"></span> Hors ligne
                                <?php endif; ?>
                            </td>
                            <td><?= $expiry ? htmlspecialchars($expiry) : '-' ?></td>
                            <td class="text-end"><?= $uuptime ?></td>
                            <td class="text-end"><?= $ubytesi ?></td>
                            <td class="text-end"><?= $ubyteso ?></td>
                            <td><?= htmlspecialchars($ucomment) ?></td>
                        </tr>
                    <?php endforeach;
                endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
