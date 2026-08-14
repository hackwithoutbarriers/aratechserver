<?php
declare(strict_types=1);
// Ce fichier est normalement inclus comme onglet par hotspot.php (qui charge
// auth.php en premier). PHP ne bloque pas l'accès direct par URL
// (/admin/users.php) : sans ce garde, la page — qui permet de créer,
// modifier, activer/désactiver et supprimer des utilisateurs hotspot —
// s'exécuterait sans aucune vérification de session. On ne relance pas
// session_start()/auth.php si la session est déjà validée (inclusion
// normale via hotspot.php), pour ne pas dupliquer l'initialisation.
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    require_once __DIR__ . '/auth.php';
}

// Page "Utilisateurs" — interface API-first.
// Toutes les données (liste, KPI, détail) et toutes les mutations
// (création, modification, activation, désactivation, suppression)
// passent par api.php?route=hotspot-user(s)... — voir docs/API_DOCUMENTATION.md.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/api_client.php';
$config = require __DIR__ . '/../config.php';

// Liste des profils pour les filtres/formulaires : lecture directe de
// hotspot_profiles (Supabase) uniquement. Pas de repli routeur ici
// (new Hotspot(...)) : un repli routeur échouerait de toute façon depuis
// l'hébergement Render (Render → 192.168.88.1 impossible en pratique).
$profiles = [];
try {
    $pdoSupa = ara_db_supabase();
    $stmt = $pdoSupa->query('SELECT profile_name FROM hotspot_profiles ORDER BY profile_name ASC');
    $profiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $profiles = [];
}

// En-têtes prêts à l'emploi côté JS : le token admin ne transite jamais
// par l'URL, ni côté serveur (voir lib/api_client.php) ni côté navigateur.
$apiHeadersJson = ara_api_headers_json($config);
$hasAdminToken = ($config['admin']['token'] ?? '') !== '';
?>
<style>
    .u-kpi { border-radius: 10px; background: #fff; padding: .9rem 1rem; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
    .u-kpi .val { font-size: 1.5rem; font-weight: 700; color: var(--bleu-nuit); }
    .u-kpi .lbl { font-size: .8rem; color: #6c757d; }
    .u-card { border: 1px solid #e9ecef; border-radius: 10px; padding: .75rem; margin-bottom: .6rem; background: #fff; }
    .u-card .u-name { font-weight: 700; }
    .u-card .u-meta { font-size: .82rem; color: #6c757d; }
    @media (max-width: 767px) { #usersTableWrap { display: none; } }
    @media (min-width: 768px) { #usersCardsWrap { display: none; } }
    #usersTableWrap { overflow-x: auto; }
    .badge-active { background: #28a745; }
    .badge-disabled { background: #dc3545; }
</style>

<div class="card card-custom">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h3 class="mb-0"><i class="bi bi-people"></i> Utilisateurs</h3>
        <button class="btn btn-orange btn-sm" id="btnNewUser"><i class="bi bi-plus-lg"></i> Nouvel utilisateur</button>
    </div>
    <div class="card-body">

        <div class="alert alert-warning" id="apiErrorBanner" role="alert" style="display:none"></div>

        <!-- KPI -->
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3"><div class="u-kpi"><div class="val" id="kpiTotal">–</div><div class="lbl">Total</div></div></div>
            <div class="col-6 col-md-3"><div class="u-kpi"><div class="val" id="kpiActive">–</div><div class="lbl">Actifs</div></div></div>
            <div class="col-6 col-md-3"><div class="u-kpi"><div class="val" id="kpiDisabled">–</div><div class="lbl">Désactivés</div></div></div>
            <div class="col-6 col-md-3"><div class="u-kpi"><div class="val" id="kpiExpiring">–</div><div class="lbl">Expirants (7j)</div></div></div>
        </div>

        <!-- Recherche + filtres -->
        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <input type="text" class="form-control form-control-sm" id="fSearch" placeholder="🔍 Rechercher (nom, MAC, commentaire)...">
            </div>
            <div class="col-6 col-md-2">
                <select class="form-select form-select-sm" id="fProfile">
                    <option value="all">Tous les profils</option>
                    <?php foreach ($profiles as $p): ?>
                        <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select class="form-select form-select-sm" id="fStatus">
                    <option value="all">Tous les statuts</option>
                    <option value="active">Actif</option>
                    <option value="disabled">Désactivé</option>
                    <option value="expired">Expiré</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select class="form-select form-select-sm" id="fConnection">
                    <option value="all">Connexion : tous</option>
                    <option value="online">En ligne</option>
                    <option value="offline">Hors ligne</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select class="form-select form-select-sm" id="fLimit">
                    <option value="25">25 / page</option>
                    <option value="50">50 / page</option>
                    <option value="100">100 / page</option>
                </select>
            </div>
        </div>

        <div id="usersLoading" class="text-center text-muted py-4">
            <div class="spinner-border spinner-border-sm"></div> Chargement des utilisateurs…
        </div>

        <!-- Vue table (desktop) -->
        <div id="usersTableWrap" class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0" style="display:none" id="usersTable">
                <thead>
                    <tr>
                        <th>Utilisateur</th><th>Profil</th><th>MAC</th><th>Statut</th>
                        <th>Connexion</th><th>Expiration</th><th class="text-end">Trafic</th><th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="usersTableBody"></tbody>
            </table>
        </div>

        <!-- Vue cartes (mobile) -->
        <div id="usersCardsWrap"></div>

        <div id="usersEmpty" class="text-center text-muted py-4" style="display:none">Aucun utilisateur trouvé.</div>

        <nav class="mt-3" id="paginationWrap" style="display:none">
            <ul class="pagination pagination-sm justify-content-center" id="paginationList"></ul>
        </nav>
    </div>
</div>

<!-- Modal détail -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-lines-fill"></i> Détail utilisateur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailBody">…</div>
        </div>
    </div>
</div>

<!-- Modal création/édition -->
<div class="modal fade" id="userFormModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="userForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="userFormTitle">Nouvel utilisateur</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger" id="userFormError" style="display:none"></div>
                    <div class="mb-2">
                        <label class="form-label">Nom d'utilisateur *</label>
                        <input type="text" class="form-control" id="fmUsername" required pattern="[A-Za-z0-9_.\-]+" maxlength="64">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Mot de passe *</label>
                        <input type="text" class="form-control" id="fmPassword">
                        <div class="form-text">Laisser vide en modification pour ne pas changer le mot de passe.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Profil *</label>
                        <select class="form-select" id="fmProfile" required>
                            <option value="">Choisir…</option>
                            <?php foreach ($profiles as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Commentaire</label>
                        <input type="text" class="form-control" id="fmComment" maxlength="255">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Expiration</label>
                        <input type="datetime-local" class="form-control" id="fmExpiry">
                        <div class="form-text">Optionnel — format AAAA-MM-JJ HH:MM:SS enregistré.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-orange" id="userFormSubmit">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toast messages -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:1080">
    <div id="uToast" class="toast align-items-center text-white border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="uToastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    // Le token admin ne quitte jamais le serveur autrement que dans un en-tête
    // HTTP (jamais dans une URL : pas de logs serveur, pas d'historique navigateur).
    const API_HEADERS = <?= $apiHeadersJson ?>;
    const API = '/api.php';

    const state = {
        search: '', profile: 'all', status: 'all', connection: 'all',
        page: 1, limit: 25,
        searchDebounce: null,
    };

    async function hotspotApi(route, { method = 'GET', params = {}, body = null } = {}) {
        const url = new URL(API, window.location.href);
        url.searchParams.set('route', route);
        if (method === 'GET') {
            Object.entries(params).forEach(([k, v]) => {
                if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
            });
        }
        const headers = Object.assign({}, API_HEADERS);
        const opts = { method, headers };
        if (method === 'POST') {
            headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body || {});
        }
        let res, json;
        try {
            res = await fetch(url.toString(), opts);
        } catch (e) {
            throw { networkError: true, message: 'Impossible de joindre le serveur.' };
        }
        try {
            json = await res.json();
        } catch (e) {
            throw { networkError: true, message: 'Réponse invalide du serveur.' };
        }
        if (!res.ok || json.success === false) {
            const err = (json && json.error) ? json.error : { code: 'UNKNOWN', message: json.message || 'Erreur inconnue.' };
            throw { apiError: true, status: res.status, code: err.code, message: err.message };
        }
        return json.data;
    }

    function showBanner(message) {
        const el = document.getElementById('apiErrorBanner');
        el.textContent = message;
        el.style.display = 'block';
    }
    function hideBanner() { document.getElementById('apiErrorBanner').style.display = 'none'; }
    function toast(message, ok = true) {
        const t = document.getElementById('uToast');
        t.classList.remove('bg-success', 'bg-danger');
        t.classList.add(ok ? 'bg-success' : 'bg-danger');
        document.getElementById('uToastBody').textContent = message;
        new bootstrap.Toast(t, { delay: 3500 }).show();
    }

    function escapeAttr(v) { return String(v == null ? '' : v); }
    function statusBadge(disabled) {
        return disabled
            ? '<span class="badge badge-disabled">🔴 Désactivé</span>'
            : '<span class="badge badge-active">🟢 Actif</span>';
    }
    function connBadge(connection) {
        if (connection === 'online') return '<span class="status-dot online"></span>En ligne';
        if (connection === 'offline') return '<span class="status-dot offline"></span>Hors ligne';
        return '<span class="status-dot unknown"></span>Inconnu';
    }
    function fmtBytes(n) {
        n = Number(n) || 0;
        if (n <= 0) return '0 B';
        const u = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(n) / Math.log(1024));
        return (n / Math.pow(1024, i)).toFixed(1) + ' ' + u[i];
    }
    function fmtExpiry(v) { return v || '-'; }

    async function loadUsers() {
        document.getElementById('usersLoading').style.display = '';
        document.getElementById('usersTable').style.display = 'none';
        document.getElementById('usersEmpty').style.display = 'none';
        hideBanner();

        let data;
        try {
            data = await hotspotApi('hotspot-users', {
                params: {
                    search: state.search, profile: state.profile, status: state.status,
                    connection: state.connection, page: state.page, limit: state.limit,
                },
            });
        } catch (e) {
            document.getElementById('usersLoading').style.display = 'none';
            showBanner(e.message || 'Impossible de charger les utilisateurs.');
            return;
        }

        document.getElementById('usersLoading').style.display = 'none';

        document.getElementById('kpiTotal').textContent = data.kpi.total;
        document.getElementById('kpiActive').textContent = data.kpi.active;
        document.getElementById('kpiDisabled').textContent = data.kpi.disabled;
        document.getElementById('kpiExpiring').textContent = data.kpi.expiring;

        renderTable(data.items);
        renderCards(data.items);
        renderPagination(data.pagination);

        if (data.items.length === 0) {
            document.getElementById('usersEmpty').style.display = '';
        } else {
            document.getElementById('usersTable').style.display = '';
        }
    }

    function actionsHtml(u) {
        const toggle = u.disabled
            ? `<button class="btn btn-sm btn-outline-success" data-act="enable" data-username="${escapeAttr(u.username)}" title="Activer"><i class="bi bi-unlock"></i></button>`
            : `<button class="btn btn-sm btn-outline-warning" data-act="disable" data-username="${escapeAttr(u.username)}" title="Désactiver"><i class="bi bi-lock"></i></button>`;
        return `
            <button class="btn btn-sm btn-outline-secondary" data-act="view" data-username="${escapeAttr(u.username)}" title="Voir"><i class="bi bi-eye"></i></button>
            <button class="btn btn-sm btn-outline-primary" data-act="edit" data-username="${escapeAttr(u.username)}" title="Modifier"><i class="bi bi-pencil"></i></button>
            ${toggle}
            <button class="btn btn-sm btn-outline-danger" data-act="delete" data-username="${escapeAttr(u.username)}" title="Supprimer"><i class="bi bi-trash"></i></button>
        `;
    }

    function renderTable(items) {
        const tbody = document.getElementById('usersTableBody');
        tbody.innerHTML = '';
        items.forEach(u => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escapeAttr(u.username)}</td>
                <td>${escapeAttr(u.profile)}</td>
                <td>${escapeAttr(u.mac_address) || '-'}</td>
                <td>${statusBadge(u.disabled)}</td>
                <td>${connBadge(u.connection)}</td>
                <td>${fmtExpiry(u.expiry)}</td>
                <td class="text-end">↓ ${fmtBytes(u.bytes_in)}<br>↑ ${fmtBytes(u.bytes_out)}</td>
                <td class="text-end text-nowrap">${actionsHtml(u)}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    function renderCards(items) {
        const wrap = document.getElementById('usersCardsWrap');
        wrap.innerHTML = '';
        items.forEach(u => {
            const card = document.createElement('div');
            card.className = 'u-card';
            card.innerHTML = `
                <div class="d-flex justify-content-between">
                    <span class="u-name">${escapeAttr(u.username)}</span>
                    ${statusBadge(u.disabled)}
                </div>
                <div class="u-meta">${escapeAttr(u.profile)} · ${connBadge(u.connection)}</div>
                <div class="u-meta">Expire : ${fmtExpiry(u.expiry)}</div>
                <div class="mt-2 d-flex gap-1">${actionsHtml(u)}</div>
            `;
            wrap.appendChild(card);
        });
    }

    function renderPagination(p) {
        const wrap = document.getElementById('paginationWrap');
        const list = document.getElementById('paginationList');
        list.innerHTML = '';
        if (p.pages <= 1) { wrap.style.display = 'none'; return; }
        wrap.style.display = '';

        function item(label, page, disabled, active) {
            const li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
            const a = document.createElement('a');
            a.className = 'page-link'; a.href = '#'; a.textContent = label;
            a.addEventListener('click', (ev) => { ev.preventDefault(); if (!disabled && !active) { state.page = page; loadUsers(); } });
            li.appendChild(a);
            return li;
        }

        list.appendChild(item('‹', Math.max(1, p.page - 1), p.page <= 1, false));
        const start = Math.max(1, p.page - 2);
        const end = Math.min(p.pages, p.page + 2);
        for (let i = start; i <= end; i++) list.appendChild(item(String(i), i, false, i === p.page));
        list.appendChild(item('›', Math.min(p.pages, p.page + 1), p.page >= p.pages, false));
    }

    document.getElementById('fSearch').addEventListener('input', (e) => {
        clearTimeout(state.searchDebounce);
        state.searchDebounce = setTimeout(() => {
            state.search = e.target.value.trim();
            state.page = 1;
            loadUsers();
        }, 400);
    });
    ['fProfile', 'fStatus', 'fConnection'].forEach(id => {
        document.getElementById(id).addEventListener('change', (e) => {
            const map = { fProfile: 'profile', fStatus: 'status', fConnection: 'connection' };
            state[map[id]] = e.target.value;
            state.page = 1;
            loadUsers();
        });
    });
    document.getElementById('fLimit').addEventListener('change', (e) => {
        state.limit = parseInt(e.target.value, 10);
        state.page = 1;
        loadUsers();
    });

    async function openDetail(username) {
        const modalEl = document.getElementById('detailModal');
        const body = document.getElementById('detailBody');
        body.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>';
        new bootstrap.Modal(modalEl).show();
        try {
            const u = await hotspotApi('hotspot-user', { params: { username } });
            body.innerHTML = `
                <dl class="row mb-0">
                    <dt class="col-5">Utilisateur</dt><dd class="col-7">${escapeAttr(u.username)}</dd>
                    <dt class="col-5">Profil</dt><dd class="col-7">${escapeAttr(u.profile)}</dd>
                    <dt class="col-5">MAC</dt><dd class="col-7">${escapeAttr(u.mac_address) || '-'}</dd>
                    <dt class="col-5">Statut</dt><dd class="col-7">${statusBadge(u.disabled)}</dd>
                    <dt class="col-5">Connexion</dt><dd class="col-7">${connBadge(u.connection)}</dd>
                    <dt class="col-5">Expiration</dt><dd class="col-7">${fmtExpiry(u.expiry)}</dd>
                    <dt class="col-5">Trafic</dt><dd class="col-7">↓ ${fmtBytes(u.bytes_in)} / ↑ ${fmtBytes(u.bytes_out)}</dd>
                    <dt class="col-5">Uptime</dt><dd class="col-7">${escapeAttr(u.uptime) || '-'}</dd>
                    <dt class="col-5">Serveur</dt><dd class="col-7">${escapeAttr(u.server) || '-'}</dd>
                    <dt class="col-5">Commentaire</dt><dd class="col-7">${escapeAttr(u.comment) || '-'}</dd>
                </dl>`;
        } catch (e) {
            body.innerHTML = `<div class="alert alert-danger mb-0">${escapeAttr(e.message || 'Erreur.')}</div>`;
        }
    }

    let formMode = 'create';
    let formOriginalUsername = null;

    function openCreateForm() {
        formMode = 'create';
        formOriginalUsername = null;
        document.getElementById('userFormTitle').textContent = 'Nouvel utilisateur';
        document.getElementById('userForm').reset();
        document.getElementById('fmUsername').disabled = false;
        document.getElementById('fmPassword').required = true;
        document.getElementById('userFormError').style.display = 'none';
        new bootstrap.Modal(document.getElementById('userFormModal')).show();
    }

    async function openEditForm(username) {
        formMode = 'edit';
        formOriginalUsername = username;
        document.getElementById('userFormTitle').textContent = 'Modifier ' + username;
        document.getElementById('userForm').reset();
        document.getElementById('userFormError').style.display = 'none';
        document.getElementById('fmPassword').required = false;
        new bootstrap.Modal(document.getElementById('userFormModal')).show();
        try {
            const u = await hotspotApi('hotspot-user', { params: { username } });
            document.getElementById('fmUsername').value = u.username;
            document.getElementById('fmUsername').disabled = true;
            document.getElementById('fmProfile').value = u.profile;
            document.getElementById('fmComment').value = u.comment || '';
            if (u.expiry) document.getElementById('fmExpiry').value = u.expiry.replace(' ', 'T').slice(0, 16);
        } catch (e) {
            const err = document.getElementById('userFormError');
            err.textContent = e.message || "Impossible de charger l'utilisateur.";
            err.style.display = '';
        }
    }

    document.getElementById('btnNewUser').addEventListener('click', openCreateForm);

    function commandToast(result, fallback) {
        const cmd = result && result.command;
        if (!cmd || !cmd.id) return fallback;
        return `${fallback} Commande #${cmd.id} (${cmd.status || 'PENDING'}).`;
    }

    document.getElementById('userForm').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const errEl = document.getElementById('userFormError');
        errEl.style.display = 'none';
        const submitBtn = document.getElementById('userFormSubmit');
        submitBtn.disabled = true;

        const username = document.getElementById('fmUsername').value.trim();
        const password = document.getElementById('fmPassword').value;
        const profile = document.getElementById('fmProfile').value;
        const comment = document.getElementById('fmComment').value.trim();
        const expiryRaw = document.getElementById('fmExpiry').value;
        const expiry = expiryRaw ? expiryRaw.replace('T', ' ') + ':00' : '';

        try {
            if (formMode === 'create') {
                const created = await hotspotApi('hotspot-user-create', { method: 'POST', body: { username, password, profile, comment, expiry } });
                toast(commandToast(created, 'Commande de création envoyée. En attente du routeur.'));
            } else {
                const body = { username: formOriginalUsername, profile, comment, expiry };
                if (password) body.password = password;
                const updated = await hotspotApi('hotspot-user-update', { method: 'POST', body });
                toast(commandToast(updated, 'Commande de modification envoyée. En attente du routeur.'));
            }
            bootstrap.Modal.getInstance(document.getElementById('userFormModal')).hide();
            loadUsers();
        } catch (e) {
            errEl.textContent = e.message || 'Une erreur est survenue.';
            errEl.style.display = '';
        } finally {
            submitBtn.disabled = false;
        }
    });

    async function toggleUser(username, disable) {
        try {
            const result = await hotspotApi(disable ? 'hotspot-user-disable' : 'hotspot-user-enable', { method: 'POST', body: { username } });
            toast(commandToast(result, disable ? 'Commande de désactivation envoyée. En attente du routeur.' : 'Commande d’activation envoyée. En attente du routeur.'));
            loadUsers();
        } catch (e) {
            toast('✕ ' + (e.message || (disable ? "Impossible de désactiver l'utilisateur." : "Impossible d'activer l'utilisateur.")), false);
        }
    }

    async function deleteUser(username) {
        if (!confirm(`Voulez-vous vraiment supprimer ${username} ? Cette opération peut être irréversible.`)) return;
        try {
            const result = await hotspotApi('hotspot-user-delete', { method: 'POST', body: { username } });
            toast(commandToast(result, 'Commande de suppression envoyée. En attente du routeur.'));
            loadUsers();
        } catch (e) {
            toast('✕ ' + (e.message || "Impossible de supprimer l'utilisateur."), false);
        }
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-act]');
        if (!btn) return;
        const act = btn.dataset.act;
        const username = btn.dataset.username;
        if (act === 'view') openDetail(username);
        else if (act === 'edit') openEditForm(username);
        else if (act === 'disable') toggleUser(username, true);
        else if (act === 'enable') toggleUser(username, false);
        else if (act === 'delete') deleteUser(username);
    });

    if (!<?= $hasAdminToken ? 'true' : 'false' ?>) {
        showBanner('Jeton administrateur introuvable côté serveur (ADMIN_TOKEN) : les appels API échoueront.');
    }
    loadUsers();
})();
</script>
