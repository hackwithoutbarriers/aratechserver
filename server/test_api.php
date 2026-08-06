<?php
require __DIR__ . '/server/RouterosAPI.php';
$config = require __DIR__ . '/server/config.php';

$api = new RouterosAPI();
$connected = $api->connect(
    $config['mikrotik']['host'],
    $config['mikrotik']['api_user'],
    $config['mikrotik']['api_password'],
    $config['mikrotik']['api_port']
);

if ($connected) {
    echo "✅ Connexion API réussie !<br>";
    $profiles = $api->comm('/ip/hotspot/user/profile/print');
    echo "Profils disponibles :<br>";
    foreach ($profiles as $p) {
        if (isset($p['name'])) {
            echo " - " . $p['name'] . "<br>";
        }
    }
    $api->disconnect();
} else {
    echo "❌ Échec de connexion. Vérifiez les identifiants, l'IP et le firewall.<br>";
}
