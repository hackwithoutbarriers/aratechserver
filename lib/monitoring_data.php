<?php
declare(strict_types=1);

const ARA_MONITORING_ONLINE_THRESHOLD = 360;

function ara_monitoring_latest_snapshot(PDO $pdo): ?array
{
    $stmt = $pdo->query(
        'SELECT id, snapshot_date, snapshot_time, active_count, users_blob, users_json,
                received_at, router_identity, router_uptime, router_version,
                cpu_load, memory_total, memory_free, network_json
         FROM hotspot_snapshots
         ORDER BY id DESC
         LIMIT 1'
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ara_monitoring_router_state(?array $snapshot): array
{
    if (!$snapshot) {
        return ['status' => 'UNKNOWN', 'age_seconds' => null, 'last_at' => null];
    }

    $last = null;
    if (!empty($snapshot['received_at'])) {
        try {
            $last = new DateTimeImmutable((string)$snapshot['received_at']);
        } catch (Throwable $e) {
            $last = null;
        }
    }

    if ($last === null && !empty($snapshot['snapshot_date']) && !empty($snapshot['snapshot_time'])) {
        $parsed = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            (string)$snapshot['snapshot_date'] . ' ' . (string)$snapshot['snapshot_time'],
            new DateTimeZone('UTC')
        );
        if ($parsed !== false) $last = $parsed;
    }

    if ($last === null) {
        return ['status' => 'UNKNOWN', 'age_seconds' => null, 'last_at' => null];
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $age = max(0, $now->getTimestamp() - $last->getTimestamp());

    return [
        'status' => $age <= ARA_MONITORING_ONLINE_THRESHOLD ? 'ONLINE' : 'OFFLINE',
        'age_seconds' => $age,
        'last_at' => $last,
    ];
}

function ara_monitoring_snapshot_users(?array $snapshot): array
{
    if (!$snapshot) return [];

    if (!empty($snapshot['users_json'])) {
        $decoded = is_string($snapshot['users_json'])
            ? json_decode($snapshot['users_json'], true)
            : $snapshot['users_json'];
        if (is_array($decoded)) return array_values(array_filter($decoded, 'is_array'));
    }

    $users = [];
    $blob = trim((string)($snapshot['users_blob'] ?? ''));
    if ($blob === '') return $users;

    foreach (explode('||', $blob) as $chunk) {
        $parts = explode(',', trim($chunk), 3);
        if (count($parts) === 3) {
            $users[] = [
                'user' => $parts[0],
                'mac' => $parts[1],
                'ip' => $parts[2],
            ];
        }
    }
    return $users;
}

function ara_monitoring_logs(PDO $pdo, string $date, string $topic = '', int $limit = 200): array
{
    $limit = max(1, min($limit, 1000));
    if ($topic !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, log_date, log_time, topics, message, received_at
             FROM router_logs
             WHERE log_date = ? AND topics ILIKE ?
             ORDER BY log_time DESC, id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$date, '%' . $topic . '%']);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, log_date, log_time, topics, message, received_at
             FROM router_logs
             WHERE log_date = ?
             ORDER BY log_time DESC, id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$date]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ara_monitoring_log_count(PDO $pdo, string $date, string $topic = ''): int
{
    if ($topic !== '') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM router_logs WHERE log_date = ? AND topics ILIKE ?');
        $stmt->execute([$date, '%' . $topic . '%']);
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM router_logs WHERE log_date = ?');
        $stmt->execute([$date]);
    }
    return (int)$stmt->fetchColumn();
}

function ara_monitoring_format_bytes(?int $bytes): string
{
    if ($bytes === null) return 'N/D';
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    foreach ($units as $unit) {
        $value /= 1024;
        if ($value < 1024 || $unit === 'TB') {
            return number_format($value, 1, ',', ' ') . ' ' . $unit;
        }
    }
    return number_format($value, 1, ',', ' ') . ' TB';
}

function ara_monitoring_status_class(string $status): string
{
    return match ($status) {
        'ONLINE' => 'online',
        'OFFLINE' => 'offline',
        default => 'unknown',
    };
}
