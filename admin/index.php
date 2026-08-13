<?php
declare(strict_types=1);

/**
 * admin/index.php — Dashboard "Mikhmon Personnel et Avancé"
 * -----------------------------------------------------------------------
 * Étape 1 de la restructuration (fondations + squelette visuel) :
 *   - Sidebar de navigation moderne (remplace la subnavbar de header.php
 *     pour CETTE page uniquement ; les autres pages admin/* gardent
 *     header.php pour l'instant, jusqu'à leur propre étape de bascule).
 *   - Section "Statut Réseau" : PREMIER test réel de la liaison
 *     synchrone Render/local -> routeur via l'API RouterOS/WireGuard.
 *     Rendu côté serveur (pas d'AJAX) pour que le résultat du test soit
 *     visible dès le chargement de page, avant même que du JS ne tourne.
 *   - Section "Gestion Business" : reprend le KPI/graphe déjà fonctionnel
 *     (route `dashboard` de api.php, inchangée) — pas de régression.
 *   - Section "WiFi Zone" : vrai placeholder vide, en attente de
 *     HotspotService (gestion hotspot en direct, étape ultérieure).
 * -----------------------------------------------------------------------
 */

require __DIR__ . '/auth.php';
require __DIR__ . '/../src/Mikrotik/RouterosClient.php';

$config = require __DIR__ . '/../config.php';
$adminToken = $config['admin']['token'] ?? getenv('ADMIN_TOKEN');
$pageTitle = 'Tableau de bord - ARA Tech WiFi';

// Test de connexion synchrone au routeur, en lecture seule.
// Ne lève jamais d'exception : voir ara_mikrotik_test_connection().
$mikrotikTest = ara_mikrotik_test_connection($config);

function ara_format_bytes(?int $bytes): string
{
    if ($bytes === null) {
        return '—';
    }
    $units = ['o', 'Ko', 'Mo', 'Go'];
    $value = (float)$bytes;
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return number_format($value, $i === 0 ? 0 : 1, ',', ' ') . ' ' . $units[$i];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --bleu-nuit: #0b2c82;
            --bleu-nuit-dark: #081f5c;
            --orange: #f5a623;
        }
        body {
            background: #f4f6f9;
            font-family: 'Segoe UI', sans-serif;
            overflow-x: hidden;
        }

        /* ---- Layout : sidebar + contenu ---- */
        .app-shell { display: flex; min-height: 100vh; }

        .sidebar {
            width: 250px;
            flex-shrink: 0;
            background: linear-gradient(180deg, var(--bleu-nuit) 0%, var(--bleu-nuit-dark) 100%);
            color: #fff;
            display: flex;
            flex-direction: column;
        }
        .sidebar-brand {
            font-weight: 700;
            font-size: 1.15rem;
            padding: 1.25rem 1rem;
            border-bottom: 3px solid var(--orange);
        }
        .sidebar-nav { padding: 0.75rem 0; flex: 1; overflow-y: auto; }
        .sidebar-nav .nav-link {
            color: rgba(255,255,255,0.85);
            padding: 0.65rem 1.1rem;
            font-size: 0.92rem;
            border-left: 3px solid transparent;
        }
        .sidebar-nav .nav-link i { width: 20px; margin-right: 8px; color: var(--orange); }
        .sidebar-nav .nav-link:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .sidebar-nav .nav-link.active {
            background: rgba(255,255,255,0.12);
            color: #fff;
            border-left-color: var(--orange);
            font-weight: 600;
        }
        .sidebar-nav .nav-section-title {
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.05em;
            color: rgba(255,255,255,0.45);
            padding: 1rem 1.1rem 0.35rem;
        }
        .sidebar-nav .nav-link.disabled-soon {
            color: rgba(255,255,255,0.45);
            cursor: pointer;
        }
        .badge-soon {
            font-size: 0.62rem;
            background: rgba(245,166,35,0.25);
            color: var(--orange);
            margin-left: 6px;
            vertical-align: middle;
        }
        .sidebar-footer { padding: 0.85rem 1.1rem; border-top: 1px solid rgba(255,255,255,0.1); }

        .main-content { flex: 1; min-width: 0; }
        .topbar {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 0.85rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        #sidebarToggle { display: none; }

        @media (max-width: 991.98px) {
            .sidebar { position: fixed; left: -260px; top: 0; bottom: 0; z-index: 1040; transition: left 0.2s ease; }
            .sidebar.open { left: 0; }
            #sidebarToggle { display: inline-flex; }
            .sidebar-backdrop {
                display: none;
                position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1030;
            }
            .sidebar-backdrop.open { display: block; }
        }

        /* ---- Cartes ---- */
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 1.5rem; }
        .card-header-custom { background: var(--bleu-nuit); color: #fff; border-radius: 12px 12px 0 0 !important; font-weight: 600; }
        .stat-value { font-size: 1.9rem; font-weight: 700; color: var(--bleu-nuit); }
        .stat-label { font-size: 0.88rem; color: #6c757d; }

        .status-pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 0.3rem 0.75rem; border-radius: 999px; font-weight: 600; font-size: 0.85rem;
        }
        .status-pill.online { background: #d1f4dd; color: #0f7a3d; }
        .status-pill.offline { background: #fde1e1; color: #b02525; }
        .status-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
        .status-dot.online { background: #28a745; }
        .status-dot.offline { background: #dc3545; }

        .btn-orange { background: var(--orange); border: none; color: #fff; font-weight: 600; }
        .btn-orange:hover { background: #e5941f; color: #fff; }

        .placeholder-zone {
            border: 2px dashed #d7dce3;
            border-radius: 12px;
            padding: 2.5rem 1.5rem;
            text-align: center;
            color: #8a93a3;
            background: #fbfcfe;
        }
        .placeholder-zone i { font-size: 2rem; color: #c3cad6; margin-bottom: 0.5rem; display: block; }
    </style>
</head>
<body>

<div class="app-shell">

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">⚡ ARA Tech WiFi</div>
        <nav class="sidebar-nav">
            <a class="nav-link active" href="index.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link" href="status.php"><i class="bi bi-wifi"></i> Statut détaillé</a>
            <a class="nav-link" href="hotspot.php"><i class="bi bi-people"></i> Hotspot</a>
            <a class="nav-link" href="inventory.php"><i class="bi bi-box-seam"></i> Stocks &amp; Import CSV</a>
            <a class="nav-link" href="finances.php"><i class="bi bi-cash-coin"></i> Finances</a>
            <a class="nav-link" href="reports.php"><i class="bi bi-graph-up"></i> Rapports</a>
            <a class="nav-link" href="ads.php"><i class="bi bi-megaphone"></i> Annonces</a>
            <a class="nav-link" href="logs.php"><i class="bi bi-journal-text"></i> Logs</a>
            <a class="nav-link" href="settings.php"><i class="bi bi-sliders"></i> Configuration</a>

            <div class="nav-section-title">À venir</div>
            <a class="nav-link disabled-soon" href="#business-section">
                <i class="bi bi-briefcase"></i> Gestion Business
            </a>
            <a class="nav-link disabled-soon" href="#wifizone-section">
                <i class="bi bi-router"></i> WiFi Zone <span class="badge badge-soon">Bientôt</span>
            </a>
        </nav>
        <div class="sidebar-footer">
            <a href="logout.php" class="btn btn-outline-light btn-sm w-100"><i class="bi bi-box-arrow-right"></i> Déconnexion</a>
        </div>
    </aside>

    <div class="main-content">
        <div class="topbar">
            <div class="d-flex align-items-center gap-2">
                <button id="sidebarToggle" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list"></i></button>
                <h5 class="mb-0">Tableau de bord</h5>
            </div>
            <span class="text-muted small">ARA Tech WiFi Admin</span>
        </div>

        <div class="container-fluid px-3 px-md-4 py-4">

            <!-- ============================================================ -->
            <!-- Statut Réseau / Sys Monitoring — test synchrone en direct    -->
            <!-- ============================================================ -->
            <div class="card card-custom" id="network-section">
                <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-hdd-network"></i> Statut Réseau — Liaison directe MikroTik</span>
                    <?php if ($mikrotikTest['success']): ?>
                        <span class="status-pill online"><span class="status-dot online"></span> En ligne (direct)</span>
                    <?php else: ?>
                        <span class="status-pill offline"><span class="status-dot offline"></span> Hors ligne</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($mikrotikTest['success']): ?>
                        <?php $d = $mikrotikTest['data']; ?>
                        <p class="text-success mb-3">
                            <i class="bi bi-check-circle-fill"></i>
                            Connexion synchrone réussie via le tunnel WireGuard
                            (<?= htmlspecialchars((string)($mikrotikTest['host'] ?? '?')) ?>:<?= htmlspecialchars((string)($mikrotikTest['port'] ?? '?')) ?>,
                            <?= (int)$mikrotikTest['latency_ms'] ?> ms).
                        </p>
                        <div class="row g-3">
                            <div class="col-6 col-md-3">
                                <div class="stat-label">Identité</div>
                                <div class="fw-semibold"><?= htmlspecialchars((string)($d['identity'] ?? '—')) ?></div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-label">Version RouterOS</div>
                                <div class="fw-semibold"><?= htmlspecialchars((string)($d['version'] ?? '—')) ?></div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-label">Modèle</div>
                                <div class="fw-semibold"><?= htmlspecialchars((string)($d['board_name'] ?? '—')) ?></div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-label">Uptime</div>
                                <div class="fw-semibold"><?= htmlspecialchars((string)($d['uptime'] ?? '—')) ?></div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-label">Charge CPU</div>
                                <div class="fw-semibold"><?= $d['cpu_load'] !== null ? $d['cpu_load'] . ' %' : '—' ?></div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-label">Mémoire libre</div>
                                <div class="fw-semibold"><?= htmlspecialchars(ara_format_bytes($d['free_memory'])) ?></div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-label">Mémoire totale</div>
                                <div class="fw-semibold"><?= htmlspecialchars(ara_format_bytes($d['total_memory'])) ?></div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-label">Architecture</div>
                                <div class="fw-semibold"><?= htmlspecialchars((string)($d['architecture'] ?? '—')) ?></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger d-flex align-items-start gap-2 mb-2">
                            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                            <div>
                                <strong>Erreur de liaison WireGuard / Routeur injoignable.</strong>
                                <div class="small mt-1">
                                    La communication synchrone Render → routeur n'a pas encore été
                                    établie avec succès. Vérifiez : le tunnel WireGuard (actif des deux
                                    côtés ?), les variables d'environnement
                                    <code>MIKROTIK_HOST</code> / <code>MIKROTIK_API_USER</code> /
                                    <code>MIKROTIK_API_PASS</code>, et que le service API est bien
                                    activé sur le routeur (<code>/ip service</code>, port
                                    <?= htmlspecialchars((string)($mikrotikTest['port'] ?? '8728')) ?>).
                                </div>
                            </div>
                        </div>
                        <details class="small text-muted">
                            <summary style="cursor:pointer;">Détail technique</summary>
                            <div class="mt-1"><code><?= htmlspecialchars((string)($mikrotikTest['error'] ?? 'Erreur inconnue.')) ?></code></div>
                            <div>Hôte testé : <?= htmlspecialchars((string)($mikrotikTest['host'] ?? '—')) ?>:<?= htmlspecialchars((string)($mikrotikTest['port'] ?? '—')) ?></div>
                            <div>Durée avant échec : <?= (int)$mikrotikTest['latency_ms'] ?> ms</div>
                        </details>
                    <?php endif; ?>
                    <div class="mt-3">
                        <a href="index.php" class="btn btn-sm btn-orange"><i class="bi bi-arrow-clockwise"></i> Retester la connexion</a>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- Gestion Business — KPI réels (route `dashboard` existante)   -->
            <!-- ============================================================ -->
            <div class="card card-custom" id="business-section">
                <div class="card-header card-header-custom d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-briefcase"></i> Gestion Business</span>
                    <div class="d-flex flex-wrap align-items-center">
                        <select id="period-select" class="form-select form-select-sm me-2" style="width: auto; min-width: 150px;">
                            <option value="today">Aujourd'hui</option>
                            <option value="yesterday">Hier</option>
                            <option value="7days">7 derniers jours</option>
                            <option value="thismonth" selected>Ce mois</option>
                            <option value="lastmonth">Mois précédent</option>
                        </select>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row" id="kpi-cards">
                        <div class="col-md-3 col-6 mb-3">
                            <div class="card card-custom text-center p-3 mb-0">
                                <div class="stat-value" id="kpi-revenue">--</div>
                                <div class="stat-label">Chiffre d'affaires</div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <div class="card card-custom text-center p-3 mb-0">
                                <div class="stat-value" id="kpi-tickets">--</div>
                                <div class="stat-label">Tickets vendus</div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <div class="card card-custom text-center p-3 mb-0">
                                <div class="stat-value" id="kpi-sessions">--</div>
                                <div class="stat-label">Sessions actives</div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <div class="card card-custom text-center p-3 mb-0">
                                <div class="stat-value" id="kpi-subs">--</div>
                                <div class="stat-label">Abonnements</div>
                            </div>
                        </div>
                    </div>
                    <canvas id="revenueChart" height="90"></canvas>
                    <p class="text-muted small mt-3 mb-0">
                        <i class="bi bi-info-circle"></i>
                        Écran complet "Finances &amp; Rapports" (recettes + dépenses réelles +
                        bénéfice) prévu à l'étape de fusion <code>finances.php</code> +
                        <code>reports.php</code>, une fois la table <code>expenses</code>
                        en place (migration 013).
                    </p>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- WiFi Zone — placeholder, HotspotService à venir              -->
            <!-- ============================================================ -->
            <div class="card card-custom" id="wifizone-section">
                <div class="card-header card-header-custom">
                    <i class="bi bi-router"></i> WiFi Zone
                </div>
                <div class="card-body">
                    <div class="placeholder-zone">
                        <i class="bi bi-tools"></i>
                        <strong>Gestion hotspot en direct — bientôt disponible</strong>
                        <p class="mb-0 small">
                            Sessions actives, création/activation/désactivation d'utilisateurs
                            et de profils en temps réel via <code>HotspotService</code>
                            (connexion RouterOS directe déjà en place ci-dessus). Arrive à
                            l'étape suivante de la feuille de route, une fois la liaison
                            WireGuard validée en conditions réelles.
                        </p>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- Sidebar responsive (mobile) ---
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const toggleBtn = document.getElementById('sidebarToggle');
    function closeSidebar() { sidebar.classList.remove('open'); backdrop.classList.remove('open'); }
    toggleBtn?.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        backdrop.classList.toggle('open');
    });
    backdrop.addEventListener('click', closeSidebar);

    // --- KPI "Gestion Business" (route existante api.php?route=dashboard) ---
    const token = <?= json_encode($adminToken) ?>;
    const apiUrl = '/api.php';
    let revenueChartInstance = null;

    function formatMoney(amount) {
        return new Intl.NumberFormat('fr-FR').format(amount || 0);
    }

    function fetchDashboard(period) {
        const url = new URL(apiUrl, window.location.origin);
        url.searchParams.append('route', 'dashboard');
        url.searchParams.append('token', token);
        url.searchParams.append('period', period);

        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || data.error?.message || 'Erreur inconnue');
                const kpis = data.kpis || {};
                document.getElementById('kpi-revenue').textContent = formatMoney(kpis.revenue) + ' FCFA';
                document.getElementById('kpi-tickets').textContent = kpis.tickets_sold ?? '--';
                document.getElementById('kpi-sessions').textContent = kpis.active_sessions ?? '--';
                document.getElementById('kpi-subs').textContent = kpis.active_subscriptions ?? '--';

                const ctx = document.getElementById('revenueChart').getContext('2d');
                if (revenueChartInstance) revenueChartInstance.destroy();
                revenueChartInstance = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: (data.revenue_chart || []).map(d => d.label),
                        datasets: [{
                            label: 'CA',
                            data: (data.revenue_chart || []).map(d => d.value),
                            backgroundColor: '#f5a623',
                            borderColor: '#0b2c82',
                            borderWidth: 1
                        }]
                    },
                    options: { responsive: true, scales: { y: { beginAtZero: true } } }
                });
            })
            .catch(err => {
                console.error('Dashboard KPI:', err);
                document.getElementById('kpi-revenue').textContent = 'N/A';
            });
    }

    document.getElementById('period-select').addEventListener('change', function () {
        fetchDashboard(this.value);
    });

    fetchDashboard('thismonth');
});
</script>
</body>
</html>
