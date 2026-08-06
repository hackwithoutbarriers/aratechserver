<?php
require __DIR__ . '/RouterosAPI.php';
$config = require __DIR__ . '/config.php';

$api = new RouterosAPI();
$api->debug = true; // active les logs pour voir ce qui se passe

$connected = $api->connect(
    $config['mikrotik']['host'],
    $config['mikrotik']['api_user'],
    $config['mikrotik']['api_password'],
    $config['mikrotik']['api_port']
);

if ($connected) {
    echo "✅ Connexion API réussie !\n";
    $profiles = $api->comm('/ip/hotspot/user/profile/print');
    echo "Profils disponibles :\n";
    foreach ($profiles as $p) {
        if (isset($p['name'])) {
            echo " - " . $p['name'] . "\n";
        }
    }
    $api->disconnect();
} else {
    echo "❌ Échec de connexion. Vérifiez les identifiants, l'IP et le firewall.\n";
}