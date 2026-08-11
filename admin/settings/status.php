<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';   // plus besoin de ../admin/
$config = require __DIR__ . '/../config.php';

$pageTitle = 'Statut Hotspot - ARA Tech WiFi';

// V2.1 : aucune requête SQL directe ici. Toutes les données proviennent de
// GET api.php?route=status, appelée en JavaScript avec le même mécanisme
// d'authentification (token admin en paramètre de requête) que le Dashboard V2.1.
$adminToken = $config['admin']['token'] ?? getenv('ADMIN_TOKEN');

require __DIR__ . '/header.php';
?>

<style>
    /* --- Statut V2.1 : styles additionnels scoping cette page uniquement --- */
    .status-hero { padding: 1.75rem 1rem; }
    .status-hero .big-state { font-size: 1.6rem; font-weight: 700; }
    .status-hero .big-state.online  { color: #1e7e34; }
    .status-hero .big-state.offline { color: #c0392b; }
    .status-hero .big-state.unknown { color: #6c757d; }
    .status-hero .status-dot { width: 16px; height: 16px; }
    .status-dot.unknown { background: #adb5bd; }

    .mini-kpi { text-align: center; padding: .75rem .5rem; }
    .mini-kpi .mini-kpi-value { font-size: 1.4rem; font-weight: 700; color: var(--bleu-nuit); }
    .mini-kpi .mini-kpi-label { font-size: .8rem; color: #6c757d; text-transform: uppercase; letter-spacing: .03em; }

    .net-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: .75rem; }
    .net-item { display: flex; align-items: center; gap: .5rem; padding: .6rem .8rem; border-radius: 8px; background: #f8f9fb; font-size: .92rem; }
    .net-item .dot { width: 12px; height: 12px; border-radius: 50%; flex: 0 0 auto; }
    .net-item .dot.online  { background: #28a745; }
    .net-item .dot.offline { background: #dc3545; }
    .net-item .dot.unknown { background: #adb5bd; }

    #status-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    #status-table-wrap table { min-width: 720px; }
    #status-table-wrap th, #status-table-wrap td { white-space: nowrap; font-size: .9rem; }

    .skeleton-line { display: inline-block; background: linear-gradient(90deg,#e9ecef 25%,#f4f6f9 37%,#e9ecef 63%); background-size: 400% 100%; animation: skeleton-pulse 1.4s ease infinite; border-radius: 4px; color: transparent !important; min-width: 2.5em; }
    @keyframes skeleton-pulse { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }

    .alert-list .list-group-item { border-left: 4px solid transparent; }
    .alert-list .list-group-item-danger  { border-left-color: #dc3545; }
    .alert-list .list-group-item-warning { border-left-color: #f5a623; }
    .alert-list .list-group-item-success { border-left-color: #28a745; }

    #refresh-btn.is-loading .bi-arrow-clockwise { display: inline-block; animation: spin 0.8s linear infinite; }
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

    @media (max-width: 576px) {
        .status-hero .big-state { font-size: 1.3rem; }
    }
</style>

<div class="container-fluid px-3 px-md-4 mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h2 class="mb-2 mb-md-0">📡 Statut du Hotspot</h2>
        <div class="d-flex align-items-center flex-wrap">
            <small class="text-muted me-2" id="last-ui-update">Dernière mise à jour de l'interface : --</small>
            <button id="refresh-btn" class="btn btn-orange btn-sm">
                <i class="bi bi-arrow-clockwise"></i> Actualiser
            </button>
        </div>
    </div>

    <div id="api-error-banner" class="alert alert-warning d-none" role="alert">
        ⚠️ Impossible de récupérer le statut réseau.
        <span id="last-known-note"></span>
    </div>

    <!-- Section 1 : État global -->
    <div class="card card-custom status-hero text-center">
        <div class="card-header card-header-custom">
            <i class="bi bi-router"></i> État du routeur
        </div>
        <div class="card-body">
            <div class="big-state unknown" id="router-big-state">
                <span class="status-dot unknown" id="router-status-dot"></span>
                <span id="router-status-text">Chargement...</span>
            </div>
            <div class="mt-2">
                <small class="text-muted">Dernier snapshot : <span id="hero-last-snapshot">--</span></small><br>
                <small class="text-muted">Âge : <span id="hero-age">--</span></small>
            </div>
        </div>
    </div>

    <!-- Section 2 : Informations routeur -->
    <div class="card card-custom mt-3">
        <div class="card-header card-header-custom">
            <i class="bi bi-cpu"></i> Informations du routeur
        </div>
        <div class="card-body">
            <div class="row mb-2">
                <div class="col-6 col-md-3 mini-kpi">
                    <div class="mini-kpi-value skeleton-line" id="kpi-cpu">--</div>
                    <div class="mini-kpi-label">CPU</div>
                </div>
                <div class="col-6 col-md-3 mini-kpi">
                    <div class="mini-kpi-value skeleton-line" id="kpi-ram">--</div>
                    <div class="mini-kpi-label">Mémoire</div>
                </div>
                <div class="col-6 col-md-3 mini-kpi">
                    <div class="mini-kpi-value skeleton-line" id="kpi-uptime">--</div>
                    <div class="mini-kpi-label">Uptime</div>
                </div>
                <div class="col-6 col-md-3 mini-kpi">
                    <div class="mini-kpi-value skeleton-line" id="kpi-sessions">--</div>
                    <div class="mini-kpi-label">Sessions actives</div>
                </div>
            </div>
            <table class="table table-sm table-borderless mb-0">
                <tbody>
                    <tr><th style="width:220px;">Identité</th><td id="info-identity">Chargement...</td></tr>
                    <tr><th>Version RouterOS</th><td id="info-version">Chargement...</td></tr>
                    <tr><th>Dernière synchronisation</th><td id="info-lastsync">Chargement...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section 3 : Utilisateurs actifs -->
    <div class="card card-custom mt-3">
        <div class="card-header card-header-custom">
            <i class="bi bi-people-fill"></i> Utilisateurs actifs
        </div>
        <div class="card-body">
            <div class="text-center mb-3">
                <div class="stat-value skeleton-line" id="active-count-value">--</div>
                <div class="stat-label">sessions actives</div>
            </div>
            <div id="status-table-wrap">
                <table class="table table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th><th>Utilisateur</th><th>IP</th><th>MAC</th>
                            <th>Profil</th><th>Uptime</th><th>Trafic</th>
                        </tr>
                    </thead>
                    <tbody id="users-tbody">
                        <tr><td colspan="7" class="text-center text-muted">Chargement...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="users-empty" class="alert alert-info mt-2 d-none">Aucun utilisateur connecté pour le moment.</div>
        </div>
    </div>

    <!-- Section 4 : État réseau -->
    <div class="card card-custom mt-3">
        <div class="card-header card-header-custom">
            <i class="bi bi-hdd-network"></i> État du réseau
        </div>
        <div class="card-body">
            <div class="net-grid" id="network-grid">
                <div class="net-item"><span class="dot unknown"></span> Internet: <strong>--</strong></div>
                <div class="net-item"><span class="dot unknown"></span> MikroTik: <strong>--</strong></div>
                <div class="net-item"><span class="dot unknown"></span> PoE Switch: <strong>--</strong></div>
                <div class="net-item"><span class="dot unknown"></span> AP-01: <strong>--</strong></div>
                <div class="net-item"><span class="dot unknown"></span> AP-02: <strong>--</strong></div>
            </div>
        </div>
    </div>

    <!-- Section 5 : Synchronisation -->
    <div class="card card-custom mt-3">
        <div class="card-header card-header-custom">
            <i class="bi bi-arrow-repeat"></i> Synchronisation
        </div>
        <div class="card-body">
            <table class="table table-sm table-borderless mb-0">
                <tbody>
                    <tr><th style="width:260px;">Dernier snapshot</th><td id="sync-last-snapshot">--</td></tr>
                    <tr><th>Âge du snapshot</th><td id="sync-age">--</td></tr>
                    <tr><th>Source</th><td id="sync-source">Push MikroTik</td></tr>
                    <tr><th>Dernière mise à jour de l'interface</th><td id="sync-ui-update">--</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section 6 : Alertes -->
    <div class="card card-custom mt-3 mb-4">
        <div class="card-header card-header-custom">
            <i class="bi bi-exclamation-triangle"></i> Alertes
        </div>
        <div class="card-body" id="alerts-container">
            <p class="text-muted mb-0">Chargement...</p>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const token = <?= json_encode($adminToken) ?>;
    const apiUrl = '/api.php';
    const REFRESH_MS = 30000;

    let isFetching = false;
    let lastGoodData = null;
    let refreshTimer = null;

    // ---------------------------------------------------------------
    // Fonctions de formatage réutilisables
    // ---------------------------------------------------------------
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = (text === null || text === undefined) ? '' : String(text);
        return div.innerHTML;
    }

    function formatNumber(n) {
        if (n === null || n === undefined || n === '') return 'N/D';
        return new Intl.NumberFormat('fr-FR').format(n);
    }

    function formatBytes(bytes) {
        if (bytes === null || bytes === undefined || bytes === '' || isNaN(bytes)) return 'N/D';
        bytes = Number(bytes);
        if (bytes === 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
        const value = bytes / Math.pow(1024, i);
        return (i === 0 ? value : value.toFixed(1)) + ' ' + units[i];
    }

    // Les uptimes proviennent du routeur déjà formatés (ex: "4d12h30m00s").
    // On les affiche tels quels ; on ne convertit que si une valeur numérique
    // (secondes) est reçue à la place.
    function formatUptime(raw) {
        if (raw === null || raw === undefined || raw === '') return 'N/D';
        if (typeof raw === 'number' || /^\d+$/.test(String(raw))) {
            let seconds = Number(raw);
            const d = Math.floor(seconds / 86400); seconds %= 86400;
            const h = Math.floor(seconds / 3600); seconds %= 3600;
            const m = Math.floor(seconds / 60);
            let out = '';
            if (d > 0) out += d + 'j ';
            if (h > 0 || d > 0) out += h + 'h ';
            out += m + 'min';
            return out.trim();
        }
        return String(raw);
    }

    function formatDateTime(raw) {
        if (!raw) return 'N/D';
        // "YYYY-MM-DD HH:MM:SS" ou ISO-8601
        const normalized = String(raw).includes('T') ? raw : String(raw).replace(' ', 'T');
        const d = new Date(normalized);
        if (isNaN(d.getTime())) return escapeHtml(raw);
        const pad = (x) => String(x).padStart(2, '0');
        return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    }

    function formatAge(seconds) {
        if (seconds === null || seconds === undefined) return 'N/D';
        seconds = Math.max(0, Math.floor(seconds));
        if (seconds < 60) return seconds + ' s';
        if (seconds < 3600) return Math.floor(seconds / 60) + ' min ' + (seconds % 60) + ' s';
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        return h + ' h ' + m + ' min';
    }

    // status: 'ONLINE' | 'OFFLINE' | 'UNKNOWN'
    function formatStatus(status) {
        switch (status) {
            case 'ONLINE':  return { emoji: '🟢', label: 'EN LIGNE',      cls: 'online' };
            case 'OFFLINE': return { emoji: '🔴', label: 'HORS LIGNE',    cls: 'offline' };
            default:        return { emoji: '⚪', label: 'STATUT INCONNU', cls: 'unknown' };
        }
    }

    function formatPercent(value) {
        if (value === null || value === undefined || value === '') return 'N/D';
        return Math.round(Number(value)) + ' %';
    }

    // ---------------------------------------------------------------
    // Rendu
    // ---------------------------------------------------------------
    function clearSkeleton() {
        document.querySelectorAll('.skeleton-line').forEach(el => el.classList.remove('skeleton-line'));
    }

    function renderRouterState(router) {
        const st = formatStatus(router.status);
        const bigState = document.getElementById('router-big-state');
        const dot = document.getElementById('router-status-dot');
        const text = document.getElementById('router-status-text');

        bigState.className = 'big-state ' + st.cls;
        dot.className = 'status-dot ' + st.cls;
        text.textContent = st.emoji + ' ' + st.label;

        document.getElementById('hero-last-snapshot').textContent = formatDateTime(router.last_snapshot);
        document.getElementById('hero-age').textContent = formatAge(router.age_seconds);

        document.getElementById('kpi-cpu').textContent = formatPercent(router.cpu);

        let memText = 'N/D';
        if (router.memory_total !== null && router.memory_total !== undefined &&
            router.memory_free !== null && router.memory_free !== undefined && router.memory_total > 0) {
            const used = router.memory_total - router.memory_free;
            const pct = Math.round((used / router.memory_total) * 100);
            memText = pct + ' % utilisé';
        }
        document.getElementById('kpi-ram').textContent = memText;

        document.getElementById('kpi-uptime').textContent = formatUptime(router.uptime);

        document.getElementById('info-identity').textContent = router.identity || 'N/D';
        document.getElementById('info-version').textContent = router.version ? ('RouterOS ' + router.version) : 'N/D';
        document.getElementById('info-lastsync').textContent = formatDateTime(router.last_snapshot);

        // Section Synchronisation
        document.getElementById('sync-last-snapshot').textContent = formatDateTime(router.last_snapshot);
        document.getElementById('sync-age').textContent = formatAge(router.age_seconds);
    }

    function renderSessions(sessions) {
        const count = sessions.active_count ?? 0;
        document.getElementById('kpi-sessions').textContent = formatNumber(count);
        document.getElementById('active-count-value').textContent = formatNumber(count);

        const tbody = document.getElementById('users-tbody');
        const empty = document.getElementById('users-empty');
        const users = Array.isArray(sessions.users) ? sessions.users : [];

        if (users.length === 0) {
            tbody.innerHTML = '';
            empty.classList.remove('d-none');
        } else {
            empty.classList.add('d-none');
            tbody.innerHTML = users.map((u, i) => {
                const trafic = '↓ ' + formatBytes(u.bytes_in) + ' / ↑ ' + formatBytes(u.bytes_out);
                return `<tr>
                    <td>${i + 1}</td>
                    <td>${escapeHtml(u.user)}</td>
                    <td>${escapeHtml(u.ip)}</td>
                    <td>${escapeHtml(u.mac)}</td>
                    <td>${escapeHtml(u.profile || 'N/D')}</td>
                    <td>${escapeHtml(formatUptime(u.uptime))}</td>
                    <td>${escapeHtml(trafic)}</td>
                </tr>`;
            }).join('');
        }
    }

    function renderNetwork(network) {
        const labels = { internet: 'Internet', mikrotik: 'MikroTik', poe_switch: 'PoE Switch', ap_01: 'AP-01', ap_02: 'AP-02' };
        const order = ['internet', 'mikrotik', 'poe_switch', 'ap_01', 'ap_02'];
        const grid = document.getElementById('network-grid');
        grid.innerHTML = order.map(key => {
            const state = network[key] || 'UNKNOWN';
            const st = formatStatus(state === 'ONLINE' ? 'ONLINE' : (state === 'OFFLINE' ? 'OFFLINE' : 'UNKNOWN'));
            return `<div class="net-item"><span class="dot ${st.cls}"></span> ${labels[key] || key}: <strong>${st.emoji} ${escapeHtml(state)}</strong></div>`;
        }).join('');
    }

    function renderAlerts(router) {
        const container = document.getElementById('alerts-container');
        if (router.status === 'OFFLINE') {
            container.innerHTML = `<ul class="list-group alert-list">
                <li class="list-group-item list-group-item-danger">
                    <strong>[CRITIQUE] Routeur injoignable</strong>
                    <div class="small">Dernier snapshot : ${escapeHtml(formatDateTime(router.last_snapshot))}</div>
                </li>
            </ul>`;
        } else if (router.status === 'UNKNOWN') {
            container.innerHTML = `<ul class="list-group alert-list">
                <li class="list-group-item list-group-item-warning">
                    <strong>[AVERTISSEMENT] Aucune donnée de statut disponible.</strong>
                </li>
            </ul>`;
        } else {
            container.innerHTML = '<p class="text-muted mb-0">Aucune alerte critique.</p>';
        }
    }

    function touchUiUpdateTimestamp() {
        const now = new Date();
        const pad = (x) => String(x).padStart(2, '0');
        const text = `${pad(now.getDate())}/${pad(now.getMonth() + 1)}/${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
        document.getElementById('last-ui-update').textContent = "Dernière mise à jour de l'interface : " + text;
        document.getElementById('sync-ui-update').textContent = text;
    }

    function showApiError(show) {
        const banner = document.getElementById('api-error-banner');
        const note = document.getElementById('last-known-note');
        if (show) {
            banner.classList.remove('d-none');
            note.textContent = lastGoodData
                ? 'Dernière donnée connue : ' + formatDateTime(lastGoodData.router.last_snapshot)
                : '';
        } else {
            banner.classList.add('d-none');
        }
    }

    function renderData(data) {
        clearSkeleton();
        renderRouterState(data.router);
        renderSessions(data.sessions);
        renderNetwork(data.network);
        renderAlerts(data.router);
        touchUiUpdateTimestamp();
    }

    function setButtonLoading(loading) {
        const btn = document.getElementById('refresh-btn');
        btn.disabled = loading;
        btn.classList.toggle('is-loading', loading);
    }

    function fetchStatus() {
        if (isFetching) return; // pas de fetch simultané
        isFetching = true;
        setButtonLoading(true);

        const url = new URL(apiUrl, window.location.origin);
        url.searchParams.append('route', 'status');
        url.searchParams.append('token', token);

        fetch(url)
            .then(response => {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(data => {
                if (!data.ok) throw new Error(data.message || 'Erreur inconnue');
                lastGoodData = data;
                showApiError(false);
                renderData(data);
            })
            .catch(error => {
                console.error(error);
                showApiError(true);
                if (lastGoodData) {
                    // Conserver les dernières données affichées.
                    touchUiUpdateTimestamp();
                }
            })
            .finally(() => {
                isFetching = false;
                setButtonLoading(false);
            });
    }

    document.getElementById('refresh-btn').addEventListener('click', fetchStatus);

    // Actualisation automatique toutes les 30s (un seul intervalle).
    fetchStatus();
    refreshTimer = setInterval(fetchStatus, REFRESH_MS);
});
</script>

</body>
</html>
