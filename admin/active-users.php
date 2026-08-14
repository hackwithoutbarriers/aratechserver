<?php
declare(strict_types=1);
// Garde anti-accès-direct (voir users.php pour le détail du problème et la
// même protection). Cette page n'expose plus que de la lecture, mais reste
// protégée par cohérence avec le reste du module Hotspot.
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    require_once __DIR__ . '/auth.php';
}

// Page "Sessions actives" — RÉÉCRITE lors de la restructuration.
// ----------------------------------------------------------------
// L'ancienne version se connectait directement au routeur MikroTik
// (new Hotspot($config['mikrotik'])) pour lister ET modifier les sessions.
// Cette connexion ne peut pas fonctionner en production : le backend est
// hébergé sur Render, le routeur a une IP privée (192.168.88.1) qui n'est
// joignable que depuis le réseau local — voir api.php, route
// hotspot-session-disconnect ("aucune voie Render→192.168.88.1 n'existe").
//
// Cette page lit donc désormais le dernier snapshot poussé par le routeur
// (route hotspot-active, alimentée par mikrotik-scripts/push-hotspot-status.rsc)
// et n'offre plus d'action de mutation : déconnecter une session à distance
// n'est pas encore possible avec l'architecture actuelle (route API
// explicitement NOT_IMPLEMENTED côté serveur). Les actions sur un
// utilisateur enregistré (activer/désactiver/supprimer) vivent uniquement
// dans l'onglet "Utilisateurs" (users.php), pour éviter d'avoir la même
// action dupliquée à deux endroits différents.

require_once __DIR__ . '/../lib/api_client.php';
$config = require __DIR__ . '/../config.php';
$apiHeadersJson = ara_api_headers_json($config);
$hasAdminToken = ($config['admin']['token'] ?? '') !== '';
?>
<style>
    .sync-status { border-radius: 10px; padding: .6rem 1rem; font-size: .9rem; margin-bottom: 1rem; }
    .sync-status.online { background: #e9f7ef; color: #1e7e34; }
    .sync-status.offline { background: #fdecea; color: #b02a37; }
    .sync-status.unknown { background: #f1f3f5; color: #495057; }
</style>

<div class="card card-custom">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h3 class="mb-0"><i class="bi bi-wifi"></i> Sessions actives</h3>
        <button class="btn btn-outline-light btn-sm" id="btnRefresh"><i class="bi bi-arrow-clockwise"></i> Actualiser</button>
    </div>
    <div class="card-body">

        <div class="alert alert-warning" id="apiErrorBanner" role="alert" style="display:none"></div>

        <div class="sync-status unknown" id="syncStatus">
            <span class="spinner-border spinner-border-sm"></span> Vérification du statut de synchronisation…
        </div>

        <div id="sessionsLoading" class="text-center text-muted py-4">
            <div class="spinner-border spinner-border-sm"></div> Chargement des sessions…
        </div>

        <div class="table-responsive" id="sessionsTableWrap" style="display:none">
            <table class="table table-bordered table-hover text-nowrap mb-0" id="sessionsTable">
                <thead>
                    <tr>
                        <th>Utilisateur</th><th>Adresse IP</th><th>MAC</th>
                        <th>Uptime</th><th class="text-end">Bytes In</th><th class="text-end">Bytes Out</th><th>Commentaire</th>
                    </tr>
                </thead>
                <tbody id="sessionsTableBody"></tbody>
            </table>
        </div>

        <div id="sessionsEmpty" class="text-center text-muted py-4" style="display:none">Aucune session active pour le moment.</div>
    </div>
</div>

<script>
(function () {
    'use strict';
    const API_HEADERS = <?= $apiHeadersJson ?>;

    function escapeAttr(v) { return String(v == null ? '' : v); }
    function fmtBytes(n) {
        n = Number(n) || 0;
        if (n <= 0) return '0 B';
        const u = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(n) / Math.log(1024));
        return (n / Math.pow(1024, i)).toFixed(1) + ' ' + u[i];
    }
    // Le champ exact dépend du script routeur qui alimente le snapshot
    // (mikrotik-scripts/push-hotspot-status.rsc) : on tente les variantes
    // usuelles (kebab-case côté RouterOS, snake_case côté API) plutôt que
    // de supposer une seule convention.
    function pick(obj, keys) {
        for (const k of keys) if (obj[k] !== undefined && obj[k] !== null && obj[k] !== '') return obj[k];
        return '-';
    }

    function showBanner(message) {
        const el = document.getElementById('apiErrorBanner');
        el.textContent = message;
        el.style.display = 'block';
    }
    function hideBanner() { document.getElementById('apiErrorBanner').style.display = 'none'; }

    function renderSyncStatus(status) {
        const el = document.getElementById('syncStatus');
        el.classList.remove('online', 'offline', 'unknown');
        if (status === 'ONLINE') {
            el.classList.add('online');
            el.innerHTML = '<span class="status-dot online"></span> Routeur synchronisé — données à jour.';
        } else if (status === 'OFFLINE') {
            el.classList.add('offline');
            el.innerHTML = '<span class="status-dot offline"></span> Dernière synchronisation trop ancienne — le routeur semble hors ligne ou injoignable. Les sessions affichées peuvent être obsolètes.';
        } else {
            el.classList.add('unknown');
            el.innerHTML = '<span class="status-dot unknown"></span> Aucun snapshot reçu du routeur pour le moment.';
        }
    }

    async function loadSessions() {
        document.getElementById('sessionsLoading').style.display = '';
        document.getElementById('sessionsTableWrap').style.display = 'none';
        document.getElementById('sessionsEmpty').style.display = 'none';
        hideBanner();

        let res, json;
        try {
            res = await fetch('/api.php?route=hotspot-active', { headers: API_HEADERS });
            json = await res.json();
        } catch (e) {
            document.getElementById('sessionsLoading').style.display = 'none';
            showBanner('Impossible de joindre le serveur.');
            return;
        }
        if (!res.ok || json.success === false) {
            document.getElementById('sessionsLoading').style.display = 'none';
            showBanner((json.error && json.error.message) || json.message || 'Erreur lors de la récupération des sessions.');
            return;
        }

        const data = json.data;
        document.getElementById('sessionsLoading').style.display = 'none';
        renderSyncStatus(data.status);

        const sessions = data.sessions || [];
        const tbody = document.getElementById('sessionsTableBody');
        tbody.innerHTML = '';
        sessions.forEach(s => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escapeAttr(pick(s, ['user', 'username', 'name']))}</td>
                <td>${escapeAttr(pick(s, ['ip', 'address']))}</td>
                <td>${escapeAttr(pick(s, ['mac', 'mac-address', 'mac_address']))}</td>
                <td>${escapeAttr(pick(s, ['uptime']))}</td>
                <td class="text-end">${fmtBytes(pick(s, ['bytes-in', 'bytes_in']))}</td>
                <td class="text-end">${fmtBytes(pick(s, ['bytes-out', 'bytes_out']))}</td>
                <td>${escapeAttr(pick(s, ['comment']))}</td>
            `;
            tbody.appendChild(tr);
        });

        if (sessions.length === 0) {
            document.getElementById('sessionsEmpty').style.display = '';
        } else {
            document.getElementById('sessionsTableWrap').style.display = '';
        }
    }

    document.getElementById('btnRefresh').addEventListener('click', loadSessions);

    if (!<?= $hasAdminToken ? 'true' : 'false' ?>) {
        showBanner('Jeton administrateur introuvable côté serveur (ADMIN_TOKEN) : les appels API échoueront.');
    }
    loadSessions();
    // Rafraîchissement automatique : les sessions changent en continu et
    // cette page est en lecture seule (pas de risque de perdre une saisie).
    setInterval(loadSessions, 30000);
})();
</script>
