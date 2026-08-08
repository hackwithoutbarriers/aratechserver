<?php
declare(strict_types=1);
require_once __DIR__ . '/RouterosAPI.php';

/**
 * Couche d'abstraction Hotspot – ARA Tech WiFi
 * Utilise RouterosAPI pour interagir avec le routeur MikroTik.
 */
class Hotspot
{
    private RouterosAPI $api;
    private bool $connected = false;

    public function __construct(array $mikrotikConfig)
    {
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
    // Profils
    // ---------------------------------------------------------------------

    /**
     * Retourne tous les profils hotspot.
     */
    public function getProfiles(): array
    {
        return $this->api->comm('/ip/hotspot/user/profile/print');
    }

    /**
     * Retourne un profil par son .id ou son nom.
     */
    public function getProfile(string $idOrName): ?array
    {
        $profiles = $this->api->comm('/ip/hotspot/user/profile/print', ['?.id' => $idOrName]);
        if (!empty($profiles)) return $profiles[0];

        $profiles = $this->api->comm('/ip/hotspot/user/profile/print', ['?name' => $idOrName]);
        return !empty($profiles) ? $profiles[0] : null;
    }

    /**
     * Ajoute un nouveau profil.
     */
    public function addProfile(array $params): string
    {
        $this->api->comm('/ip/hotspot/user/profile/add', $params);
        // Récupérer l'ID du profil créé
        $newProf = $this->api->comm('/ip/hotspot/user/profile/print', ['?name' => $params['name']]);
        return $newProf[0]['.id'] ?? '';
    }

    /**
     * Modifie un profil existant.
     */
    public function setProfile(string $id, array $params): void
    {
        $params['.id'] = $id;
        $this->api->comm('/ip/hotspot/user/profile/set', $params);
    }

    /**
     * Supprime un profil.
     */
    public function removeProfile(string $id): void
    {
        $this->api->comm('/ip/hotspot/user/profile/remove', ['.id' => $id]);
    }

    /**
     * Retourne le scheduler de monitoring associé à un profil (par nom).
     */
    public function getProfileScheduler(string $profileName): ?array
    {
        $schedulers = $this->api->comm('/system/scheduler/print', ['?name' => $profileName]);
        return !empty($schedulers) ? $schedulers[0] : null;
    }

    /**
     * Crée ou met à jour le scheduler d'expiration pour un profil.
     */
    public function setProfileScheduler(string $name, string $onEvent, bool $disabled = false): void
    {
        $existing = $this->getProfileScheduler($name);
        $randStart = '0' . rand(1,5) . ':' . rand(10,59) . ':' . rand(10,59);
        $randInterval = '00:02:' . rand(10,59);

        if ($existing) {
            $this->api->comm('/system/scheduler/set', [
                '.id' => $existing['.id'],
                'name' => $name,
                'start-time' => $randStart,
                'interval' => $randInterval,
                'on-event' => $onEvent,
                'disabled' => $disabled ? 'yes' : 'no',
                'comment' => 'Monitor Profile ' . $name,
            ]);
        } else {
            $this->api->comm('/system/scheduler/add', [
                'name' => $name,
                'start-time' => $randStart,
                'interval' => $randInterval,
                'on-event' => $onEvent,
                'disabled' => $disabled ? 'yes' : 'no',
                'comment' => 'Monitor Profile ' . $name,
            ]);
        }
    }

    /**
     * Supprime le scheduler d'un profil.
     */
    public function removeProfileScheduler(string $name): void
    {
        $existing = $this->getProfileScheduler($name);
        if ($existing) {
            $this->api->comm('/system/scheduler/remove', ['.id' => $existing['.id']]);
        }
    }

    // ---------------------------------------------------------------------
    // Utilisateurs
    // ---------------------------------------------------------------------

    /**
     * Retourne les utilisateurs hotspot (avec filtres optionnels).
     */
    public function getUsers(array $filters = []): array
    {
        $query = [];
        if (!empty($filters['profile'])) {
            $query['?profile'] = $filters['profile'];
        }
        if (!empty($filters['comment'])) {
            $query['?comment'] = $filters['comment'];
        }
        if (!empty($filters['disabled'])) {
            $query['?disabled'] = $filters['disabled'];
        }
        return $this->api->comm('/ip/hotspot/user/print', $query);
    }

    /**
     * Retourne un utilisateur par son .id.
     */
    public function getUser(string $id): ?array
    {
        $users = $this->api->comm('/ip/hotspot/user/print', ['.id' => $id]);
        return !empty($users) ? $users[0] : null;
    }

    /**
     * Ajoute un utilisateur hotspot.
     */
    public function addUser(array $params): void
    {
        $this->api->comm('/ip/hotspot/user/add', $params);
    }

    /**
     * Modifie un utilisateur.
     */
    public function setUser(string $id, array $params): void
    {
        $params['.id'] = $id;
        $this->api->comm('/ip/hotspot/user/set', $params);
    }

    /**
     * Supprime un utilisateur.
     */
    public function removeUser(string $id): void
    {
        $this->api->comm('/ip/hotspot/user/remove', ['.id' => $id]);
    }

    /**
     * Active un utilisateur.
     */
    public function enableUser(string $id): void
    {
        $this->api->comm('/ip/hotspot/user/enable', ['.id' => $id]);
    }

    /**
     * Désactive un utilisateur.
     */
    public function disableUser(string $id): void
    {
        $this->api->comm('/ip/hotspot/user/disable', ['.id' => $id]);
    }

    // ---------------------------------------------------------------------
    // Sessions actives
    // ---------------------------------------------------------------------

    /**
     * Retourne les sessions actives (tous les serveurs ou filtré par serveur).
     */
    public function getActiveSessions(?string $server = null): array
    {
        $query = [];
        if ($server !== null) {
            $query['?server'] = $server;
        }
        return $this->api->comm('/ip/hotspot/active/print', $query);
    }

    /**
     * Supprime une session active.
     */
    public function removeActiveSession(string $id): void
    {
        $this->api->comm('/ip/hotspot/active/remove', ['.id' => $id]);
    }

    // ---------------------------------------------------------------------
    // Utilitaires
    // ---------------------------------------------------------------------

    /**
     * Retourne le nom du routeur.
     */
    public function getRouterIdentity(): string
    {
        $identity = $this->api->comm('/system/identity/print');
        return $identity[0]['name'] ?? 'MikroTik';
    }

    /**
     * Retourne la liste des pools d'adresses IP.
     */
    public function getIpPools(): array
    {
        return $this->api->comm('/ip/pool/print');
    }

    /**
     * Retourne les queues simples non dynamiques (pour parent queue).
     */
    public function getStaticQueues(): array
    {
        return $this->api->comm('/queue/simple/print', ['?dynamic' => 'false']);
    }

    /**
     * Construit le script on-login pour un profil donné.
     * (Version simplifiée compatible ARA Tech)
     */
    public function buildOnLoginScript(array $profileParams): string
    {
        $name       = $profileParams['name'] ?? 'default';
        $expmode    = $profileParams['expmode'] ?? 'rem';
        $price      = $profileParams['price'] ?? '0';
        $sprice     = $profileParams['sprice'] ?? '0';
        $validity   = $profileParams['validity'] ?? '1d';
        $lock       = ($profileParams['lock'] ?? 'Disable') === 'Enable' ? 'Enable' : 'Disable';

        // Script de base qui écrit la date d'expiration dans le commentaire
        $onlogin = ':put (",'.$expmode.',' . $price . ',' . $validity . ','.$sprice.',,' . $lock . ',"); ';
        $onlogin .= '{ :local comment [ /ip hotspot user get [/ip hotspot user find where name="$user"] comment];';
        $onlogin .= ' :local ucode [:pick $comment 0 2];';
        $onlogin .= ' :if ($ucode = "vc" or $ucode = "up" or $comment = "") do={';
        $onlogin .= ' :local date [ /system clock get date ];';
        $onlogin .= ' :local time [ /system clock get time ];';
        $onlogin .= ' /sys sch add name="$user" disable=no start-date=$date interval="'.$validity.'";';
        $onlogin .= ' :delay 5s;';
        $onlogin .= ' :local exp [ /sys sch get [ /sys sch find where name="$user" ] next-run];';
        $onlogin .= ' :delay 5s;';
        $onlogin .= ' /sys sch remove [find where name="$user"];';
        $onlogin .= ' /ip hotspot user set comment="$exp" [find where name="$user"];';

        // Lock user si demandé
        if ($lock === 'Enable') {
            $onlogin .= ' :local mac $"mac-address"; /ip hotspot user set mac-address=$mac [find where name=$user];';
        }

        $onlogin .= ' }}';

        return $onlogin;
    }

    /**
     * Construit le script de monitoring (bgservice) pour un profil.
     */
    public function buildBackgroundServiceScript(string $profileName, string $expMode): string
    {
        $mode = ($expMode === 'rem' || $expMode === 'remc') ? 'remove' : 'set limit-uptime=1s';

        $script = ':local dateint do={:local year [:pick $d 0 4];:local month [:pick $d 5 7];:local day [:pick $d 8 10];:return ($year * 10000 + $month * 100 + $day);};';
        $script .= ' :local timeint do={:local hours [:pick $t 0 2];:local minutes [:pick $t 3 5];:local seconds [:pick $t 6 8];:return ($hours * 10000 + $minutes * 100 + $seconds);};';
        $script .= ' :local date [ /system clock get date ];';
        $script .= ' :local time [ /system clock get time ];';
        $script .= ' :local today [$dateint d=$date] ;';
        $script .= ' :local curtime [$timeint t=$time] ;';
        $script .= ' :foreach i in [ /ip hotspot user find where profile="'.$profileName.'" ] do={';
        $script .= ' :local comment [ /ip hotspot user get $i comment];';
        $script .= ' :local name [ /ip hotspot user get $i name];';
        $script .= ' :if ([:pick $comment 0 3] = "vc-" or [:pick $comment 0 3] = "up-") do={';
        $script .= ' :local expstr [:pick $comment 3];';
        $script .= ' :local expdate [:pick $expstr 0 10];';
        $script .= ' :local exptime [:pick $expstr 11 19];';
        $script .= ' :local expd [$dateint d=$expdate] ;';
        $script .= ' :local expt [$timeint t=$exptime] ;';
        $script .= ' :if (($expd < $today) or ($expd = $today and $expt < $curtime)) do={';
        $script .= ' [ /ip hotspot user ' . $mode . ' $i ];';
        $script .= ' [ /ip hotspot active remove [find where user=$name] ];';
        $script .= ' }}}';

        return $script;
    }
}
