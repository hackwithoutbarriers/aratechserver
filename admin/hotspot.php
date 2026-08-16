<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
$config = require __DIR__ . '/../config.php';
$pageTitle = 'Hotspot — ARA Tech WiFi';
$embedded = ((string)($_GET['embed'] ?? '') === '1');
$activeTab = (string)($_GET['tab'] ?? 'users');

// Vouchers are generated/imported as Hotspot users in Mikhmon and are now
// managed by the dedicated Stock workspace. Keep the old URL compatible.
if ($activeTab === 'vouchers') {
    header('Location: operations.php?tab=inventory');
    exit;
}

if (!in_array($activeTab, ['users', 'active', 'profiles'], true)) {
    $activeTab = 'users';
}

if (!$embedded) require_once __DIR__ . '/header.php';
?>
<div class="container-fluid <?= $embedded ? 'px-0' : 'px-3 px-md-4 py-4' ?>">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h3 class="mb-1">Hotspot</h3><p class="text-muted mb-0">Administration des utilisateurs, sessions actives et profils techniques.</p></div>
    </div>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link <?= $activeTab === 'users' ? 'active' : '' ?>" href="operations.php?tab=hotspot&legacy_tab=users"><i class="bi bi-people"></i> Utilisateurs</a></li>
        <li class="nav-item"><a class="nav-link <?= $activeTab === 'active' ? 'active' : '' ?>" href="operations.php?tab=hotspot&legacy_tab=active"><i class="bi bi-wifi"></i> Sessions actives</a></li>
        <li class="nav-item"><a class="nav-link <?= $activeTab === 'profiles' ? 'active' : '' ?>" href="operations.php?tab=hotspot&legacy_tab=profiles"><i class="bi bi-pie-chart"></i> Profils</a></li>
    </ul>

    <div class="tab-content">
        <?php
        switch ($activeTab) {
            case 'active':
                require __DIR__ . '/active-users.php';
                break;
            case 'profiles':
                require __DIR__ . '/profiles.php';
                break;
            default:
                require __DIR__ . '/users.php';
        }
        ?>
    </div>

    <div class="alert alert-info mt-3 mb-0">
        <i class="bi bi-box-seam"></i>
        Les tickets/utilisateurs générés dans Mikhmon sont gérés dans <strong>Opérations → Stock &amp; import</strong>.
        Un ticket importé quitte automatiquement le stock dès que son login est détecté.
    </div>
</div>
<?php if (!$embedded): ?></body></html><?php endif; ?>