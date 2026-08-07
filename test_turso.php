<?php
declare(strict_types=1);
$config = require __DIR__ . '/config.php';

function turso_pipeline(array $config, array $stmts): array {
    if (empty($config['turso']['url']) || empty($config['turso']['token'])) {
        throw new RuntimeException('Turso non configuré.');
    }
    $url   = rtrim($config['turso']['url'], '/') . '/v2/pipeline';
    $token = $config['turso']['token'];
    $requests = [];
    foreach ($stmts as $stmt) {
        $requests[] = [
            'type'  => 'execute',
            'stmt'  => [
                'sql'  => $stmt['sql'],
                'args' => array_map(fn($v) => ['type' => 'text', 'value' => (string)$v], $stmt['args'] ?? []),
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
    if ($raw === false) throw new RuntimeException("Turso cURL error: $err");
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) throw new RuntimeException("Turso réponse invalide (HTTP $code)");
    foreach ($decoded['results'] ?? [] as $r) {
        if (($r['type']??'') === 'error') throw new RuntimeException('Turso SQL: ' . ($r['error']['message']??'inconnue'));
    }
    return $decoded['results'] ?? [];
}

function turso_rows(array $result): array {
    $response = $result['response']['result'] ?? [];
    $cols = array_column($response['cols'] ?? [], 'name');
    $rows = [];
    foreach ($response['rows'] ?? [] as $row) {
        $assoc = [];
        foreach ($row as $i => $cell) $assoc[$cols[$i]] = $cell['value'] ?? null;
        $rows[] = $assoc;
    }
    return $rows;
}

$message = '';
$latest = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Écrire un snapshot ET le relire immédiatement dans le même pipeline
    try {
        $date = date('Y-m-d');
        $time = date('H:i:s');
        $active = 3;
        $users = 'test1,AA:BB:CC:DD:EE:FF,10.0.0.1||test2,11:22:33:44:55:66,10.0.0.2||';

        $results = turso_pipeline($config, [
            // 1. Créer la table si elle n'existe pas
            [
                'sql'  => 'CREATE TABLE IF NOT EXISTS hotspot_snapshots (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    snapshot_date TEXT NOT NULL,
                    snapshot_time TEXT NOT NULL,
                    active_count INTEGER NOT NULL,
                    users_blob TEXT,
                    received_at TEXT NOT NULL
                )',
                'args' => [],
            ],
            // 2. Insérer
            [
                'sql'  => 'INSERT INTO hotspot_snapshots (snapshot_date, snapshot_time, active_count, users_blob, received_at)
                         VALUES (?, ?, ?, ?, ?)',
                'args' => [$date, $time, $active, $users, date('c')],
            ],
            // 3. Relire le dernier (celui qu'on vient d'insérer)
            [
                'sql'  => 'SELECT * FROM hotspot_snapshots ORDER BY id DESC LIMIT 1',
                'args' => [],
            ],
        ]);

        // Récupérer le résultat du SELECT (le dernier avec 'cols')
        foreach ($results as $r) {
            if (isset($r['response']['result']['cols'])) {
                $rows = turso_rows($r);
                if (!empty($rows)) $latest = $rows[0];
            }
        }
        $message = $latest ? "Snapshot de test inséré et relu avec succès." : "Insertion OK mais le SELECT n'a rien retourné.";
    } catch (Throwable $e) {
        $message = 'ERREUR : ' . $e->getMessage();
    }
} else {
    // Affichage simple (GET) : juste relire le dernier snapshot
    try {
        $results = turso_pipeline($config, [
            [
                'sql'  => 'SELECT * FROM hotspot_snapshots ORDER BY id DESC LIMIT 1',
                'args' => [],
            ],
        ]);
        foreach ($results as $r) {
            if (isset($r['response']['result']['cols'])) {
                $rows = turso_rows($r);
                if (!empty($rows)) $latest = $rows[0];
            }
        }
    } catch (Throwable $e) {
        $message = 'ERREUR lecture : ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Test Turso</title></head>
<body>
    <h2>Test connexion Turso</h2>
    <?php if ($message): ?>
        <p style="color:blue"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <form method="post">
        <button type="submit">Insérer un snapshot de test</button>
    </form>

    <h3>Dernier snapshot dans Turso :</h3>
    <?php if ($latest): ?>
        <table border="1" cellpadding="5">
            <tr><th>Date</th><td><?= htmlspecialchars($latest['snapshot_date']) ?></td></tr>
            <tr><th>Heure</th><td><?= htmlspecialchars($latest['snapshot_time']) ?></td></tr>
            <tr><th>Utilisateurs actifs</th><td><?= htmlspecialchars($latest['active_count']) ?></td></tr>
            <tr><th>Liste (brute)</th><td><pre><?= htmlspecialchars($latest['users_blob'] ?? '') ?></pre></td></tr>
        </table>
    <?php else: ?>
        <p>Aucun snapshot trouvé.</p>
    <?php endif; ?>
</body>
</html>
