<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';

$pdo = ara_db($config);
$pdo->exec("CREATE TABLE IF NOT EXISTS hotspot_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    snapshot_date TEXT NOT NULL,
    snapshot_time TEXT NOT NULL,
    active_count INTEGER NOT NULL,
    users_blob TEXT,
    received_at TEXT NOT NULL
)");

// Restaurer depuis Turso si la locale est vide (dernier snapshot)
restore_from_turso_if_empty(
    $pdo, $config,
    'hotspot_snapshots',
    'SELECT snapshot_date, snapshot_time, active_count, users_blob, received_at FROM hotspot_snapshots ORDER BY id DESC LIMIT 1',
    [],
    'INSERT INTO hotspot_snapshots (snapshot_date, snapshot_time, active_count, users_blob, received_at) VALUES (?, ?, ?, ?, ?)'
);

// Token admin pour les appels JS
$adminToken = $config['admin']['token'] ?? getenv('ADMIN_TOKEN');

$pageTitle = 'Tableau de bord - ARA Tech WiFi';
require __DIR__ . '/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Tableau de bord</h2>
        <div class="d-flex align-items-center">
            <select id="period-select" class="form-select me-2" style="width: auto;">
                <option value="today">Aujourd'hui</option>
                <option value="yesterday">Hier</option>
                <option value="7days">7 derniers jours</option>
                <option value="thismonth" selected>Ce mois</option>
                <option value="lastmonth">Mois précédent</option>
                <option value="custom">Personnalisé</option>
            </select>
            <div id="custom-dates" style="display:none;" class="d-flex align-items-center">
                <input type="date" id="start-date" class="form-control me-1" style="width: auto;">
                <input type="date" id="end-date" class="form-control me-1" style="width: auto;">
                <button id="apply-custom" class="btn btn-primary">Appliquer</button>
            </div>
        </div>
    </div>

    <!-- KPIs -->
    <div class="row" id="kpi-cards">
        <!-- CA -->
        <div class="col-md-3 col-6 mb-3">
            <div class="card card-custom text-center p-3 skeleton">
                <div class="stat-value" id="kpi-revenue">--</div>
                <div class="stat-label">Chiffre d'affaires</div>
                <div class="small-text" id="kpi-revenue-var"></div>
            </div>
        </div>
        <!-- Tickets -->
        <div class="col-md-3 col-6 mb-3">
            <div class="card card-custom text-center p-3 skeleton">
                <div class="stat-value" id="kpi-tickets">--</div>
                <div class="stat-label">Tickets vendus</div>
                <div class="small-text" id="kpi-tickets-var"></div>
            </div>
        </div>
        <!-- Sessions -->
        <div class="col-md-2 col-6 mb-3">
            <div class="card card-custom text-center p-3 skeleton">
                <div class="stat-value" id="kpi-sessions">--</div>
                <div class="stat-label">Sessions actives</div>
            </div>
        </div>
        <!-- Abonnements -->
        <div class="col-md-2 col-6 mb-3">
            <div class="card card-custom text-center p-3 skeleton">
                <div class="stat-value" id="kpi-subs">--</div>
                <div class="stat-label">Abonnements</div>
                <small class="text-muted" id="kpi-subs-expiring"></small>
            </div>
        </div>
        <!-- Réseau -->
        <div class="col-md-2 col-12 mb-3">
            <div class="card card-custom text-center p-3 skeleton">
                <div class="stat-value" id="kpi-network">--</div>
                <div class="stat-label">Réseau</div>
            </div>
        </div>
    </div>

    <!-- Rangée graphique + dernières ventes -->
    <div class="row">
        <div class="col-lg-8 mb-3">
            <div class="card card-custom">
                <div class="card-header card-header-custom">Évolution du chiffre d'affaires</div>
                <div class="card-body">
                    <canvas id="revenueChart" height="100"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card card-custom">
                <div class="card-header card-header-custom">Dernières ventes</div>
                <div class="card-body p-0">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr><th>Heure</th><th>Produit</th><th>Montant</th></tr>
                        </thead>
                        <tbody id="recent-sales-tbody">
                            <!-- Rempli par JS -->
                        </tbody>
                    </table>
                    <div id="sales-empty" class="text-muted text-center p-3 d-none">Aucune vente pour cette période.</div>
                </div>
                <div class="card-footer text-center">
                    <a href="/admin/sales.php" class="btn btn-sm btn-outline-primary">Voir toutes les ventes</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Widget réseau détaillé -->
    <div class="row mt-3">
        <div class="col-12">
            <div class="card card-custom">
                <div class="card-header card-header-custom">État du réseau</div>
                <div class="card-body">
                    <div class="row" id="network-details">
                        <!-- Rempli par JS -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alertes -->
    <div class="row mt-3">
        <div class="col-12">
            <div class="card card-custom">
                <div class="card-header card-header-custom">Alertes</div>
                <div class="card-body" id="alerts-container">
                    <p class="text-muted mb-0">Chargement...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions rapides -->
    <div class="row mt-3 mb-4">
        <div class="col-12 d-flex flex-wrap gap-2">
            <a href="/admin/tickets.php" class="btn btn-success">🎫 Vendre ticket</a>
            <a href="/admin/subscriptions.php" class="btn btn-warning">⭐ Abonnement</a>
            <a href="/admin/clients.php" class="btn btn-info">👤 Client</a>
            <a href="/admin/reports.php" class="btn btn-secondary">📊 Rapports</a>
        </div>
    </div>
</div>

<script>
// Code JavaScript pour le dashboard V2.1
const token = <?= json_encode($adminToken) ?>;
const apiUrl = '/api.php';
let revenueChartInstance = null;

// Éléments DOM
const periodSelect = document.getElementById('period-select');
const customDatesDiv = document.getElementById('custom-dates');
const startDateInput = document.getElementById('start-date');
const endDateInput = document.getElementById('end-date');
const applyCustomBtn = document.getElementById('apply-custom');

periodSelect.addEventListener('change', function() {
    if (this.value === 'custom') {
        customDatesDiv.style.display = 'flex';
    } else {
        customDatesDiv.style.display = 'none';
        fetchDashboard(this.value);
    }
});

applyCustomBtn.addEventListener('click', function() {
    const start = startDateInput.value;
    const end = endDateInput.value;
    if (start && end) {
        fetchDashboard('custom', start, end);
    } else {
        alert('Veuillez sélectionner les deux dates.');
    }
});

function fetchDashboard(period, start = '', end = '') {
    // Afficher skeletons
    document.querySelectorAll('.card-custom.skeleton').forEach(el => el.classList.add('loading'));
    const url = new URL(apiUrl, window.location.origin);
    url.searchParams.append('route', 'dashboard');
    url.searchParams.append('token', token);
    url.searchParams.append('period', period);
    if (period === 'custom') {
        url.searchParams.append('start', start);
        url.searchParams.append('end', end);
    }

    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Erreur inconnue');
            updateKPIs(data.kpis);
            updateChart(data.revenue_chart);
            updateRecentSales(data.recent_sales);
            updateNetwork(data.network);
            updateAlerts(data.alerts);
            document.querySelectorAll('.card-custom.skeleton').forEach(el => el.classList.remove('loading'));
        })
        .catch(error => {
            console.error(error);
            alert('Erreur lors du chargement des données : ' + error.message);
            document.querySelectorAll('.card-custom.skeleton').forEach(el => el.classList.remove('loading'));
        });
}

function updateKPIs(kpis) {
    document.getElementById('kpi-revenue').textContent = formatMoney(kpis.revenue) + ' FCFA';
    const varRev = kpis.revenue_variation;
    const revVarEl = document.getElementById('kpi-revenue-var');
    revVarEl.textContent = varRev === 'Nouveau' ? 'Nouveau' : (varRev >= 0 ? '+' + varRev : varRev) + '%';
    revVarEl.className = 'small-text ' + (varRev === 'Nouveau' ? 'text-muted' : (varRev >= 0 ? 'text-success' : 'text-danger'));

    document.getElementById('kpi-tickets').textContent = kpis.tickets_sold;
    const varTickets = kpis.tickets_variation;
    const tickVarEl = document.getElementById('kpi-tickets-var');
    tickVarEl.textContent = varTickets === 'Nouveau' ? 'Nouveau' : (varTickets >= 0 ? '+' + varTickets : varTickets) + ' tickets';
    tickVarEl.className = 'small-text ' + (varTickets === 'Nouveau' ? 'text-muted' : (varTickets >= 0 ? 'text-success' : 'text-danger'));

    document.getElementById('kpi-sessions').textContent = kpis.active_sessions;
    document.getElementById('kpi-subs').textContent = kpis.active_subscriptions;
    const expiringEl = document.getElementById('kpi-subs-expiring');
    expiringEl.textContent = kpis.expiring_subscriptions > 0 ? `${kpis.expiring_subscriptions} expire(nt) bientôt` : '';

    // État réseau global (basé sur le KPI, mais on le mettra à jour plus tard via le détail)
    document.getElementById('kpi-network').textContent = kpis.active_sessions >= 0 ? 'ONLINE' : 'OFFLINE'; // temporaire
}

function updateChart(chartData) {
    const ctx = document.getElementById('revenueChart').getContext('2d');
    if (revenueChartInstance) {
        revenueChartInstance.destroy();
    }
    revenueChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: chartData.map(d => d.label),
            datasets: [{
                label: 'CA',
                data: chartData.map(d => d.value),
                backgroundColor: '#f5a623',
                borderColor: '#0b2c82',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true } },
            plugins: { tooltip: { callbacks: { label: ctx => formatMoney(ctx.raw) + ' FCFA' } } }
        }
    });
}

function updateRecentSales(sales) {
    const tbody = document.getElementById('recent-sales-tbody');
    const emptyDiv = document.getElementById('sales-empty');
    tbody.innerHTML = '';
    if (!sales || sales.length === 0) {
        emptyDiv.classList.remove('d-none');
        return;
    }
    emptyDiv.classList.add('d-none');
    sales.forEach(sale => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${sale.sale_time.substring(0,5)}</td>
            <td>${escapeHtml(sale.profile || 'Inconnu')}</td>
            <td>${formatMoney(sale.amount)} FCFA</td>
        `;
        tbody.appendChild(tr);
    });
}

function updateNetwork(network) {
    const container = document.getElementById('network-details');
    container.innerHTML = '';
    const statusMap = { 'ONLINE': '✅', 'OFFLINE': '❌', 'DEGRADED': '⚠️', 'UNKNOWN': '❓' };
    for (const [component, state] of Object.entries(network)) {
        const col = document.createElement('div');
        col.className = 'col-md-3 col-6 mb-2';
        col.innerHTML = `
            <div class="d-flex align-items-center">
                <span style="font-size: 1.5em;">${statusMap[state] || '❓'}</span>
                <span class="ms-2">${component}: <strong>${state}</strong></span>
            </div>
        `;
        container.appendChild(col);
    }
    // Mise à jour de l'état global dans le KPI réseau
    const globalState = network.mikrotik === 'ONLINE' ? 'ONLINE' : 'OFFLINE';
    document.getElementById('kpi-network').textContent = globalState;
}

function updateAlerts(alerts) {
    const container = document.getElementById('alerts-container');
    if (!alerts || alerts.length === 0) {
        container.innerHTML = '<p class="text-muted mb-0">Aucune alerte.</p>';
        return;
    }
    let html = '<ul class="list-group">';
    alerts.forEach(alert => {
        const levelClass = alert.level === 'CRITIQUE' ? 'list-group-item-danger' : 'list-group-item-warning';
        html += `<li class="list-group-item ${levelClass} d-flex justify-content-between align-items-center">
            <div>
                <strong>[${alert.level}] ${escapeHtml(alert.title)}</strong>
                <div class="small">${escapeHtml(alert.description)}</div>
            </div>
            <span class="badge bg-secondary">${alert.time}</span>
        </li>`;
    });
    html += '</ul>';
    container.innerHTML = html;
}

function formatMoney(amount) {
    return new Intl.NumberFormat('fr-FR').format(amount);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Charger la période par défaut au démarrage
document.addEventListener('DOMContentLoaded', () => {
    fetchDashboard('thismonth');
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
