<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/RouterosAPI.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . $config['allowed_origin']);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$route = trim((string)($_GET['route'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// fonctions json_response, json_error, etc. (identiques à votre code)

switch ($route) {
    case 'ads':
        // ... votre code existant
        break;
    case 'loyalty':
        // ... votre code existant
        break;
    case 'track':
        // ... votre code existant
        break;
    case 'admin':
        // ... votre code existant
        break;
    case 'admin_save_ad':
        // ... votre code existant
        break;
    case 'admin_delete_ad':
        // ... votre code existant
        break;
    case 'admin_reseed_ads':
        // ... votre code existant
        break;

    // NOUVELLE ROUTE
    case 'expiry':
        $user = trim((string)($_GET['user'] ?? ''));
        if ($user === '') json_error('Paramètre user manquant.');
        try {
            $api = new RouterosAPI();
            $connectOk = false;
            for ($i=1; $i<=$config['mikrotik']['connect_retries']; $i++) {
                if ($api->connect(
                    $config['mikrotik']['host'],
                    $config['mikrotik']['api_user'],
                    $config['mikrotik']['api_password'],
                    $config['mikrotik']['api_port']
                )) { $connectOk = true; break; }
                sleep($i);
            }
            if (!$connectOk) json_error('Connexion au routeur impossible.', 502);
            $getUser = $api->comm('/ip/hotspot/user/print', ['?name' => $user]);
            $api->disconnect();
            $expiry = '';
            if (isset($getUser[0]['comment'])) {
                preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $getUser[0]['comment'], $matches);
                $expiry = $matches[1] ?? '';
            }
            json_response(['success'=>true, 'expiry'=>$expiry]);
        } catch (Throwable $e) {
            error_log('[ARA Tech][api.php] Expiry error: ' . $e->getMessage());
            json_error('Erreur interne.', 500);
        }
        break;

    default:
        json_error('Route inconnue.', 404);
}
