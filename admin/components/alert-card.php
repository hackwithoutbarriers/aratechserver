<?php
declare(strict_types=1);

$alertType = (string)($alertType ?? 'info');
$alertIcon = (string)($alertIcon ?? 'bi bi-info-circle');
$alertTitle = (string)($alertTitle ?? 'Information');
$alertMessage = (string)($alertMessage ?? '');

$allowedTypes = ['success', 'warning', 'danger', 'info', 'secondary'];
if (!in_array($alertType, $allowedTypes, true)) {
    $alertType = 'info';
}
?>
<div class="alert alert-<?= htmlspecialchars($alertType, ENT_QUOTES, 'UTF-8') ?> d-flex align-items-start gap-2 mb-3" role="alert">
    <i class="<?= htmlspecialchars($alertIcon, ENT_QUOTES, 'UTF-8') ?> fs-5"></i>
    <div>
        <strong><?= htmlspecialchars($alertTitle, ENT_QUOTES, 'UTF-8') ?></strong>
        <?php if ($alertMessage !== ''): ?>
            <div class="small mt-1"><?= htmlspecialchars($alertMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    </div>
</div>
