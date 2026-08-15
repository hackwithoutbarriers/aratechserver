<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
$config = require __DIR__ . '/../config.php';
$pageTitle = 'Hotspot - ARA Tech WiFi';
$activeTab = $_GET['tab'] ?? 'users';

require_once __DIR__ . '/header.php';
?>

<div class="container-fluid mt-4">
    <h2 class="mb-3">📡 Gestion du Hotspot</h2>

    <!--
        Architecture: toutes les données affichées ici viennent du miroir
        Supabase alimenté par le routeur. Les mutations passent par la file
        hotspot_commands ; aucune connexion Render → routeur n'est requise.
    -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'users' ? 'active' : '' ?>" href="?tab=users">
                <i class="bi bi-people"></i> Utilisateurs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'active' ? 'active' : '' ?>" href="?tab=active">
                <i class="bi bi-wifi"></i> Sessions actives
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'vouchers' ? 'active' : '' ?>" href="?tab=vouchers">
                <i class="bi bi-ticket-perforated"></i> Vouchers
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'profiles' ? 'active' : '' ?>" href="?tab=profiles">
                <i class="bi bi-pie-chart"></i> Profils
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <?php
        switch ($activeTab) {
            case 'active':
                include __DIR__ . '/active-users.php';
                break;
            case 'vouchers':
                include __DIR__ . '/vouchers.php';
                break;
            case 'profiles':
                include __DIR__ . '/profiles.php';
                break;
            default:
                include __DIR__ . '/users.php';
        }
        ?>
    </div>
</div>

</body>
</html>