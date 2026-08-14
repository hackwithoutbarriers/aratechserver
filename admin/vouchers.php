<?php
declare(strict_types=1);
// Garde anti-accès-direct — même logique que users.php.
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    require_once __DIR__ . '/auth.php';
}

// Page "Vouchers" — RÉÉCRITE lors de la restructuration.
// ----------------------------------------------------------------
// L'ancienne version instanciait `new Voucher($config['mikrotik'])` et
// incluait `lib/voucher.php` + `vouchers/template.php` : ces deux fichiers
// n'existent plus dans le dépôt (retirés lors du passage à l'architecture
// Supabase-mirror + hotspot_commands), ce qui provoquait une erreur fatale
// PHP à chaque ouverture de cet onglet.
//
// Cette version lit les vouchers via la route hotspot-vouchers (déjà
// implémentée côté API, filtrée sur les commentaires préfixés "vc-"/"up-",
// convention déjà utilisée par les scripts routeur). Le mot de passe n'est
// volontairement jamais renvoyé par l'API (voir hotspot_user_public_row) :
// l'impression de tickets avec mot de passe reste une opération côté
// routeur/Mikhmon pour l'instant, pas depuis ce tableau de bord distant.

require_once __DIR__ . '/../lib/api_client.php';
$config = require __DIR__ . '/../config.php';
$apiHeadersJson = ara_api_headers_json($config);
$hasAdminToken = ($config['admin']['token'] ?? '') !== '';

$profiles = [];
try {
    require_once __DIR__ . '/../db.php';
    $pdoSupa = ara_db_supabase();
    $stmt = $pdoSupa->query('SELECT profile_name FROM hotspot_profiles ORDER BY profile_name ASC');
    $profiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $profiles = [];
}
?>
<style>
    @media print {
        .no-print { display: none !important; }
        .card-custom { box-shadow: none !important; border: 1px solid #ccc !important; }
    }
</style>

<div class="card card-custom">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
        <h3 class="mb-0"><i class="bi bi-ticket-perforated"></i> Vouchers</h3>
        <button class="btn btn-outline-light btn-sm" id="btnPrint"><i class="bi bi-printer"></i> Imprimer la liste</button>
    </div>
    <div class="card-body">

        <div class="alert alert-warning no-print" id="apiErrorBanner" role="alert" style="display:none"></div>
        <div class="alert alert-info no-print">
            <i class="bi bi-info-circle"></i> La génération de nouveaux vouchers depuis cette interface arrive dans
            une prochaine phase (le contrat API existe, l'exécution routeur n'est pas encore branchée). Cet écran
            affiche l'état actuellement synchronisé depuis MikroTik.
        </div>

        <div class="row g-2 mb-3 no-print">
            <div class="col-md-4">
                <input type="text" class="form-control form-control-sm" id="fSearch" placeholder="🔍 Rechercher (utilisateur, commentaire)...">
            </div>
            <div class="col-6 col-md-2">
                <select class="form-select form-select-sm" id="fProfile">
                    <option value="all">Tous les profils</option>
                    <?php foreach ($profiles as $p): ?>
                        <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div id="vLoading" class="text-center text-muted py-4 no-print">
            <div class="spinner-border spinner-border-sm"></div> Chargement des vouchers…
        </div>

        <div class="table-responsive" id="vTableWrap" style="display:none">
            <table class="table table-bordered table-hover mb-0" id="vTable">
                <thead>
                    <tr><th>Utilisateur</th><th>Profil</th><th>Commentaire</th><th>Expiration</th><th>État</th></tr>
                </thead>
                <tbody id="vTableBody"></tbody>
            </table>
        </div>

        <div id="vEmpty" class="text-center text-muted py-4" style="display:none">Aucun voucher trouvé.</div>

        <nav class="mt-3 no-print" id="paginationWrap" style="display:none">
            <ul class="pagination pagination-sm justify-content-center" id="paginationList"></ul>
        </nav>
    </div>
</div>

<script>
(function () {
    'use strict';
    const API_HEADERS = <?= $apiHeadersJson ?>;
    const state = { search: '', profile: 'all', page: 1, limit: 25, searchDebounce: null };

    function escapeAttr(v) { return String(v == null ? '' : v); }

    function showBanner(message) {
        const el = document.getElementById('apiErrorBanner');
        el.textContent = message;
        el.style.display = 'block';
    }
    function hideBanner() { document.getElementById('apiErrorBanner').style.display = 'none'; }

    async function loadVouchers() {
        document.getElementById('vLoading').style.display = '';
        document.getElementById('vTableWrap').style.display = 'none';
        document.getElementById('vEmpty').style.display = 'none';
        hideBanner();

        const params = new URLSearchParams({ route: 'hotspot-vouchers', page: state.page, limit: state.limit });
        if (state.search) params.set('search', state.search);
        if (state.profile !== 'all') params.set('profile', state.profile);

        let res, json;
        try {
            res = await fetch('/api.php?' + params.toString(), { headers: API_HEADERS });
            json = await res.json();
        } catch (e) {
            document.getElementById('vLoading').style.display = 'none';
            showBanner('Impossible de joindre le serveur.');
            return;
        }
        if (!res.ok || json.success === false) {
            document.getElementById('vLoading').style.display = 'none';
            showBanner((json.error && json.error.message) || json.message || 'Erreur lors de la récupération des vouchers.');
            return;
        }

        const data = json.data;
        document.getElementById('vLoading').style.display = 'none';

        const tbody = document.getElementById('vTableBody');
        tbody.innerHTML = '';
        (data.items || []).forEach(v => {
            const tr = document.createElement('tr');
            const badge = v.disabled
                ? '<span class="badge bg-secondary">Désactivé</span>'
                : '<span class="badge bg-success">Actif</span>';
            tr.innerHTML = `
                <td>${escapeAttr(v.username)}</td>
                <td>${escapeAttr(v.profile)}</td>
                <td>${escapeAttr(v.comment)}</td>
                <td>${escapeAttr(v.expiry) || '—'}</td>
                <td>${badge}</td>
            `;
            tbody.appendChild(tr);
        });

        renderPagination(data.total || 0, data.page || 1, data.limit || state.limit);

        if ((data.items || []).length === 0) {
            document.getElementById('vEmpty').style.display = '';
        } else {
            document.getElementById('vTableWrap').style.display = '';
        }
    }

    function renderPagination(total, page, limit) {
        const pages = limit > 0 ? Math.ceil(total / limit) : 0;
        const wrap = document.getElementById('paginationWrap');
        const list = document.getElementById('paginationList');
        list.innerHTML = '';
        if (pages <= 1) { wrap.style.display = 'none'; return; }
        wrap.style.display = '';

        function item(label, targetPage, disabled, active) {
            const li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
            const a = document.createElement('a');
            a.className = 'page-link'; a.href = '#'; a.textContent = label;
            a.addEventListener('click', (ev) => { ev.preventDefault(); if (!disabled && !active) { state.page = targetPage; loadVouchers(); } });
            li.appendChild(a);
            return li;
        }

        list.appendChild(item('‹', Math.max(1, page - 1), page <= 1, false));
        const start = Math.max(1, page - 2);
        const end = Math.min(pages, page + 2);
        for (let i = start; i <= end; i++) list.appendChild(item(String(i), i, false, i === page));
        list.appendChild(item('›', Math.min(pages, page + 1), page >= pages, false));
    }

    document.getElementById('fSearch').addEventListener('input', (e) => {
        clearTimeout(state.searchDebounce);
        state.searchDebounce = setTimeout(() => { state.search = e.target.value.trim(); state.page = 1; loadVouchers(); }, 400);
    });
    document.getElementById('fProfile').addEventListener('change', (e) => { state.profile = e.target.value; state.page = 1; loadVouchers(); });
    document.getElementById('btnPrint').addEventListener('click', () => window.print());

    if (!<?= $hasAdminToken ? 'true' : 'false' ?>) {
        showBanner('Jeton administrateur introuvable côté serveur (ADMIN_TOKEN) : les appels API échoueront.');
    }
    loadVouchers();
})();
</script>
