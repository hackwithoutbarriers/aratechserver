<?php
declare(strict_types=1);

$metricLabel = (string)($metricLabel ?? '');
$metricValue = (string)($metricValue ?? '—');
$metricIcon = (string)($metricIcon ?? 'bi-bar-chart');
$metricTone = (string)($metricTone ?? 'primary');
$metricHelp = isset($metricHelp) ? (string)$metricHelp : '';
?>
<div class="card card-custom h-100 metric-card metric-card-<?= htmlspecialchars($metricTone, ENT_QUOTES, 'UTF-8') ?>">
    <div class="card-body d-flex align-items-start justify-content-between gap-3">
        <div class="min-w-0">
            <div class="stat-label"><?= htmlspecialchars($metricLabel, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="stat-value mt-1"><?= htmlspecialchars($metricValue, ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($metricHelp !== ''): ?>
                <div class="small text-muted mt-1"><?= htmlspecialchars($metricHelp, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
        <div class="metric-icon" aria-hidden="true"><i class="bi <?= htmlspecialchars($metricIcon, ENT_QUOTES, 'UTF-8') ?>"></i></div>
    </div>
</div>
