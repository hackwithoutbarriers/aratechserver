# ARA Tech Wi‑Fi Zone — Portail Captif

> Portail captif (Captive Portal) pour hotspot MikroTik avec gestion de vouchers, affichage de promotions, et suivi de connexion.

---

## 📌 Table des matières

1. [Présentation](#-présentation)
2. [Architecture](#-architecture)
3. [Prérequis](#-prérequis)
4. [Installation](#-installation)
5. [Configuration](#-configuration)
6. [Fonctionnalités](#-fonctionnalités)
7. [API Backend](#-api-backend)
8. [Utilisation](#-utilisation)
9. [Dépannage](#-dépannage)
10. [Maintenance](#-maintenance)
11. [Contribuer](#-contribuer)
12. [Licence](#-licence)

---

## 🎯 Présentation

ARA Tech Wi‑Fi Zone est un **portail captif** professionnel pour hotspot MikroTik. Il offre :

- ✅ Interface utilisateur moderne et responsive
- ✅ Affichage de promotions et produits (carrousel et grille)
- ✅ Connexion par voucher ou compte membre
- ✅ Affichage de l’expiration du ticket sur la page de statut
- ✅ Suivi des clics/vues sur les annonces
- ✅ Administration simple des annonces

---

## 🏗 Architecture

```
┌─────────────────────────────────────────────────────┐
│                    MikroTik Router                   │
│  ┌────────────────────────────────────────────────┐  │
│  │   Pages HTML/CSS/JS (login, status, logout)   │  │
│  └────────────────────────────────────────────────┘  │
│                      │                               │
│                      │ API REST (local)              │
│                      ▼                               │
│  ┌────────────────────────────────────────────────┐  │
│  │   Backend (Render / serveur PHP externe)      │  │
│  │   - api.php (ads, expiry, track, admin)       │  │
│  │   - db.php (SQLite)                           │  │
│  │   - RouterosAPI.php (connexion RouterOS)     │  │
│  └────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────┘
```

- Les pages HTML sont hébergées sur le **MikroTik** (hotspot local).
- Le backend PHP est hébergé sur **Render** (ou tout serveur PHP).
- La communication est bidirectionnelle (quand elle est configurée).

---

## 📋 Prérequis

### Matériel et logiciels

- **MikroTik RouterOS** 6.43+ (avec service Hotspot activé)
- **Serveur PHP** 7.4+ (avec `pdo_sqlite` et `curl`)
- **Accès au routeur** (Winbox, SSH, ou API)

### Fichiers à avoir

```
/
├── login.html              # Page de connexion
├── status.html             # Page de statut utilisateur
├── logout.html             # Page de déconnexion
├── success.html            # Page de connexion réussie
├── style.css               # Feuille de style principale
├── ads.json                # Données des annonces (fallback)
├── img/                    # Dossier des images
│   ├── logo.png
│   ├── special-offer.png
│   ├── router.png
│   └── ...
└── server/                 # Backend PHP (sur Render)
    ├── api.php
    ├── db.php
    ├── RouterosAPI.php
    ├── config.php
    └── data/               # Base SQLite
        └── transactions.sqlite
```

---

## 🔧 Installation

### 1. Déployer les pages HTML sur le MikroTik

Les pages HTML (`login.html`, `status.html`, `logout.html`, `success.html`) doivent être placées dans le **dossier Hotspot** du routeur. Par défaut, il se trouve dans :

```
/hotspot/
```

Vous pouvez les uploader via Winbox (Files) ou via FTP.

### 2. Déployer le backend sur Render

1. Clonez le dépôt sur votre serveur :
   ```bash
   git clone https://github.com/votre-repo/ara-tech-backend.git
   ```

2. Assurez-vous que le dossier `data/` est accessible en écriture :
   ```bash
   chmod 775 data/
   ```

3. Configurez votre service Render pour pointer vers le dossier `server/` comme racine.

### 3. Configurer les variables d’environnement (ou `config.php`)

Copiez `config.example.php` vers `config.php` et renseignez les valeurs :

- **MikroTik** : IP, utilisateur API, mot de passe, port
- **Admin token** : un token secret pour protéger les routes admin
- **CORS** : l’origine autorisée (ex: `http://10.10.0.1`)

---

## ⚙️ Configuration

### 1. Sur le MikroTik (obligatoire)

#### Activer le service API

```routeros
/ip service enable api
```

#### Créer un utilisateur API dédié

```routeros
/user add name=api-hotspot group=write password=MonMotDePasse
```

#### Autoriser l’accès depuis le réseau de gestion

```routeros
/ip firewall filter add chain=input protocol=tcp src-address=192.168.88.0/24 dst-port=8728 action=accept
```

### 2. Dans `config.php`

```php
'mikrotik' => [
    'host'         => '192.168.88.1',   // IP du routeur
    'api_user'     => 'api-hotspot',
    'api_password' => 'MonMotDePasse',
    'api_port'     => 8728,
],
'admin' => [
    'token' => 'mon_token_super_secret',
],
'allowed_origin' => 'http://10.10.0.1',
```

---

## ✨ Fonctionnalités

### Pages HTML

| Page | Rôle |
|------|------|
| `login.html` | Connexion par voucher ou membre, carrousel de promotions, grille produits |
| `status.html` | Informations de session + affichage de l’expiration du ticket |
| `logout.html` | Déconnexion et récapitulatif de session |
| `success.html` | Confirmation de connexion |

### Backend (`api.php`)

| Route | Méthode | Description |
|-------|---------|-------------|
| `?route=ads` | GET | Retourne la liste des annonces actives (carrousel + produits) |
| `?route=expiry&user=XXX` | GET | Retourne la date d’expiration d’un utilisateur (depuis le commentaire RouterOS) |
| `?route=track` | POST | Enregistre un clic ou une vue sur une annonce |
| `?route=admin&token=XXX` | GET | Interface admin simple (liste des annonces) |
| `?route=admin_save_ad` | POST | Ajoute ou met à jour une annonce |
| `?route=admin_delete_ad` | POST | Supprime une annonce |

---

## 📡 API Backend

### GET /api.php?route=ads

Retourne les annonces actives.

```json
{
  "success": true,
  "items": [
    {
      "id": "ad-001",
      "type": "sponsored",
      "title": "Offre de Lancement",
      "description": "10% de réduction",
      "image": "img/special-offer.png",
      "url": "https://wa.me/22892709708",
      "start": "2026-07-30",
      "end": "2026-08-07",
      "active": 1,
      "price": null,
      "views": 0,
      "clicks": 0
    }
  ]
}
```

### GET /api.php?route=expiry&user=XXX

Retourne l’expiration du ticket (depuis le commentaire de l’utilisateur).

```json
{
  "success": true,
  "expiry": "2026-08-10 14:30:00"
}
```

### POST /api.php?route=track

```json
{
  "id": "ad-001",
  "type": "click",
  "user": "anonymous"
}
```

---

## 🖥 Utilisation

### Connexion d’un utilisateur

1. L’utilisateur se connecte au Wi‑Fi (SSID du hotspot).
2. Le portail captif s’ouvre automatiquement sur `login.html`.
3. Il saisit son **code voucher** ou ses identifiants.
4. S’il est valide, il est redirigé vers `success.html`.
5. Il peut consulter son statut via le bouton "Voir mon statut".

### Administration des annonces

- **Ajouter** : `POST /api.php?route=admin_save_ad&token=mon_token` avec les données JSON.
- **Supprimer** : `POST /api.php?route=admin_delete_ad&token=mon_token` avec `{ "id": "ad-001" }`.

---

## 🧪 Dépannage

### Problème : la page de statut n’affiche pas l’expiration

- Vérifiez que le script `on-login` des profils hotspot met bien une date dans le commentaire.
- Vérifiez que l’API REST du routeur est accessible depuis le réseau du hotspot (`/rest/ip/hotspot/user/print`).
- Si ce n’est pas le cas, la page utilise le temps restant (`$(session-time-left)`) pour estimer l’expiration.

### Problème : l’API backend ne répond pas

- Vérifiez que `config.php` est bien présent et correctement configuré.
- Vérifiez que le dossier `data/` existe et est accessible en écriture.
- Consultez les logs : `data/app.log`.

### Problème : connexion à l’API RouterOS impossible

- Testez avec la classe `RouterosAPI` en debug (`$api->debug = true;`).
- Vérifiez que le service API est activé (`/ip service enable api`).
- Vérifiez les règles de pare‑feu (port 8728).

---

## 🔄 Maintenance

### Nettoyage automatique

Les transactions expirées sont automatiquement supprimées après 90 jours (configurable dans `config.php`).

### Rotation des logs

Les logs sont automatiquement archivés lorsqu’ils dépassent 10 Mo.

### Mise à jour des annonces

- Soit via l’API admin (`admin_save_ad`, `admin_delete_ad`)
- Soit en modifiant le fichier `ads.json` et en réinitialisant avec `admin_reseed_ads`

---

## 🤝 Contribuer

Les contributions sont les bienvenues !  
Merci de soumettre une **pull request** ou d’ouvrir une **issue** pour toute suggestion ou correction.

---

## 📄 Licence

Ce projet est sous licence **MIT** — voir le fichier [LICENSE](LICENSE) pour plus de détails.

---

## 📧 Contact

- **ARA Tech** — [Site web](https://aratech.tg) (à venir)
- **Support** : [WhatsApp](https://wa.me/22892709708)

---

**Fait avec ❤️ pour la communauté Wi‑Fi au Togo.**
