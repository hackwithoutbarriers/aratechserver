<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../lib/api_client.php';
$config = require __DIR__ . '/../config.php';

$pageTitle = 'Logs - ARA Tech WiFi';

$date  = $_GET['date']  ?? date('Y-m-d');
$topic = $_GET['topic'] ?? '';

$query = ['date' => $date];
if ($topic !== '') {
    $query['topic'] = $topic;
}

$result = ara_api_call($config, 'get-logs', $query);
$logs  = $result['success'] ? ($result['data']['logs'] ?? []) : [];
$count = $result['success'] ? ($result['data']['count'] ?? 0) : 0;
$error = $result['success'] ? '' : $result['message'];

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
                            <tr><th>Heure</th><th>Topics</th><th>Message</th></tr>
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

    <a href="index.php" class="btn btn-outline-secondary mt-3"><i class="bi bi-arrow-left"></i> Retour au tableau de bord</a>
</div>

</body>
</html>
