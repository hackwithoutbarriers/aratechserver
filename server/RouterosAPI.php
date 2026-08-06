<?php
/**
 * RouterosAPI.php
 * ------------------------------------------------------------------
 * Client PHP pour l'API binaire MikroTik RouterOS (port 8728/8729).
 * Implémentation standard, basée sur le client communautaire largement
 * utilisé dans l'écosystème Mikhmon/hotspot (protocole documenté par
 * MikroTik : https://wiki.mikrotik.com/wiki/Manual:API).
 *
 * Pour la production, vous pouvez aussi utiliser la version officielle
 * maintenue sur le dépôt GitHub "routeros-api-php" — cette classe est
 * fonctionnellement équivalente et suffisante pour ce projet.
 * ------------------------------------------------------------------
 */

class RouterosAPI
{
    public $debug = false;
    public $connected = false;
    public $port = 8728;
    public $timeout = 10;
    public $attempts = 3;
    public $delay = 2;

    private $socket;
    private $error_no;
    private $error_str;

    /**
     * Établit la connexion et s'authentifie auprès du routeur.
     * @param string $ip
     * @param string $login
     * @param string $password
     * @param int $port
     * @return bool
     */
    public function connect(string $ip, string $login, string $password, int $port = 8728): bool
    {
        $this->port = $port;
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $this->connected = false;
            $this->socket = @fsockopen($ip, $this->port, $this->error_no, $this->error_str, $this->timeout);

            if ($this->socket) {
                stream_set_timeout($this->socket, $this->timeout);

                // RouterOS >= 6.43 : authentification directe (plus de challenge MD5)
                $this->write('/login', false);
                $this->write('=name=' . $login, false);
                $this->write('=password=' . $password, true);
                $response = $this->read(false);

                if (isset($response[0]) && $response[0] === '!done') {
                    $this->connected = true;
                    break;
                }

                // Anciennes versions RouterOS (< 6.43) : challenge MD5
                if (isset($response[0]) && $response[0] === '!trap') {
                    $this->write('/login');
                    $response = $this->read(false);
                    if (isset($response[0]) && $response[0] === '!done') {
                        $matches = [];
                        if (preg_match_all('/[=]([^=]*)[=](.*)/', $response[1] ?? '', $matches)) {
                            $md5 = md5(chr(0) . $password . pack('H*', $matches[2][0]));
                            $this->write('/login', false);
                            $this->write('=name=' . $login, false);
                            $this->write('=response=00' . $md5, true);
                            $response = $this->read(false);
                            if (isset($response[0]) && $response[0] === '!done') {
                                $this->connected = true;
                                break;
                            }
                        }
                    }
                }
            }

            if ($attempt < $this->attempts) {
                sleep($this->delay);
            }
        }

        return $this->connected;
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            fclose($this->socket);
        }
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->socket !== null;
    }

    /**
     * Envoie une commande complète (ex: /ip/hotspot/user/add) avec ses
     * paramètres associatifs et retourne la réponse déjà analysée.
     */
    public function comm(string $command, array $arguments = []): array
    {
        $count = count($arguments);
        $this->write($command, $count === 0);

        $i = 0;
        foreach ($arguments as $key => $value) {
            $i++;
            $this->write('=' . $key . '=' . $value, $i === $count);
        }

        return $this->parseResponse($this->read());
    }

    private function write(string $command, bool $terminate = true): void
    {
        if (!$this->socket) {
            return;
        }
        foreach (explode("\n", $command) as $line) {
            $line = trim($line);
            $this->writeLength(strlen($line));
            fwrite($this->socket, $line);
        }
        if ($terminate) {
            fwrite($this->socket, chr(0));
        }
    }

    private function writeLength(int $length): void
    {
        if ($length < 0x80) {
            fwrite($this->socket, chr($length));
        } elseif ($length < 0x4000) {
            $length |= 0x8000;
            fwrite($this->socket, chr(($length >> 8) & 0xFF) . chr($length & 0xFF));
        } elseif ($length < 0x200000) {
            $length |= 0xC00000;
            fwrite($this->socket, chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF));
        } elseif ($length < 0x10000000) {
            $length |= 0xE0000000;
            fwrite($this->socket, chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF));
        } else {
            fwrite($this->socket, chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF));
        }
    }

    private function readLength(): int
    {
        $byte = ord(fread($this->socket, 1));

        if (($byte & 0x80) === 0x00) {
            return $byte;
        }
        if (($byte & 0xC0) === 0x80) {
            return (($byte & ~0xC0) << 8) + ord(fread($this->socket, 1));
        }
        if (($byte & 0xE0) === 0xC0) {
            $b = ($byte & ~0xE0) << 8;
            $b = ($b + ord(fread($this->socket, 1))) << 8;
            return $b + ord(fread($this->socket, 1));
        }
        if (($byte & 0xF0) === 0xE0) {
            $b = ($byte & ~0xF0) << 8;
            $b = ($b + ord(fread($this->socket, 1))) << 8;
            $b = ($b + ord(fread($this->socket, 1))) << 8;
            return $b + ord(fread($this->socket, 1));
        }
        $b = ord(fread($this->socket, 1)) << 8;
        $b = ($b + ord(fread($this->socket, 1))) << 8;
        $b = ($b + ord(fread($this->socket, 1))) << 8;
        return $b + ord(fread($this->socket, 1));
    }

    private function read(bool $parseTrailingEmpty = true): array
    {
        $response = [];

        while (true) {
            $length = $this->readLength();
            $chunk = '';

            if ($length > 0) {
                $received = 0;
                while ($received < $length) {
                    $part = fread($this->socket, $length - $received);
                    if ($part === false || $part === '') {
                        break;
                    }
                    $chunk .= $part;
                    $received = strlen($chunk);
                }
                $response[] = $chunk;
            }

            $status = stream_get_meta_data($this->socket);

            if ($length === 0) {
                break;
            }
            if (!empty($status['timed_out'])) {
                break;
            }
        }

        return $response;
    }

    private function parseResponse(array $response): array
    {
        $parsed = [];
        $current = [];
        $hasCurrent = false;

        foreach ($response as $line) {
            if ($line === '!re' || $line === '!done' || $line === '!trap' || $line === '!fatal') {
                if ($hasCurrent) {
                    $parsed[] = $current;
                }
                $current = ['__reply' => $line];
                $hasCurrent = true;
                continue;
            }
            if ($line === '') {
                continue;
            }
            $matches = [];
            if (preg_match('/^[=]([^=]*)[=](.*)$/s', $line, $matches)) {
                $current[$matches[1]] = $matches[2];
            }
        }
        if ($hasCurrent) {
            $parsed[] = $current;
        }

        return $parsed;
    }
}