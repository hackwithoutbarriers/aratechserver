<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';         // kicks out non-logged-in users
require __DIR__ . '/RouterosAPI.php';  // your existing API wrapper
$config = require __DIR__ . '/config.php';

$status = null;
$activeUsers = [];
$error = '';

try {
    $API = new RouterosAPI();
    // Set a short timeout (seconds) – the API class uses $this->timeout
    $API->timeout = 3;

    // Connect using the nested mikrotik credentials
    $connected = $API->connect(
        $config['mikrotik']['host'],
        $config['mikrotik']['api_user'],
        $config['mikrotik']['api_password'],
        (int)$config['mikrotik']['api_port']   // 4th param is port, not timeout
    );

    if (!$connected) {
        throw new Exception('Could not connect to MikroTik API.');
    }

    // Fetch system identity
    $identity = $API->comm('/system/identity/print');
    $routerName = $identity[0]['name'] ?? 'MikroTik';

    // Fetch active hotspot users
    $activeUsers = $API->comm('/ip/hotspot/active/print');
    $activeCount = count($activeUsers);

    $status = 'online';
    $API->disconnect();
} catch (Exception $e) {
    $error = $e->getMessage();
    $status = 'offline';
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

    <?php if ($status === 'online'): ?>
        <p><span class="dot online"></span> Router <strong><?= htmlspecialchars($routerName) ?></strong> is online</p>
        <p>Active hotspot users: <strong><?= $activeCount ?></strong></p>

        <?php if ($activeCount > 0): ?>
            <table>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>IP Address</th>
                    <th>MAC Address</th>
                    <th>Uptime</th>
                    <th>Bytes In</th>
                    <th>Bytes Out</th>
                </tr>
                <?php foreach ($activeUsers as $i => $user): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($user['user'] ?? '') ?></td>
                    <td><?= htmlspecialchars($user['address'] ?? '') ?></td>
                    <td><?= htmlspecialchars($user['mac-address'] ?? '') ?></td>
                    <td><?= htmlspecialchars($user['uptime'] ?? '') ?></td>
                    <td><?= htmlspecialchars($user['bytes-in'] ?? '0') ?></td>
                    <td><?= htmlspecialchars($user['bytes-out'] ?? '0') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>No users connected right now.</p>
        <?php endif; ?>

    <?php else: ?>
        <p><span class="dot offline"></span> Router offline or unreachable</p>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <p><small>Last updated: <?= date('Y-m-d H:i:s') ?> (auto‑refreshes every 30s)</small></p>
</body>
</html>
