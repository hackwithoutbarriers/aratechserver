<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
$config = require __DIR__ . '/../config.php';

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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs - ARA Tech WiFi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --bleu-nuit: #0b2c82;
            --orange: #f5a623;
        }
        body {
            background: #f4f6f9;
            font-family: 'Segoe UI', sans-serif;
        }
        .navbar-custom {
            background: var(--bleu-nuit);
        }
        .navbar-brand {
            font-weight: 700;
            font-size: 1.3rem;
            color: #fff !important;
        }
        .card-custom {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
        }
        .card-header-custom {
            background: var(--bleu-nuit);
            color: #fff;
            border-radius: 12px 12px 0 0 !important;
            font-weight: 600;
        }
        .table-logs {
            font-size: 0.9rem;
        }
        .table-logs th {
            background: #f8f9fa;
        }
        .btn-orange {
            background: var(--orange);
            border: none;
            color: #fff;
            font-weight: 600;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .btn-orange:hover {
            background: #e5941f;
            color: #fff;
        }
        .filter-section {
            background: #fff;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <!-- Barre de navigation -->
    <nav class="navbar navbar-custom navbar-dark px-3">
        <a href="index.php" class="navbar-brand">⚡ ARA Tech WiFi Admin</a>
        <div class="ms-auto">
            <a href="logout.php" class="btn btn-outline-light btn-sm">Déconnexion</a>
        </div>
    </nav>

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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
