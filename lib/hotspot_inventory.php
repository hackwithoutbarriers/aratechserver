<?php
declare(strict_types=1);

/**
 * Synchronise the application stock with actual voucher/user logins.
 *
 * sales_log is deliberately used only as a consumption signal here:
 * a row means the username logged in. It is NOT used to calculate revenue.
 * Only rows recorded after the item was imported into the inventory can
 * consume that inventory item, so pre-existing login history is ignored.
 */
function hotspot_inventory_consume_logged_in(PDO $pdo): int
{
    $sql = <<<'SQL'
        WITH consumed AS (
            SELECT DISTINCT ON (i.username)
                i.username,
                s.received_at
            FROM hotspot_inventory i
            INNER JOIN sales_log s
                ON lower(s.username) = lower(i.username)
               AND s.received_at >= i.imported_at
            WHERE i.status = 'AVAILABLE'
            ORDER BY i.username, s.received_at ASC, s.id ASC
        )
        UPDATE hotspot_inventory i
        SET status = 'USED',
            consumed_at = c.received_at,
            consumed_reason = 'LOGIN_DETECTED'
        FROM consumed c
        WHERE i.username = c.username
          AND i.status = 'AVAILABLE'
        RETURNING i.username
    SQL;

    $stmt = $pdo->query($sql);
    return count($stmt->fetchAll(PDO::FETCH_COLUMN));
}

function hotspot_inventory_upsert(PDO $pdo, string $username, ?string $profile, array $metadata = []): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO hotspot_inventory (username, profile, source, imported_at, status, metadata)
         VALUES (?, ?, 'MIKHMON_CSV', now(), 'AVAILABLE', ?::jsonb)
         ON CONFLICT (username) DO UPDATE SET
             profile = EXCLUDED.profile,
             source = EXCLUDED.source,
             imported_at = CASE
                 WHEN hotspot_inventory.status = 'USED' THEN hotspot_inventory.imported_at
                 ELSE EXCLUDED.imported_at
             END,
             status = CASE
                 WHEN hotspot_inventory.status = 'USED' THEN 'USED'
                 ELSE 'AVAILABLE'
             END,
             metadata = hotspot_inventory.metadata || EXCLUDED.metadata"
    );
    $stmt->execute([
        $username,
        $profile,
        json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function hotspot_inventory_counts(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT
            COUNT(*) FILTER (WHERE status = 'AVAILABLE') AS available,
            COUNT(*) FILTER (WHERE status = 'USED') AS used,
            COUNT(*) AS total
         FROM hotspot_inventory"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'available' => (int)($row['available'] ?? 0),
        'used' => (int)($row['used'] ?? 0),
        'total' => (int)($row['total'] ?? 0),
    ];
}

function hotspot_inventory_list(PDO $pdo, string $status = 'AVAILABLE', int $limit = 500): array
{
    $status = in_array($status, ['AVAILABLE', 'USED'], true) ? $status : 'AVAILABLE';
    $limit = max(1, min(1000, $limit));
    $stmt = $pdo->prepare(
        'SELECT username, profile, source, imported_at, status, consumed_at, consumed_reason
         FROM hotspot_inventory
         WHERE status = ?
         ORDER BY imported_at DESC, username ASC
         LIMIT ' . $limit
    );
    $stmt->execute([$status]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
