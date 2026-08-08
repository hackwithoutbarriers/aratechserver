<?php
declare(strict_types=1);
// auth.php déjà chargé par hotspot.php

require_once __DIR__ . '/../lib/RouterosAPI.php';
require_once __DIR__ . '/../lib/hotspot.php';
require_once __DIR__ . '/../lib/format.php';
require_once __DIR__ . '/../db.php';          // ara_db_supabase()
$config = require __DIR__ . '/../config.php';

// Connexion Supabase (pour lecture)
$supaAvailable = false;
try {
    $pdoSupa = ara_db_supabase();
    $supaAvailable = true;
} catch (Throwable $e) {
    $pdoSupa = null;
}

// Paramètres de filtre
$profileFilter = $_GET['profile'] ?? 'all';
$commentFilter = $_GET['comment'] ?? '';

// Tenter de lire depuis Supabase d'abord
$users = [];
$fromSupa = false;
if ($supaAvailable) {
    $sql = "SELECT * FROM hotspot_users WHERE 1=1";
    $params = [];
    if ($profileFilter !== 'all') {
        $sql .= " AND profile = ?";
        $params[] = $profileFilter;
    }
    if ($commentFilter !== '') {
        $sql .= " AND comment LIKE ?";
        $params[] = '%' . $commentFilter . '%';
    }
    $sql .= " ORDER BY username ASC";
    try {
        $stmt = $pdoSupa->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($users)) {
            $fromSupa = true;
        }
    } catch (Throwable $e) {
        // Silencieux, on passera au fallback routeur
    }
}

// Fallback : lecture directe depuis le routeur
if (!$fromSupa) {
    $hotspot = new Hotspot($config['mikrotik']);
    if ($hotspot->isConnected()) {
        $filters = [];
        if ($profileFilter !== 'all') {
            $filters['profile'] = $profileFilter;
        }
        if ($commentFilter !== '') {
            $filters['comment'] = $commentFilter;
        }
        $usersRaw = $hotspot->getUsers($filters);
        // Formater comme la table Supabase pour un traitement uniforme
        $users = array_map(function ($u) {
            return [
                'username'   => $u['name'] ?? '',
                'password'   => $u['password'] ?? '',
                'profile'    => $u['profile'] ?? '',
                'mac_address'=> $u['mac-address'] ?? '',
                'comment'    => $u['comment'] ?? '',
                'disabled'   => ($u['disabled'] ?? 'false') === 'true',
                'bytes_in'   => (int)($u['bytes-in'] ?? 0),
                'bytes_out'  => (int)($u['bytes-out'] ?? 0),
                'uptime'     => $u['uptime'] ?? '',
                'server'     => $u['server'] ?? '',
            ];
        }, $usersRaw);
        $hotspot->disconnect();
    }
}

// Liste des profils pour le filtre (on peut aussi les récupérer de Supabase plus tard)
$profiles = [];
if ($supaAvailable) {
    try {
        $stmt = $pdoSupa->query("SELECT profile_name FROM hotspot_profiles ORDER BY profile_name ASC");
        $profiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {}
}
if (empty($profiles)) {
    // Fallback routeur
    $hotspot2 = new Hotspot($config['mikrotik']);
    if ($hotspot2->isConnected()) {
        $rawProfiles = $hotspot2->getProfiles();
        foreach ($rawProfiles as $p) {
            $profiles[] = $p['name'] ?? '';
        }
        $hotspot2->disconnect();
    }
}

// Utilisateurs actifs (snapshot local SQLite)
$activeUsernames = [];
$pdoLocal = ara_db($config);
$pdoLocal->exec("CREATE TABLE IF NOT EXISTS hotspot_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    snapshot_date TEXT NOT NULL,
    snapshot_time TEXT NOT NULL,
    active_count INTEGER NOT NULL,
    users_blob TEXT,
    received_at TEXT NOT NULL
)");
$snapshotStmt = $pdoLocal->query("SELECT users_blob FROM hotspot_snapshots ORDER BY id DESC LIMIT 1");
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
        <h3><i class="bi bi-people"></i> Utilisateurs (<?= count($users) ?>) <?= $fromSupa ? '<small class="text-muted">via Supabase</small>' : '' ?></h3>
    </div>
    <div class="card-body p-0">
        <!-- Filtres -->
        <form method="get" class="p-2 bg-light border-bottom">
            <input type="hidden" name="tab" value="users">
            <div class="row g-2">
                <div class="col-md-3">
                    <select class="form-select form-select-sm" name="profile" onchange="this.form.submit()">
                        <option value="all">Tous les profils</option>
                        <?php foreach ($profiles as $pName):
                            $sel = ($pName === $profileFilter) ? 'selected' : '';
                            echo "<option value=\"$pName\" $sel>$pName</option>";
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

        <!-- Tableau -->
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
                        $uname = $u['username'] ?? '';
                        $uprofile = $u['profile'] ?? '';
                        $umac = $u['mac_address'] ?? '';
                        $uuptime = isset($u['uptime']) ? formatDTM($u['uptime']) : '';
                        $ubytesi = isset($u['bytes_in']) ? formatBytes((int)$u['bytes_in'], 2) : '';
                        $ubyteso = isset($u['bytes_out']) ? formatBytes((int)$u['bytes_out'], 2) : '';
                        $ucomment = $u['comment'] ?? '';
                        $isOnline = in_array($uname, $activeUsernames);
                        $expiry = (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ucomment)) ? $ucomment : '';
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
