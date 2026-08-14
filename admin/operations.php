<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/components/embedded-page.php';
$pageTitle = 'Opérations — ARA Tech WiFi';
require __DIR__ . '/header.php';
$tab = $_GET['tab'] ?? 'hotspot';
$tabs = ['hotspot' => 'Hotspot', 'inventory' => 'Stocks & import'];
if (!isset($tabs[$tab])) { $tab = 'hotspot'; }
?>
<div class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h2 class="mb-1">Opérations</h2><p class="text-muted mb-0">Centre unique pour les opérations réseau et le stock.</p></div>
    </div>
    <ul class="nav nav-tabs mb-4">
        <?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="operations.php?tab=<?= urlencode($key) ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($tab === 'hotspot'): ?>
        <?php ara_render_embedded_page(__DIR__ . '/partials/operations/hotspot.php'); ?>
    <?php else: ?>
        <?php ara_render_embedded_page(__DIR__ . '/partials/operations/inventory.php'); ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
