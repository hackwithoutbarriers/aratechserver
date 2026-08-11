<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
$config = require __DIR__ . '/../config.php';

$pageTitle = 'Gestion des Annonces - ARA Tech WiFi';

$adminToken = $config['admin']['token'] ?? getenv('ADMIN_TOKEN');
$apiBase = 'https://' . $_SERVER['HTTP_HOST'] . '/api.php';

// Récupération de la liste des annonces
$ads = [];
$error = '';
try {
    $response = file_get_contents($apiBase . '?route=admin&token=' . urlencode($adminToken));
    $data = json_decode($response, true);
    if ($data && ($data['success'] ?? false)) {
        $ads = $data['ads'] ?? [];
    } else {
        $error = $data['message'] ?? 'Erreur lors du chargement des annonces.';
    }
} catch (Throwable $e) {
    $error = 'Impossible de contacter l\'API : ' . $e->getMessage();
}

require __DIR__ . '/header.php';
?>

<div class="container-fluid mt-4">
    <h2 class="mb-3">📢 Gestion des annonces</h2>

    <div class="mb-3 d-flex justify-content-between">
        <button class="btn btn-orange" data-bs-toggle="modal" data-bs-target="#adModal" onclick="clearForm()">
            <i class="bi bi-plus-circle"></i> Nouvelle annonce
        </button>
        <div>
            <button class="btn btn-outline-secondary" onclick="reseedAds()" title="Recharger depuis ads.json">
                <i class="bi bi-arrow-repeat"></i> Réinitialiser
            </button>
            <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif (empty($ads)): ?>
        <div class="alert alert-info">Aucune annonce trouvée.</div>
    <?php else: ?>
        <div class="card card-custom">
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Titre</th>
                            <th>Type</th>
                            <th>Actif</th>
                            <th>Prix</th>
                            <th>Vues</th>
                            <th>Clics</th>
                            <th>Créé le</th>
                            <th style="width: 120px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ads as $ad): ?>
                        <tr>
                            <td><?= htmlspecialchars($ad['title']) ?></td>
                            <td><?= htmlspecialchars($ad['type']) ?></td>
                            <td><?= $ad['active'] ? '✅' : '❌' ?></td>
                            <td><?= $ad['price'] ? $ad['price'].' FCFA' : '-' ?></td>
                            <td><?= $ad['views'] ?></td>
                            <td><?= $ad['clicks'] ?></td>
                            <td><?= htmlspecialchars($ad['created_at'] ?? '') ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary me-1" onclick='editAd(<?= json_encode($ad) ?>)' title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteAd('<?= $ad['id'] ?>')" title="Supprimer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal pour ajout/édition -->
<div class="modal fade" id="adModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="adForm">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalTitle">Nouvelle annonce</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="adId" name="id">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Titre</label>
                            <input type="text" class="form-control" id="title" name="title" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Type</label>
                            <select class="form-select" id="type" name="type">
                                <option value="sponsored">Sponsorisée</option>
                                <option value="info">Information</option>
                                <option value="offer">Offre</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Image (URL)</label>
                            <input type="url" class="form-control" id="image" name="image">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Lien (URL)</label>
                            <input type="url" class="form-control" id="url" name="url">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Date début</label>
                            <input type="date" class="form-control" id="start" name="start">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Date fin</label>
                            <input type="date" class="form-control" id="end" name="end">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Prix (optionnel)</label>
                            <input type="number" class="form-control" id="price" name="price">
                        </div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="active" name="active" value="1" checked>
                        <label class="form-check-label">Actif</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-orange">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const apiBase = '<?= $apiBase ?>';
const token = '<?= htmlspecialchars($adminToken) ?>';

function clearForm() {
    document.getElementById('adId').value = '';
    document.getElementById('modalTitle').textContent = 'Nouvelle annonce';
    document.getElementById('adForm').reset();
    document.getElementById('active').checked = true;
}

function editAd(ad) {
    document.getElementById('adId').value = ad.id;
    document.getElementById('modalTitle').textContent = 'Modifier l\'annonce';
    document.getElementById('title').value = ad.title || '';
    document.getElementById('type').value = ad.type || 'sponsored';
    document.getElementById('description').value = ad.description || '';
    document.getElementById('image').value = ad.image || '';
    document.getElementById('url').value = ad.url || '';
    document.getElementById('start').value = ad.start || '';
    document.getElementById('end').value = ad.end || '';
    document.getElementById('price').value = ad.price || '';
    document.getElementById('active').checked = ad.active == 1;
    new bootstrap.Modal(document.getElementById('adModal')).show();
}

document.getElementById('adForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const payload = Object.fromEntries(formData.entries());
    payload.active = document.getElementById('active').checked ? 1 : 0;

    fetch(apiBase + '?route=admin_save_ad&token=' + token, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('adModal')).hide();
            location.reload();
        } else {
            alert('Erreur : ' + (data.message || 'inconnue'));
        }
    })
    .catch(err => alert('Erreur réseau'));
});

function deleteAd(id) {
    if (!confirm('Confirmer la suppression de cette annonce ?')) return;
    fetch(apiBase + '?route=admin_delete_ad&token=' + token, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) location.reload();
        else alert('Erreur : ' + (data.message || 'inconnue'));
    })
    .catch(err => alert('Erreur réseau'));
}

function reseedAds() {
    if (!confirm('Cela va vider toutes les annonces et les recharger depuis ads.json. Continuer ?')) return;
    fetch(apiBase + '?route=admin_reseed_ads&token=' + token, { method: 'POST' })
    .then(r => r.json())
    .then(data => {
        if (data.success) location.reload();
        else alert('Erreur : ' + (data.message || 'inconnue'));
    })
    .catch(err => alert('Erreur réseau'));
}
</script>

</body>
</html>
