<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../lib/RouterosAPI.php';
$config = require __DIR__ . '/../config.php';

// Récupération des paramètres
$id      = $_GET['id']    ?? '';
$qr      = $_GET['qr']    ?? 'yes';  // afficher QR code par défaut
$small   = $_GET['small'] ?? '';
$userp   = $_GET['user']  ?? '';     // ex: vc-xxx ou up-xxx

// Connexion au routeur
$API = new RouterosAPI();
$connected = $API->connect(
    $config['mikrotik']['host'],
    $config['mikrotik']['api_user'],
    $config['mikrotik']['api_password'],
    (int)$config['mikrotik']['api_port']
);
if (!$connected) {
    die('<div class="alert alert-danger">Impossible de se connecter au routeur.</div>');
}

// Récupération du nom du hotspot et du DNS (peut être mis en cache dans config)
$identity = $API->comm('/system/identity/print');
$hotspotname = $identity[0]['name'] ?? 'ARA Tech WiFi';

// Récupération du DNS name du hotspot (pour l'URL de connexion)
$dnsname = '';
$hotspotServers = $API->comm('/ip/hotspot/print');
if (!empty($hotspotServers)) {
    $dnsname = $hotspotServers[0]['dns-name'] ?? '';
}
if (empty($dnsname)) {
    $dnsname = 'wifi.aratech.local'; // fallback
}

// Récupération des utilisateurs concernés
$users = [];
if ($userp !== '') {
    // Format vc-xxx ou up-xxx-username
    $parts = explode('-', $userp);
    $mode = $parts[0];
    $username = end($parts);
    // Si plus de 3 segments, l'avant-dernier peut être un préfixe
    if (count($parts) == 3) {
        $username = $parts[1] . '-' . $parts[2];
    }
    $response = $API->comm('/ip/hotspot/user/print', ['?name' => $username]);
    $users = $response;
} elseif ($id !== '') {
    // $id correspond au commentaire (ex: vc-xxx)
    $response = $API->comm('/ip/hotspot/user/print', ['?comment' => $id, '?uptime' => '0s']);
    $users = $response;
}

// Si on a des utilisateurs, on récupère les infos du profil
$profile = null;
if (!empty($users)) {
    $profileName = $users[0]['profile'] ?? '';
    if ($profileName) {
        $profileResponse = $API->comm('/ip/hotspot/user/profile/print', ['?name' => $profileName]);
        $profile = $profileResponse[0] ?? null;
    }
}

// Extraire les infos depuis le profil (si disponible)
$validity = $profile ? (explode(',', $profile['on-login'] ?? '')[3] ?? '') : '';
$getprice = $profile ? (explode(',', $profile['on-login'] ?? '')[2] ?? '0') : '0';
$getsprice = $profile ? (explode(',', $profile['on-login'] ?? '')[4] ?? '0') : '0';

// Devise et formatage (on peut utiliser FCFA par défaut)
$currency = 'FCFA';
$price = '';
if ($getsprice != '0' && $getsprice != '') {
    $price = number_format((float)$getsprice, 0, ',', ' ') . ' ' . $currency;
} elseif ($getprice != '0' && $getprice != '') {
    $price = number_format((float)$getprice, 0, ',', ' ') . ' ' . $currency;
}

// Logo
$logo = "../img/logo.png"; // à personnaliser

// --- Affichage (impression) ---
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Vouchers - <?= htmlspecialchars($hotspotname) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../img/favicon.png">
    <script src="../js/qrious.min.js"></script>
    <style>
        body {
            color: #000;
            background: #fff;
            font-size: 14px;
            font-family: 'Helvetica', Arial, sans-serif;
            margin: 0;
            -webkit-print-color-adjust: exact;
        }
        table.voucher {
            display: inline-block;
            border: 2px solid black;
            margin: 2px;
        }
        @page {
            size: auto;
            margin: 7mm 3mm 9mm 3mm;
        }
        @media print {
            table { page-break-after: auto; }
            tr    { page-break-inside: avoid; }
        }
        .qrcode { height:80px; width:80px; }
        .no-print { margin: 10px; }
    </style>
</head>
<body onload="window.print()">

<?php foreach ($users as $i => $user): 
    $username   = $user['name'] ?? '';
    $password   = $user['password'] ?? '';
    $profile    = $user['profile'] ?? '';
    $timelimit  = $user['limit-uptime'] ?? '';
    $datalimitBytes = $user['limit-bytes-total'] ?? 0;
    $datalimit  = $datalimitBytes ? formatBytes($datalimitBytes, 2) : '';
    $comment    = $user['comment'] ?? '';
    $uid        = str_replace('=', '', base64_encode($user['.id'] ?? ''));
    $urilogin   = "http://$dnsname/login?username=$username&password=$password";
    $qrcode     = "<canvas class='qrcode' id='$uid'></canvas>
                    <script>
                    (function() {
                        new QRious({
                            element: document.getElementById('$uid'),
                            value: '$urilogin',
                            size: 256
                        });
                    })();
                    </script>";
    $num = $i + 1;

    // Déterminer le mode (vc ou up) en fonction du commentaire
    $ucode = substr($comment, 0, 2);
    $usermode = ($ucode === 'vc' || $ucode === 'up') ? $ucode : 'up';

    // Choix du template
    if ($userp !== '' && $small !== 'yes') {
        // Template thermal (utilisé pour un utilisateur spécifique)
        include __DIR__ . '/../vouchers/template-thermal.php';
    } elseif ($small === 'yes') {
        include __DIR__ . '/../vouchers/template-small.php';
    } else {
        include __DIR__ . '/../vouchers/template.php';
    }
endforeach; 
?>

<!-- Bouton pour revenir (avant impression) -->
<div class="no-print">
    <button onclick="window.print()" class="btn btn-primary">Imprimer</button>
    <a href="index.php" class="btn btn-secondary">Retour</a>
</div>
</body>
</html>
