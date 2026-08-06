<?php
// server/test_api.php

require __DIR__ . '/RouterosAPI.php';
$config = require __DIR__ . '/config.php';

// Activer l'affichage des erreurs pour le diagnostic
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h2>Test de connexion API MikroTik</h2>";

$api = new RouterosAPI();
$api->debug = true;

$connected = $api->connect(
    $config['mikrotik']['host'],
    $config['mikrotik']['api_user'],
    $config['mikrotik']['api_password'],
    $config['mikrotik']['api_port']
);

if ($connected) {
    echo "<p style='color:green;'>✅ Connexion API réussie !</p>";
    $profiles = $api->comm('/ip/hotspot/user/profile/print');
    echo "<p>Profils disponibles :</p><ul>";
    foreach ($profiles as $p) {
        if (isset($p['name'])) {
            echo "<li>" . $p['name'] . "</li>";
        }
    }
    echo "</ul>";
    $api->disconnect();
} else {
    echo "<p style='color:red;'>❌ Échec de connexion.</p>";
    echo "<p>Vérifiez :</p>";
    echo "<ul>";
    echo "<li>L'IP du routeur : " . $config['mikrotik']['host'] . "</li>";
    echo "<li>Le port : " . $config['mikrotik']['api_port'] . "</li>";
    echo "<li>Les identifiants : " . $config['mikrotik']['api_user'] . "</li>";
    echo "<li>Le pare-feu du routeur (port 8728 ouvert pour l'IP de Render)</li>";
    echo "</ul>";
}
