<?php
require __DIR__ . '/db.php';
require __DIR__ . '/lib/RouterosAPI.php';
$config = require __DIR__ . '/config.php';

$api = new RouterosAPI();
$api->timeout = 10;
$api->attempts = 1;
$port = $config['mikrotik']['api_port'] ?? 8728;
$host = $config['mikrotik']['host'];
$user = $config['mikrotik']['api_user'];
$pass = $config['mikrotik']['api_password'];

echo "Tentative de connexion à $host:$port...\n";
$connected = $api->connect($host, $user, $pass, $port);
if ($connected) {
    echo "Connexion réussie !\n";
    $system = $api->comm('/system/resource/print');
    echo "Ressources : " . json_encode($system, JSON_PRETTY_PRINT) . "\n";
    $api->disconnect();
} else {
    echo "Échec de connexion.\n";
    $lastError = error_get_last();
    echo "Erreur PHP : " . ($lastError['message'] ?? 'inconnue') . "\n";
}
