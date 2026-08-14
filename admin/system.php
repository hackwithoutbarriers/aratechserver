<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
$pageTitle = 'System & Monitoring — ARA Tech WiFi';
require __DIR__ . '/header.php';
?>
<div class="container-fluid px-3 px-md-4 py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4"><div><h2 class="mb-1">System & Monitoring</h2><p class="text-muted mb-0">Point d’entrée unique pour la santé du réseau, les opérations et les alertes.</p></div><a href="index.php" class="btn btn-orange"><i class="bi bi-speedometer2"></i> Dashboard</a></div>
  <div class="row g-3">
    <div class="col-lg-7"><div class="card card-custom h-100"><div class="card-header card-header-custom"><i class="bi bi-broadcast"></i> Command center</div><div class="card-body"><div class="row g-3"><div class="col-6"><div class="stat-label">Monitoring</div><div class="fw-semibold">Snapshot MikroTik</div></div><div class="col-6"><div class="stat-label">Événements</div><div class="fw-semibold">Logs système</div></div></div><div class="mt-4 d-flex flex-wrap gap-2"><a href="monitoring.php?tab=status" class="btn btn-orange">Statut réseau</a><a href="monitoring.php?tab=logs" class="btn btn-outline-secondary">Journal système</a></div></div></div></div>
    <div class="col-lg-5"><div class="card card-custom h-100"><div class="card-header card-header-custom"><i class="bi bi-tools"></i> Système</div><div class="card-body"><p class="text-muted">Configuration et paramètres d’administration.</p><a href="settings.php" class="btn btn-outline-secondary">Configuration</a></div></div></div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
