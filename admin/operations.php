<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/components/embedded-page.php';
$pageTitle = 'Opérations — ARA Tech WiFi';
require __DIR__ . '/header.php';
$requestedTab = $_GET['tab'] ?? null;
$tab = in_array($requestedTab, ['inventory'], true) ? 'inventory' : 'hotspot';
?>
<div class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h2 class="mb-1">Opérations</h2><p class="text-muted mb-0">Centre unique pour les opérations réseau et le stock.</p></div>
    </div>
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link <?= $tab === 'hotspot' ? 'active' : '' ?>" href="operations.php?tab=hotspot">Hotspot</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab === 'inventory' ? 'active' : '' ?>" href="operations.php?tab=inventory">Stocks &amp; import</a></li>
    </ul>
    <?php if ($tab === 'hotspot'): ?>
        <?php ara_render_embedded_page(__DIR__ . '/partials/operations/hotspot.php'); ?>
    <?php else: ?>
        <?php ara_render_embedded_page(__DIR__ . '/partials/operations/inventory.php'); ?>
    <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const activeTab = <?= json_encode($tab) ?>;
    document.querySelectorAll('form').forEach(function (form) {
        if (!form.querySelector('input[name="tab"]')) {
            const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'tab'; hidden.value = activeTab; form.appendChild(hidden);
        }
    });
});
</script>
<?php require __DIR__ . '/footer.php'; ?>
