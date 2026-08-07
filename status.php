<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';          // kicks out non-logged-in users
$config = require __DIR__ . '/config.php';

// ---------------------------------------------------------------------
// Minimal Turso helpers (will be moved to a shared lib later)
// ---------------------------------------------------------------------
function turso_pipeline(array $config, array $stmts): array
{
    if (empty($config['turso']['url']) || empty($config['turso']['token'])) {
        throw new RuntimeException('Turso is not configured.');
    }

    $url   = rtrim($config['turso']['url'], '/') . '/v2/pipeline';
    $token = $config['turso']['token'];

    $requests = [];
    foreach ($stmts as $stmt) {
        $requests[] = [
            'type' => 'execute',
            'stmt' => [
                'sql'  => $stmt['sql'],
                'args' => array_map(
                    static fn($v) => ['type' => 'text', 'value' => (string)$v],
                    $stmt['args'] ?? []
                ),
            ],
        ];
    }
    $requests[] = ['type' => 'close'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['requests' => $requests]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("Turso unreachable (cURL: $err).");
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid Turso response (HTTP $code).");
    }

    foreach ($decoded['results'] ?? [] as $result) {
        if (($result['type'] ?? '') === 'error') {
            throw new RuntimeException('Turso SQL error: ' . ($result['error']['message'] ?? 'unknown'));
        }
    }

    return $decoded['results'] ?? [];
}

function turso_rows(array $result): array
{
    $response = $result['response']['result'] ?? [];
    $cols = array_column($response['cols'] ?? [], 'name');
    $rows = [];

    foreach ($response['rows'] ?? [] as $row) {
        $assoc = [];
        foreach ($row as $i => $cell) {
            $assoc[$cols[$i]] = $cell['value'] ?? null;
        }
        $rows[] = $assoc;
    }

    return $rows;
}
// ---------------------------------------------------------------------

$activeCount = 0;
$users       = [];       // parsed list of [user, mac, ip]
$lastTime    = '';
$error       = '';

try {
    // Get today's date for querying the snapshot (UTC)
    $today = date('Y-m-d');

    // Fetch the latest snapshot pushed by the router
    $results = turso_pipeline($config, [
        [
            'sql'  => 'SELECT active_count, users_blob, snapshot_time
                       FROM hotspot_snapshots
                       WHERE snapshot_date = ?
                       ORDER BY snapshot_time DESC
                       LIMIT 1',
            'args' => [$today],
        ]
    ]);

    // Find the SELECT result (the one that has column definitions)
    $snapshotRow = null;
    foreach ($results as $r) {
        if (isset($r['response']['result']['cols'])) {
            $rows = turso_rows($r);
            if (!empty($rows)) {
                $snapshotRow = $rows[0];
            }
            break;
        }
    }

    if ($snapshotRow) {
        $activeCount = (int)$snapshotRow['active_count'];
        $usersBlob   = $snapshotRow['users_blob'] ?? '';
        $lastTime    = $snapshotRow['snapshot_time'] ?? '';

        // Parse the blob: "user,mac,ip||user2,mac2,ip2||..."
        if ($usersBlob !== '') {
            foreach (explode('||', $usersBlob) as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '') continue;
                $parts = explode(',', $chunk, 3);
                if (count($parts) === 3) {
                    $users[] = [
                        'user' => $parts[0],
                        'mac'  => $parts[1],
                        'ip'   => $parts[2],
                    ];
                }
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
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
        <p><span class="dot offline"></span> Error fetching status</p>
        <p class="error"><?= htmlspecialchars($error) ?></p>

    <?php elseif ($lastTime === ''): ?>
        <p><span class="dot offline"></span> No snapshot received yet.</p>
        <p>Waiting for the router to send its first status…</p>

    <?php else: ?>
        <p><span class="dot online"></span> Last snapshot: <strong><?= htmlspecialchars($lastTime) ?></strong></p>
        <p>Active hotspot users: <strong><?= $activeCount ?></strong></p>

        <?php if (!empty($users)): ?>
            <table>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>MAC Address</th>
                    <th>IP Address</th>
                </tr>
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
