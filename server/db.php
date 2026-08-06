<?php
/**
 * db.php — Accès à la base SQLite locale des transactions.
 * Aucune installation de serveur de base de données requise : le fichier
 * .sqlite est créé automatiquement au premier appel dans data/.
 */

declare(strict_types=1);

function ara_db(array $config): PDO
{
    $dir = dirname($config['db_path']);
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }

    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL;'); // meilleure tolérance aux accès concurrents (pay.php + callback.php)

    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        identifier        TEXT UNIQUE NOT NULL,
        tx_reference      TEXT,
        phone             TEXT NOT NULL,
        network           TEXT NOT NULL,
        package_code      TEXT NOT NULL,
        amount            INTEGER NOT NULL,
        status            TEXT NOT NULL DEFAULT 'pending',
        hotspot_username  TEXT,
        hotspot_password  TEXT,
        sms_sent          INTEGER NOT NULL DEFAULT 0,
        created_at        TEXT NOT NULL,
        updated_at        TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ads (
        id          TEXT PRIMARY KEY,
        type        TEXT NOT NULL,
        title       TEXT NOT NULL,
        description TEXT,
        image       TEXT,
        url         TEXT,
        start       TEXT,
        end         TEXT,
        active      INTEGER NOT NULL DEFAULT 1,
        price       INTEGER,
        views       INTEGER NOT NULL DEFAULT 0,
        clicks      INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT NOT NULL,
        updated_at  TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS loyalty (
        user          TEXT PRIMARY KEY,
        points        INTEGER NOT NULL DEFAULT 0,
        topups        INTEGER NOT NULL DEFAULT 0,
        referral_code TEXT,
        created_at    TEXT NOT NULL,
        updated_at    TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS referrals (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        user          TEXT NOT NULL,
        referred_user TEXT NOT NULL,
        created_at    TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS track_events (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        item_id    TEXT NOT NULL,
        event_type TEXT NOT NULL,
        user       TEXT,
        created_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tx_reference ON transactions(tx_reference)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_phone ON transactions(phone)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ads_active ON ads(active)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_loyalty_user ON loyalty(user)");

    return $pdo;
}

/**
 * Génère un code alphanumérique lisible (sans caractères ambigus 0/O, 1/I/l).
 */
function ara_random_code(int $length = 6): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

/**
 * Journalise un message dans le fichier de log configuré.
 * Niveau de log : si debug activé, log plus détaillé.
 */
function ara_log(string $message, array $config, string $level = 'info'): void
{
    if ($config['debug'] === false && $level === 'debug') {
        return;
    }
    $dir = dirname($config['log_path']);
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    file_put_contents(
        $config['log_path'],
        '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Rotate et purge les logs + transactions anciennes (GDPR compliance).
 * Supprime :
 *   - Entrées de transaction > 90 jours
 *   - Fichier log si > 10 MB (archive et recrée)
 * À appeler périodiquement (en cron, ou après chaque webhook).
 */
function ara_maintenance(array $config, int $retention_days = 90): void
{
    // Purge des transactions complètes > 90 jours (GDPR)
    try {
        $pdo = ara_db($config);
        $cutoff = date('c', time() - ($retention_days * 86400));
        $pdo->prepare(
            "DELETE FROM transactions WHERE status = 'completed' AND updated_at < :cutoff"
        )->execute([':cutoff' => $cutoff]);
        $pdo->exec("PRAGMA optimize"); // Compact database
    } catch (Throwable $e) {
        error_log('[ARA Tech] Maintenance DB error: ' . $e->getMessage());
    }

    // Rotation du log si > 10 MB (configurable)
    $logSize = $config['maintenance']['log_rotation_size'] ?? 10485760;
    if (file_exists($config['log_path']) && filesize($config['log_path']) > $logSize) {
        $archivePath = $config['log_path'] . '.' . date('Y-m-d-His') . '.bak';
        rename($config['log_path'], $archivePath);
        // Optionnel : supprimer les archives de plus de 30 jours
        $glob = glob($config['log_path'] . '.*.bak');
        if ($glob) {
            $thirtyDaysAgo = time() - (30 * 86400);
            foreach ($glob as $file) {
                if (filemtime($file) < $thirtyDaysAgo) {
                    unlink($file);
                }
            }
        }
    }
}

/**
 * Récupère les métriques de base pour le reporting.
 * Retourne : revenus du jour, top packages, taux de succès, etc.
 */
function ara_metrics(array $config, string $period = 'today'): array
{
    try {
        $pdo = ara_db($config);
        
        // Déterminer la plage de dates
        $now = time();
        switch ($period) {
            case 'today':
                $startDate = date('c', strtotime('today midnight'));
                break;
            case 'week':
                $startDate = date('c', strtotime('last Monday midnight'));
                break;
            case 'month':
                $startDate = date('c', strtotime('first day of this month midnight'));
                break;
            case 'all':
                $startDate = '2020-01-01T00:00:00+00:00';
                break;
            default:
                $startDate = date('c', strtotime('today midnight'));
        }

        // Revenue total
        $stmt = $pdo->prepare(
            "SELECT SUM(amount) as total_revenue, COUNT(*) as total_transactions 
             FROM transactions 
             WHERE status = 'completed' AND created_at >= :start"
        );
        $stmt->execute([':start' => $startDate]);
        $revenue = $stmt->fetch(PDO::FETCH_ASSOC);

        // Revenue par package
        $stmt = $pdo->prepare(
            "SELECT package_code, COUNT(*) as count, SUM(amount) as revenue 
             FROM transactions 
             WHERE status = 'completed' AND created_at >= :start
             GROUP BY package_code 
             ORDER BY revenue DESC"
        );
        $stmt->execute([':start' => $startDate]);
        $byPackage = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Revenue par réseau (T-Money vs Flooz)
        $stmt = $pdo->prepare(
            "SELECT network, COUNT(*) as count, SUM(amount) as revenue 
             FROM transactions 
             WHERE status = 'completed' AND created_at >= :start
             GROUP BY network 
             ORDER BY revenue DESC"
        );
        $stmt->execute([':start' => $startDate]);
        $byNetwork = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Taux de succès / échecs
        $stmt = $pdo->prepare(
            "SELECT status, COUNT(*) as count 
             FROM transactions 
             WHERE created_at >= :start
             GROUP BY status"
        );
        $stmt->execute([':start' => $startDate]);
        $byStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // SMS succès
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) as sms_sent_count 
             FROM transactions 
             WHERE status = 'completed' AND sms_sent = 1 AND created_at >= :start"
        );
        $stmt->execute([':start' => $startDate]);
        $smsSent = $stmt->fetchColumn();

        return [
            'period'            => $period,
            'period_start'      => $startDate,
            'total_revenue'     => (int)($revenue['total_revenue'] ?? 0),
            'total_transactions' => (int)($revenue['total_transactions'] ?? 0),
            'average_transaction' => $revenue['total_transactions'] > 0 
                ? round((int)($revenue['total_revenue'] ?? 0) / (int)$revenue['total_transactions'], 2)
                : 0,
            'by_package'        => $byPackage,
            'by_network'        => $byNetwork,
            'by_status'         => $byStatus,
            'sms_sent_count'    => (int)$smsSent,
        ];
    } catch (Throwable $e) {
        error_log('[ARA Tech] Metrics error: ' . $e->getMessage());
        return [];
    }
}

// Optionnel : fonctions de chiffrement/déchiffrement
function ara_encrypt(string $data, string $key): string {
    if (strlen($key) !== 32) {
        throw new InvalidArgumentException('La clé de chiffrement doit faire 32 octets pour AES-256-CBC.');
    }
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

function ara_decrypt(string $encrypted, string $key): string {
    if (strlen($key) !== 32) {
        throw new InvalidArgumentException('La clé de chiffrement doit faire 32 octets pour AES-256-CBC.');
    }
    $decoded = base64_decode($encrypted);
    $iv = substr($decoded, 0, 16);
    $cipher = substr($decoded, 16);
    return openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}