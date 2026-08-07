<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
$config = require __DIR__ . '/config.php';

$snapshot = null;
$error = '';

try {
    $pdo = ara_db($config);

    // S'assurer que la table existe (au cas où)
    $pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_snapshots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        snapshot_date TEXT NOT NULL,
        snapshot_time TEXT NOT NULL,
        active_count INTEGER NOT NULL,
        users_blob TEXT,
        received_at TEXT NOT NULL
    )");

    // Dernier snapshot
    $stmt = $pdo->query("SELECT * FROM hotspot_snapshots ORDER BY id DESC LIMIT 1");
    $snapshot = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$activeCount = $snapshot ? (int)$snapshot['active_count'] : 0;
$usersBlob   = $snapshot ? ($snapshot['users_blob'] ?? '') : '';
$lastDate    = $snapshot ? ($snapshot['snapshot_date'] ?? '') : '';
$lastTime    = $snapshot ? ($snapshot['snapshot_time'] ?? '') : '';

// Parser la liste des utilisateurs (format : user,mac,ip||user2,mac2,ip2||...)
$users = [];
if ($usersBlob !== '') {
    foreach (explode('||', $usersBlob) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') continue;
        $parts = explode(',', $chunk, 3);
        if (count($parts) === 3) {
            $users[] = ['user' => $parts[0], 'mac' => $parts[1], 'ip' => $parts[2]];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotspot Status</title>
    <style>
        body { font-family: sans-serif; max-width: 800px; margin: 20px auto; padding: 10px; }
        .dot { display: inline-block; width: 20px; height: 20px; border-radius: 50%; margin-right: 5px; vertical-align: middle; }
        .online { background: #4CAF50; }
        .offline { background: #F44336; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        th { background: #f0f0f0; }
        .error { color: red; font-weight: bold; }
        .logout { float: right; }
    </style>
    <meta http-equiv="refresh" content="30">
</head>
<body>
    <a href="logout.php" class="logout">Logout</a>
    <h2>Hotspot Status</h2>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php elseif (!$snapshot): ?>
        <p><span class="dot offline"></span> No snapshot received yet.</p>
        <p>Waiting for the router to send its first status…</p>
    <?php else: ?>
        <p><span class="dot online"></span> Last snapshot: <strong><?= htmlspecialchars($lastDate . ' ' . $lastTime) ?></strong></p>
        <p>Active hotspot users: <strong><?= $activeCount ?></strong></p>
        <?php if (!empty($users)): ?>
            <table>
                <tr><th>#</th><th>User</th><th>MAC Address</th><th>IP Address</th></tr>
                <?php foreach ($users as $i => $u): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($u['user']) ?></td>
                    <td><?= htmlspecialchars($u['mac']) ?></td>
                    <td><?= htmlspecialchars($u['ip']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>No users connected at that moment.</p>
        <?php endif; ?>
    <?php endif; ?>
    <p><small>Last updated: <?= date('Y-m-d H:i:s') ?> (auto‑refreshes every 30s)</small></p>
</body>
</html>
