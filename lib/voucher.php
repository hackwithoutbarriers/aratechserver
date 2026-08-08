<?php
declare(strict_types=1);

/**
 * Couche Voucher – ARA Tech WiFi
 * Centralise la récupération et la mise en forme des données
 * nécessaires à l'impression des tickets (vouchers).
 */
class Voucher
{
    private RouterosAPI $api;
    private bool $connected;
    private array $config;

    // Cache local
    private ?string $hotspotName = null;
    private ?string $dnsName = null;

    public function __construct(array $mikrotikConfig)
    {
        $this->config = $mikrotikConfig;
        $this->api = new RouterosAPI();
        $this->api->timeout = $mikrotikConfig['connect_timeout'] ?? 2;
        $this->api->attempts = $mikrotikConfig['connect_retries'] ?? 1;

        $this->connected = $this->api->connect(
            $mikrotikConfig['host'],
            $mikrotikConfig['api_user'],
            $mikrotikConfig['api_password'],
            (int)($mikrotikConfig['api_port'] ?? 8728)
        );
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function disconnect(): void
    {
        if ($this->connected) {
            $this->api->disconnect();
            $this->connected = false;
        }
    }

    // ---------------------------------------------------------------------
    // Informations sur le hotspot
    // ---------------------------------------------------------------------

    public function getHotspotName(): string
    {
        if ($this->hotspotName === null) {
            $identity = $this->api->comm('/system/identity/print');
            $this->hotspotName = $identity[0]['name'] ?? 'ARA Tech WiFi';
        }
        return $this->hotspotName;
    }

    public function getDnsName(): string
    {
        if ($this->dnsName === null) {
            $servers = $this->api->comm('/ip/hotspot/print');
            $this->dnsName = $servers[0]['dns-name'] ?? '';
            if (empty($this->dnsName)) {
                $this->dnsName = 'wifi.aratech.local'; // fallback
            }
        }
        return $this->dnsName;
    }

    // ---------------------------------------------------------------------
    // Récupération des utilisateurs
    // ---------------------------------------------------------------------

    /**
     * Récupère les utilisateurs correspondant à un commentaire
     * (ex: vc-xxxx, up-xxxx) et qui n'ont pas encore été utilisés (uptime = 0s).
     */
    public function getUsersByComment(string $comment): array
    {
        return $this->api->comm('/ip/hotspot/user/print', [
            '?comment' => $comment,
            '?uptime' => '0s',
        ]);
    }

    /**
     * Récupère un utilisateur par son nom (mode user=vc-xxx ou user=up-xxx).
     */
    public function getUsersByUsername(string $userParam): array
    {
        // Extraction du nom réel
        $parts = explode('-', $userParam);
        $mode = $parts[0];
        $username = end($parts);
        if (count($parts) == 3) {
            $username = $parts[1] . '-' . $parts[2];
        }

        return $this->api->comm('/ip/hotspot/user/print', ['?name' => $username]);
    }

    /**
     * Retourne un utilisateur unique ou null.
     */
    public function getSingleUser(string $userParam): ?array
    {
        $users = $this->getUsersByUsername($userParam);
        return !empty($users) ? $users[0] : null;
    }

    // ---------------------------------------------------------------------
    // Détails du profil
    // ---------------------------------------------------------------------

    /**
     * Retourne les informations du profil d'un utilisateur (valable pour tout le lot).
     */
    public function getProfileForUser(array $user): ?array
    {
        $profileName = $user['profile'] ?? '';
        if (empty($profileName)) return null;

        $profiles = $this->api->comm('/ip/hotspot/user/profile/print', [
            '?name' => $profileName,
        ]);
        return !empty($profiles) ? $profiles[0] : null;
    }

    // ---------------------------------------------------------------------
    // Formatage des données du voucher
    // ---------------------------------------------------------------------

    /**
     * Prépare les données d'un ticket utilisateur.
     */
    public function prepareVoucherData(array $user, ?array $profile = null): array
    {
        $username   = $user['name'] ?? '';
        $password   = $user['password'] ?? '';
        $comment    = $user['comment'] ?? '';
        $timelimit  = $user['limit-uptime'] ?? '';
        $datalimitBytes = $user['limit-bytes-total'] ?? 0;

        // Déterminer le mode (vc = voucher code, up = username/password)
        $ucode = substr($comment, 0, 2);
        $mode = ($ucode === 'vc' || $ucode === 'up') ? $ucode : 'up';

        // Extraire validité et prix depuis le profil
        $validity = '';
        $priceText = '';
        if ($profile) {
            $onLogin = $profile['on-login'] ?? '';
            $parts = explode(',', $onLogin);
            $validity = $parts[3] ?? '';
            $price = $parts[2] ?? '0';
            $sprice = $parts[4] ?? '0';
            // Choix du prix à afficher (priorité au prix de vente)
            $displayPrice = ($sprice != '0' && $sprice != '') ? $sprice : $price;
            if ($displayPrice != '0' && $displayPrice != '') {
                $priceText = number_format((float)$displayPrice, 0, ',', ' ') . ' FCFA';
            }
        }

        // Limite de données
        $datalimit = $datalimitBytes > 0 ? formatBytes($datalimitBytes, 2) : '';

        // URL de connexion pour QR code
        $dns = $this->getDnsName();
        $loginUrl = "http://$dns/login?username=$username&password=$password";

        // Identifiant unique pour le QR code (base64 de .id)
        $uid = str_replace('=', '', base64_encode($user['.id'] ?? ''));

        return [
            'username'   => $username,
            'password'   => $password,
            'profile'    => $profile['name'] ?? ($user['profile'] ?? ''),
            'mode'       => $mode,
            'validity'   => $validity,
            'timelimit'  => $timelimit,
            'datalimit'  => $datalimit,
            'price'      => $priceText,
            'loginUrl'   => $loginUrl,
            'uid'        => $uid,
            'comment'    => $comment,
        ];
    }
}

// Helper de formatage (sera déplacé dans lib/format.php à terme)
if (!function_exists('formatBytes')) {
    function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), $precision) . ' ' . $units[$i];
    }
}
