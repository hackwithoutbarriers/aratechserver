<?php

declare(strict_types=1);

/**
 * src/Mikrotik/RouterosApiClient.php
 * -----------------------------------------------------------------------
 * Client bas niveau du protocole API RouterOS (port 8728/8729).
 *
 * Origine : adapté de routeros_api.class.php (Denis Basta et contributeurs,
 * déjà présent dans le dépôt mikhmon-server / mikhmon/lib/), renommé
 * `RouterosApiClient` et nettoyé conformément au rapport d'audit (§1 —
 * "la classe vendor, renommée/namespacée"). Le protocole bas niveau
 * (longueur encodée, lecture/écriture de trames, login pré/post v6.43)
 * est identique à l'original ; seul le style a été modernisé
 * (typage strict, suppression du code mort spécifique Smarty
 * `parseResponse4Smarty()` / `arrayChangeKeyName()`, non utilisé ici).
 *
 * Ce fichier ne connaît RIEN de Supabase, de la config de l'application
 * ni de la logique métier hotspot : c'est un client protocole générique,
 * exactement comme PDO l'est pour Postgres. La couche applicative vit
 * dans RouterosClient.php (factory + test de connexion) et, à une étape
 * ultérieure, HotspotService.php (logique métier hotspot).
 *
 * Pas de namespace PHP : le reste du dépôt (db.php, api.php, admin/*)
 * n'utilise ni namespaces ni autoloader Composer (aucun composer.json
 * dans le dépôt) — on reste cohérent avec ce style plutôt que d'introduire
 * une convention isolée pour ce seul fichier.
 * -----------------------------------------------------------------------
 */
class RouterosApiClient
{
    /** Affiche les échanges bas niveau sur stdout/error_log si activé. */
    public bool $debug = false;

    /** État courant de la connexion. */
    public bool $connected = false;

    /** Port API RouterOS (8728 en clair, 8729 en SSL/API-SSL). */
    public int $port = 8728;

    /** Connexion chiffrée (nécessite api-ssl activé côté routeur). */
    public bool $ssl = false;

    /** Timeout de connexion ET de lecture, en secondes. */
    public int $timeout = 3;

    /** Nombre de tentatives de connexion avant abandon. */
    public int $attempts = 2;

    /** Délai entre deux tentatives, en secondes. */
    public int $delay = 1;

    /** @var resource|closed-resource|null */
    private $socket;

    public int $error_no = 0;
    public string $error_str = '';

    /** Vérifie si une valeur peut être parcourue par foreach(). */
    public function isIterable($var): bool
    {
        return $var !== null
            && (is_array($var)
                || $var instanceof Traversable);
    }

    private function debugLog(string $text): void
    {
        if ($this->debug) {
            error_log('[RouterosApiClient] ' . $text);
        }
    }

    /**
     * Encode une longueur de trame selon le protocole API RouterOS
     * (varint sur 1 à 5 octets). Logique inchangée par rapport à
     * l'implémentation d'origine.
     */
    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x4000) {
            $length |= 0x8000;
            return chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        if ($length < 0x200000) {
            $length |= 0xC00000;
            return chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        if ($length < 0x10000000) {
            $length |= 0xE0000000;
            return chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF)
                . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        return chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF)
            . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
    }

    /**
     * Établit la connexion et s'authentifie auprès du routeur.
     * Gère le login pré-v6.43 (challenge MD5) et post-v6.43 (plain).
     */
    public function connect(string $ip, string $login, string $password): bool
    {
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $this->connected = false;
            $protocol = $this->ssl ? 'ssl://' : '';
            $context = stream_context_create([
                'ssl' => ['ciphers' => 'ADH:ALL', 'verify_peer' => false, 'verify_peer_name' => false],
            ]);

            $this->debugLog("Tentative #$attempt vers $protocol$ip:$this->port ...");

            $this->socket = @stream_socket_client(
                $protocol . $ip . ':' . $this->port,
                $this->error_no,
                $this->error_str,
                $this->timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if ($this->socket) {
                stream_set_timeout($this->socket, $this->timeout);

                $this->write('/login', false);
                $this->write('=name=' . $login, false);
                $this->write('=password=' . $password);
                $response = $this->read(false);

                if (isset($response[0]) && $response[0] === '!done') {
                    if (!isset($response[1])) {
                        // Login post-v6.43 : déjà authentifié.
                        $this->connected = true;
                        break;
                    }

                    // Login pré-v6.43 : challenge MD5.
                    $matches = [];
                    if (preg_match_all('/[^=]+/i', $response[1], $matches)
                        && ($matches[0][0] ?? '') === 'ret'
                        && strlen($matches[0][1] ?? '') === 32
                    ) {
                        $this->write('/login', false);
                        $this->write('=name=' . $login, false);
                        $this->write('=response=00' . md5(chr(0) . $password . pack('H*', $matches[0][1])));
                        $response = $this->read(false);
                        if (isset($response[0]) && $response[0] === '!done') {
                            $this->connected = true;
                            break;
                        }
                    }
                }

                fclose($this->socket);
            }

            if ($attempt < $this->attempts) {
                sleep($this->delay);
            }
        }

        $this->debugLog($this->connected ? 'Connecté.' : 'Échec de connexion : ' . $this->error_str);

        return $this->connected;
    }

    /** Ferme proprement la connexion socket si elle est encore ouverte. */
    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->connected = false;
        $this->debugLog('Déconnecté.');
    }

    /** Transforme la réponse brute (!re/!done/!trap...) en tableau exploitable. */
    public function parseResponse(array $response): array
    {
        $parsed = [];
        $current = null;
        $singleValue = null;

        foreach ($response as $x) {
            if (in_array($x, ['!fatal', '!re', '!trap'], true)) {
                if ($x === '!re') {
                    $parsed[] = [];
                    $current = &$parsed[array_key_last($parsed)];
                } else {
                    $parsed[$x][] = [];
                    $current = &$parsed[$x][array_key_last($parsed[$x])];
                }
            } elseif ($x !== '!done') {
                $matches = [];
                if (preg_match_all('/[^=]+/i', $x, $matches)) {
                    if (($matches[0][0] ?? '') === 'ret') {
                        $singleValue = $matches[0][1] ?? '';
                    }
                    $current[$matches[0][0]] = $matches[0][1] ?? '';
                }
            }
        }

        if (empty($parsed) && $singleValue !== null) {
            return [$singleValue];
        }

        return $parsed;
    }

    /** Lit une réponse complète depuis le socket (bloquant jusqu'à !done). */
    public function read(bool $parse = true): array
    {
        $response = [];
        $receivedDone = false;

        while (true) {
            $byte = ord(fread($this->socket, 1));
            $length = 0;

            if ($byte & 128) {
                if (($byte & 192) === 128) {
                    $length = (($byte & 63) << 8) + ord(fread($this->socket, 1));
                } elseif (($byte & 224) === 192) {
                    $length = (($byte & 31) << 8) + ord(fread($this->socket, 1));
                    $length = ($length << 8) + ord(fread($this->socket, 1));
                } elseif (($byte & 240) === 224) {
                    $length = (($byte & 15) << 8) + ord(fread($this->socket, 1));
                    $length = ($length << 8) + ord(fread($this->socket, 1));
                    $length = ($length << 8) + ord(fread($this->socket, 1));
                } else {
                    $length = ord(fread($this->socket, 1));
                    $length = ($length << 8) + ord(fread($this->socket, 1));
                    $length = ($length << 8) + ord(fread($this->socket, 1));
                    $length = ($length << 8) + ord(fread($this->socket, 1));
                }
            } else {
                $length = $byte;
            }

            $chunk = '';
            if ($length > 0) {
                $chunk = '';
                $readLength = 0;
                while ($readLength < $length) {
                    $toRead = $length - $readLength;
                    $chunk .= fread($this->socket, $toRead);
                    $readLength = strlen($chunk);
                }
                $response[] = $chunk;
            }

            if ($chunk === '!done') {
                $receivedDone = true;
            }

            $status = stream_get_meta_data($this->socket);
            $unreadBytes = $status['unread_bytes'] ?? 0;

            if ((!$this->connected && !$unreadBytes) || ($this->connected && !$unreadBytes && $receivedDone)) {
                break;
            }
        }

        return $parse ? $this->parseResponse($response) : $response;
    }

    /**
     * Envoie une commande brute.
     *
     * @param bool|int $param2 true = fin de commande immédiate ; false =
     *                         d'autres lignes suivent ; int = tag de requête.
     */
    public function write(string $command, $param2 = true): bool
    {
        if ($command === '') {
            return false;
        }

        foreach (explode("\n", $command) as $line) {
            $line = trim($line);
            fwrite($this->socket, $this->encodeLength(strlen($line)) . $line);
        }

        if (is_int($param2)) {
            $tag = '.tag=' . $param2;
            fwrite($this->socket, $this->encodeLength(strlen($tag)) . $tag . chr(0));
        } elseif (is_bool($param2)) {
            fwrite($this->socket, $param2 ? chr(0) : '');
        }

        return true;
    }

    /**
     * Exécute une commande API et attend la réponse complète.
     * Ex: comm('/system/resource/print') ou
     *     comm('/ip/hotspot/user/add', ['name' => 'user1', 'password' => 'x'])
     */
    public function comm(string $command, array $arguments = []): array
    {
        $count = count($arguments);
        $this->write($command, $count === 0);

        $i = 0;
        if ($this->isIterable($arguments)) {
            foreach ($arguments as $key => $value) {
                $prefix = match ($key[0] ?? '') {
                    '?' => "$key=$value",
                    '~' => "$key~$value",
                    default => "=$key=$value",
                };
                $isLast = (++$i === $count);
                $this->write($prefix, $isLast);
            }
        }

        return $this->read();
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
