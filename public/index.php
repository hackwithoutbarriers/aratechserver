<?php
// Page d'accueil publique du réseau ARA Tech WiFi Zone
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ARA Tech – WiFi Zone</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --bleu-nuit: #0b2c82;
            --orange: #f5a623;
        }
        body {
            background: linear-gradient(135deg, var(--bleu-nuit) 0%, #1a3f8f 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            font-family: 'Segoe UI', sans-serif;
            color: #fff;
            text-align: center;
            padding: 2rem;
        }
        .logo {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            letter-spacing: 1px;
        }
        .slogan {
            font-size: 1.5rem;
            opacity: 0.9;
            margin-bottom: 2rem;
        }
        .features {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 2rem;
            max-width: 800px;
            margin-bottom: 2.5rem;
        }
        .feature-card {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 1.5rem;
            width: 220px;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.2);
        }
        .feature-card i {
            font-size: 2.5rem;
            color: var(--orange);
            margin-bottom: 0.8rem;
        }
        .feature-card h5 {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .feature-card p {
            font-size: 0.9rem;
            opacity: 0.8;
            margin-bottom: 0;
        }
        .btn-connect {
            background: var(--orange);
            border: none;
            color: #fff;
            font-weight: 700;
            padding: 0.8rem 2.5rem;
            border-radius: 30px;
            font-size: 1.1rem;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 1rem;
        }
        .btn-connect:hover {
            background: #e5941f;
            transform: scale(1.03);
            color: #fff;
        }
        .admin-link {
            color: rgba(255,255,255,0.5);
            text-decoration: none;
            font-size: 0.85rem;
        }
        .admin-link:hover {
            color: rgba(255,255,255,0.8);
        }
        footer {
            position: fixed;
            bottom: 1rem;
            font-size: 0.8rem;
            opacity: 0.6;
        }
    </style>
</head>
<body>
    <div class="logo">⚡ ARA Tech WiFi</div>
    <div class="slogan">Connectez-vous à l'Internet rapide et fiable</div>

    <div class="features">
        <div class="feature-card">
            <i class="bi bi-speedometer2"></i>
            <h5>Haut débit</h5>
            <p>Profitez d'une connexion stable et rapide pour tous vos usages.</p>
        </div>
        <div class="feature-card">
            <i class="bi bi-shield-check"></i>
            <h5>Sécurisé</h5>
            <p>Navigation protégée et données personnelles respectées.</p>
        </div>
        <div class="feature-card">
            <i class="bi bi-headset"></i>
            <h5>Support 24/7</h5>
            <p>Une équipe réactive pour vous accompagner à tout moment.</p>
        </div>
    </div>

    <!-- Le bouton peut rediriger vers le portail captif du routeur si disponible,
         ou simplement afficher un message. Ici, il mène vers le statut public (si désiré). -->
    <a href="admin/status.php" class="btn-connect">
        <i class="bi bi-wifi"></i> Voir l'état du réseau
    </a>

    <div>
<a href="admin/dashboard/" class="admin-link">Accès administration</a>
    </div>

    <footer>
        &copy; <?= date('Y') ?> ARA Tech – WiFi Zone. Tous droits réservés.
    </footer>
</body>
</html>
