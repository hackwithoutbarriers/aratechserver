<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
$config = require __DIR__ . '/../config.php';
$pageTitle = 'Hotspot - ARA Tech WiFi';
$activeTab = $_GET['tab'] ?? 'users'; // onglet actif

require __DIR__ . '/header.php';
?>

<div class="container-fluid mt-4">
    <h2 class="mb-3">📡 Gestion du Hotspot</h2>

    <!-- Navigation par onglets -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'users' ? 'active' : '' ?>" href="?tab=users">
                <i class="bi bi-people"></i> Utilisateurs
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'profiles' ? 'active' : '' ?>" href="?tab=profiles">
                <i class="bi bi-pie-chart"></i> Profils
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'active' ? 'active' : '' ?>" href="?tab=active">
                <i class="bi bi-wifi"></i> Sessions actives
            </a>
        </li>
    </ul>

    <!-- Contenu de l'onglet -->
    <div class="tab-content">
        <?php
        // Inclusion du fichier correspondant, qui contient déjà toute sa logique
        switch ($activeTab) {
            case 'profiles':
                include __DIR__ . '/profiles.php';
                break;
            case 'active':
                include __DIR__ . '/active-users.php';
                break;
            default:
                include __DIR__ . '/users.php';
        }
        ?>
    </div>
</div>

</body>
</html>
