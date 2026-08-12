<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
$config = require __DIR__ . '/../config.php';

// Titre de la page (utilisé par header.php)
$pageTitle = 'Logs - ARA Tech WiFi';

// Récupération du token admin
$adminToken = $config['admin']['token'] ?? getenv('ADMIN_TOKEN');

// Valeurs par défaut
$date  = $_GET['date']  ?? date('Y-m-d');
$topic = $_GET['topic'] ?? '';

// Construction de l'URL de l'API
$apiBase = 'https://' . $_SERVER['HTTP_HOST'] . '/api.php?route=get-logs';
$url = $apiBase . '&token=' . urlencode($adminToken) . '&date=' . urlencode($date);
if ($topic !== '') {
    $url .= '&topic=' . urlencode($topic);
}

// Récupération des logs
$logs = [];
$error = '';
$count = 0;
try {
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    if ($data && ($data['success'] ?? false)) {
        $logs = $data['logs'] ?? [];
        $count = $data['count'] ?? 0;
    } else {
        $error = $data['message'] ?? 'Erreur inconnue lors de la récupération des logs.';
    }
} catch (Throwable $e) {
    $error = 'Impossible de contacter l\'API : ' . $e->getMessage();
}

// Inclusion du header commun (ouvre <html>, <head>, <body>, navbar, bouton retour haut)
require __DIR__ . '/header.php';
?>

<div class="container-fluid mt-4">
    <h2 class="mb-3">📋 Consultation des logs système</h2>

    <!-- Section filtre -->
    <div class="filter-section">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="date" class="form-label">Date</label>
                <input type="date" class="form-control" id="date" name="date" value="<?= htmlspecialchars($date) ?>">
            </div>
            <div class="col-md-3">
                <label for="topic" class="form-label">Topic (mot-clé)</label>
                <input type="text" class="form-control" id="topic" name="topic" placeholder="ex: hotspot" value="<?= htmlspecialchars($topic) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-orange w-100">Filtrer</button>
            </div>
            <div class="col-md-4 text-end">
                <span class="text-muted"><?= $count ?> entrée(s) trouvée(s)</span>
            </div>
        </form>
    </div>

    <!-- Affichage des logs -->
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif (empty($logs)): ?>
        <div class="alert alert-info">Aucun log trouvé pour cette période / ce filtre.</div>
    <?php else: ?>
        <div class="card card-custom">
            <div class="card-header card-header-custom">
                Logs du <?= htmlspecialchars($date) ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-logs table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Heure</th>
                                <th>Topics</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?= htmlspecialchars($log['log_time'] ?? '') ?></td>
                                <td><?= htmlspecialchars($log['topics'] ?? '') ?></td>
                                <td><?= htmlspecialchars($log['message'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Bouton retour -->
    <a href="index.php" class="btn btn-outline-secondary mt-3"><i class="bi bi-arrow-left"></i> Retour au tableau de bord</a>
</div>

</body>
</html>
