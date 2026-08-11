<?php
declare(strict_types=1);
// Inclus par admin/hotspot.php
require_once __DIR__ . '/../db.php';

$profiles = [];
$error = '';

try {
    $pdoSupa = ara_db_supabase();
    $stmt = $pdoSupa->query('SELECT profile_name, shared_users, rate_limit, on_login, address_pool, last_sync FROM hotspot_profiles ORDER BY profile_name ASC');
    $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = 'Erreur lors du chargement des profils : ' . $e->getMessage();
}
?>

<div class="card card-custom">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h3 class="mb-0"><i class="bi bi-pie-chart-fill"></i> Profils Hotspot (<?= count($profiles) ?>)</h3>
    </div>
    <div class="card-body p-0">
        <?php if ($error): ?>
            <div class="alert alert-danger m-3"><?= htmlspecialchars($error) ?></div>
        <?php elseif (empty($profiles)): ?>
            <div class="alert alert-info m-3">Aucun profil trouvé en base de données. Effectuez une synchronisation depuis le routeur MikroTik.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Nom du Profil</th>
                            <th class="text-center">Utilisateurs Simultanés</th>
                            <th>Limite de Débit (Rate Limit)</th>
                            <th>Pool d'Adresses</th>
                            <th>Dernière Synchronisation</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($profiles as $p): ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($p['profile_name']) ?></td>
                            <td class="text-center"><span class="badge bg-info text-dark"><?= (int)$p['shared_users'] ?></span></td>
                            <td><code><?= htmlspecialchars($p['rate_limit'] ?: 'Illimité') ?></code></td>
                            <td><?= htmlspecialchars($p['address_pool'] ?: 'Par défaut') ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($p['last_sync'] ?: 'N/D') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>