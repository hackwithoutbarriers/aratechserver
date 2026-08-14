<?php
declare(strict_types=1);

$statusCode = strtoupper((string)($statusCode ?? 'UNKNOWN'));
$statusLabel = (string)($statusLabelOverride ?? '');

if ($statusLabel === '') {
    $statusLabel = match ($statusCode) {
        'ONLINE' => 'En ligne',
        'OFFLINE' => 'Hors ligne',
        'WARNING' => 'Attention',
        default => 'Inconnu',
    };
}

$statusClass = match ($statusCode) {
    'ONLINE' => 'online',
    'OFFLINE' => 'offline',
    'WARNING' => 'warning',
    default => 'unknown',
};
?>
<span class="status-pill <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>">
    <span class="status-dot <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"></span>
    <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
</span>
