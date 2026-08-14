<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
$pageTitle = 'Opérations — ARA Tech WiFi';
require __DIR__ . '/header.php';
?>
<div class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div><h2 class="mb-1">Opérations</h2><p class="text-muted mb-0">Centre unique pour le Hotspot, les utilisateurs et les stocks.</p></div>
    </div>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card card-custom h-100"><div class="card-header card-header-custom">Gestion du Hotspot</div><div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6"><a class="text-decoration-none" href="hotspot.php?tab=users"><div class="card border h-100"><div class="card-body"><i class="bi bi-people fs-3 text-warning"></i><h5 class="mt-2">Utilisateurs</h5><p class="text-muted mb-0">Créer, modifier, activer et désactiver les comptes Hotspot.</p></div></div></a></div>
                    <div class="col-md-6"><a class="text-decoration-none" href="hotspot.php?tab=active"><div class="card border h-100"><div class="card-body"><i class="bi bi-wifi fs-3 text-warning"></i><h5 class="mt-2">Sessions actives</h5><p class="text-muted mb-0">Surveiller les connexions en cours et leur trafic.</p></div></div></a></div>
                    <div class="col-md-6"><a class="text-decoration-none" href="hotspot.php?tab=vouchers"><div class="card border h-100"><div class="card-body"><i class="bi bi-ticket-perforated fs-3 text-warning"></i><h5 class="mt-2">Vouchers</h5><p class="text-muted mb-0">Consulter, filtrer et imprimer les vouchers synchronisés.</p></div></div></a></div>
                    <div class="col-md-6"><a class="text-decoration-none" href="hotspot.php?tab=profiles"><div class="card border h-100"><div class="card-body"><i class="bi bi-pie-chart fs-3 text-warning"></i><h5 class="mt-2">Profils</h5><p class="text-muted mb-0">Consulter les profils Hotspot et leurs paramètres.</p></div></div></a></div>
                </div>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card card-custom h-100"><div class="card-header card-header-custom">Stocks & import</div><div class="card-body"><i class="bi bi-box-seam fs-2 text-warning"></i><h5 class="mt-2">Inventaire WiFi</h5><p class="text-muted">Le stock de codes et l'import CSV sont regroupés dans un seul espace opérationnel.</p><a class="btn btn-orange" href="inventory.php">Ouvrir l'inventaire</a></div></div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
