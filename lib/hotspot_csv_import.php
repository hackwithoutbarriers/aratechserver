<?php
declare(strict_types=1);

const HOTSPOT_CSV_REQUIRED_HEADERS = [
    'username'   => 'Username',
    'password'   => 'Password',
    'profile'    => 'Profile',
    'time limit' => 'Time Limit',
    'data limit' => 'Data Limit',
    'comment'    => 'Comment',
];
const HOTSPOT_CSV_MAX_BYTES = 2097152; // 2 MiB
const HOTSPOT_CSV_MAX_ROWS = 2000;

function hotspot_csv_normalize_header(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return strtolower($value);
}

function hotspot_csv_normalize_value(?string $value): string
{
    return trim((string)$value);
}

function hotspot_csv_detect_delimiter(string $line): string
{
    $candidates = [',', ';'];
    $best = ',';
    $bestScore = -1;
    foreach ($candidates as $delimiter) {
        $fields = str_getcsv($line, $delimiter);
        $score = 0;
        foreach ($fields as $field) {
            $key = hotspot_csv_normalize_header((string)$field);
            if (isset(HOTSPOT_CSV_REQUIRED_HEADERS[$key])) $score += 10;
        }
        $score += count($fields);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $delimiter;
        }
    }
    return $best;
}

function hotspot_csv_validate_upload(array $file): void
{
    if (!isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])) {
        throw new RuntimeException('Fichier téléversé manquant.');
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Erreur lors du téléversement du fichier.');
    }
    if (!is_uploaded_file((string)$file['tmp_name'])) {
        throw new RuntimeException('Source de téléversement invalide.');
    }
    if ((int)$file['size'] <= 0) {
        throw new RuntimeException('Le fichier CSV est vide.');
    }
    if ((int)$file['size'] > HOTSPOT_CSV_MAX_BYTES) {
        throw new RuntimeException('Le fichier dépasse la taille maximale autorisée de 2 MiB.');
    }
    if (strtolower((string)pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        throw new RuntimeException('Le fichier doit porter l’extension .csv.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string)$file['tmp_name']);
    $allowed = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
    if ($mime === false || !in_array($mime, $allowed, true)) {
        throw new RuntimeException('Type de fichier non autorisé pour un CSV.');
    }

    $sample = file_get_contents((string)$file['tmp_name'], false, null, 0, 4096);
    if ($sample === false || !preg_match('//u', $sample)) {
        throw new RuntimeException('Le fichier doit être encodé en UTF-8.');
    }
    if (stripos($sample, '<?php') !== false || stripos($sample, '<?=') !== false) {
        throw new RuntimeException('Le contenu ressemble à un script PHP et est refusé.');
    }
}

function hotspot_csv_read(string $path, PDO $pdo): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Impossible de lire le fichier CSV.');

    try {
        $firstLine = fgets($handle);
        if ($firstLine === false) throw new RuntimeException('Fichier CSV vide.');
        $delimiter = hotspot_csv_detect_delimiter($firstLine);
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter);
        if ($header === false || $header === null) throw new RuntimeException('En-tête CSV illisible.');
        $normalized = [];
        foreach ($header as $i => $value) {
            $key = hotspot_csv_normalize_header((string)$value);
            if ($key === '') continue;
            if (isset($normalized[$key])) {
                throw new RuntimeException('Colonne dupliquée dans l’en-tête : ' . (HOTSPOT_CSV_REQUIRED_HEADERS[$key] ?? $value));
            }
            $normalized[$key] = $i;
        }

        $missing = [];
        foreach (HOTSPOT_CSV_REQUIRED_HEADERS as $key => $label) {
            if (!array_key_exists($key, $normalized)) $missing[] = $label;
        }
        if ($missing) {
            throw new RuntimeException('Colonnes manquantes : ' . implode(', ', $missing) . '.');
        }

        $unknown = [];
        foreach (array_keys($normalized) as $key) {
            if (!isset(HOTSPOT_CSV_REQUIRED_HEADERS[$key])) $unknown[] = $key;
        }

        $profileStmt = $pdo->query('SELECT profile_name FROM hotspot_profiles');
        $profiles = [];
        foreach ($profileStmt->fetchAll(PDO::FETCH_COLUMN) as $profile) {
            $name = trim((string)$profile);
            if ($name !== '') $profiles[strtolower($name)] = $name;
        }
        if (!$profiles) {
            throw new RuntimeException('Aucun profil Hotspot n’est disponible dans hotspot_profiles.');
        }

        $rows = [];
        $errors = [];
        $seen = [];
        $lineNumber = 1;
        while (($csvRow = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNumber++;
            if ($csvRow === [null] || (count($csvRow) === 1 && trim((string)$csvRow[0]) === '')) continue;
            if (count($rows) >= HOTSPOT_CSV_MAX_ROWS) {
                throw new RuntimeException('Le fichier dépasse la limite de ' . HOTSPOT_CSV_MAX_ROWS . ' lignes de données.');
            }

            $get = static function (string $key) use ($csvRow, $normalized): string {
                return hotspot_csv_normalize_value($csvRow[$normalized[$key]] ?? '');
            };
            $username = $get('username');
            $password = $get('password');
            $profile = $get('profile');
            $timeLimit = $get('time limit');
            $dataLimit = $get('data limit');
            $comment = $get('comment');
            $rowErrors = [];

            if ($username === '') $rowErrors[] = 'Username vide.';
            elseif (!preg_match('/^[A-Za-z0-9_.\-]+$/', $username) || strlen($username) > 64) {
                $rowErrors[] = 'Username invalide.';
            }
            $usernameKey = strtolower($username);
            if ($username !== '' && isset($seen[$usernameKey])) {
                $rowErrors[] = 'Doublon dans le fichier (même Username que la ligne ' . $seen[$usernameKey] . ').';
            } elseif ($username !== '') {
                $seen[$usernameKey] = $lineNumber;
            }

            if ($password === '') $rowErrors[] = 'Password vide.';
            if ($profile === '') $rowErrors[] = 'Profile vide.';
            elseif (!isset($profiles[strtolower($profile)])) $rowErrors[] = 'Profile "' . $profile . '" inexistant.';

            if ($timeLimit !== '' && !hotspot_csv_valid_time_limit($timeLimit)) {
                $rowErrors[] = 'Time Limit invalide.';
            }
            if ($dataLimit !== '' && !preg_match('/^\d+$/', $dataLimit)) {
                $rowErrors[] = 'Data Limit invalide.';
            } elseif ($dataLimit !== '' && (function_exists('bccomp') ? bccomp($dataLimit, (string)PHP_INT_MAX, 0) > 0 : strlen($dataLimit) > strlen((string)PHP_INT_MAX) || (strlen($dataLimit) === strlen((string)PHP_INT_MAX) && strcmp($dataLimit, (string)PHP_INT_MAX) > 0))) {
                $rowErrors[] = 'Data Limit dépasse la capacité numérique du serveur.';
            }

            $rows[] = [
                'line' => $lineNumber,
                'username' => $username,
                'password' => $password,
                'profile' => $profile,
                'time_limit' => $timeLimit,
                'data_limit' => $dataLimit === '' ? null : $dataLimit,
                'comment' => $comment,
                'errors' => $rowErrors,
            ];
            if ($rowErrors) $errors[] = ['line' => $lineNumber, 'username' => $username, 'error' => implode(' ', $rowErrors)];
        }

        if (!$rows) {
            throw new RuntimeException('Le CSV ne contient aucune ligne utilisateur.');
        }

        return [
            'delimiter' => $delimiter,
            'unknown_headers' => $unknown,
            'rows' => $rows,
            'errors' => $errors,
            'profiles' => array_values($profiles),
        ];
    } finally {
        fclose($handle);
    }
}

function hotspot_csv_valid_time_limit(string $value): bool
{
    $value = trim($value);
    if ($value === '') return true;
    // RouterOS duration syntax: 10h, 24h, 7d, 1w, and combinations such as 1d 2h.
    return preg_match('/^(?:(?:\d+(?:\.\d+)?)(?:w|d|h|m|s))(?:\s+(?:\d+(?:\.\d+)?)(?:w|d|h|m|s))*$/i', $value) === 1;
}

function hotspot_csv_lookup_existing(PDO $pdo, array $usernames): array
{
    if (!$usernames) return [];
    $placeholders = implode(',', array_fill(0, count($usernames), '?'));
    $stmt = $pdo->prepare("SELECT username FROM hotspot_users WHERE username IN ($placeholders)");
    $stmt->execute(array_values($usernames));
    $existing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $username) $existing[strtolower((string)$username)] = true;
    return $existing;
}

function hotspot_csv_canonical_payload(array $payload): string
{
    ksort($payload);
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function hotspot_csv_find_reusable_command(PDO $pdo, string $action, string $username, array $payload): ?int
{
    $stmt = $pdo->prepare(
        "SELECT id, payload FROM hotspot_commands
         WHERE username = ? AND action = ?
           AND UPPER(status) IN ('PENDING','PROCESSING','EXECUTED')
         ORDER BY id DESC LIMIT 20"
    );
    $stmt->execute([$username, $action]);
    $wanted = hotspot_csv_canonical_payload($payload);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stored = json_decode((string)($row['payload'] ?? '{}'), true);
        if (!is_array($stored)) continue;
        $stored['username'] = $username;
        if (hotspot_csv_canonical_payload($stored) === hotspot_csv_canonical_payload(array_merge($payload, ['username' => $username]))) {
            return (int)$row['id'];
        }
    }
    return null;
}

function hotspot_csv_queue_command(PDO $pdo, string $action, string $username, array $payload): int
{
    $payload['username'] = $username;
    $existing = hotspot_csv_find_reusable_command($pdo, $action, $username, $payload);
    if ($existing !== null) return $existing;

    $stmt = $pdo->prepare(
        "INSERT INTO hotspot_commands (action, username, payload, status, created_at)
         VALUES (?, ?, ?, 'PENDING', ?) RETURNING id"
    );
    $stmt->execute([$action, $username, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), date('c')]);
    $id = $stmt->fetchColumn();
    if ($id === false) throw new RuntimeException('Impossible de créer la commande MikroTik.');
    return (int)$id;
}

function hotspot_csv_import_rows(PDO $pdo, array $rows): array
{
    $validRows = array_values(array_filter($rows, static fn(array $row): bool => empty($row['errors'])));
    $usernames = array_map(static fn(array $row): string => $row['username'], $validRows);
    $existing = hotspot_csv_lookup_existing($pdo, $usernames);

    $upsertNew = $pdo->prepare(
        "INSERT INTO hotspot_users
            (username, password, profile, mac_address, comment, disabled, bytes_in, bytes_out, uptime, server, limit_uptime, limit_bytes_total)
         VALUES (?, ?, ?, '', ?, 'false', 0, 0, '', '', ?::interval, ?)
         ON CONFLICT (username) DO UPDATE SET
            password = EXCLUDED.password,
            profile = EXCLUDED.profile,
            comment = EXCLUDED.comment,
            limit_uptime = EXCLUDED.limit_uptime,
            limit_bytes_total = EXCLUDED.limit_bytes_total"
    );
    $upsertExisting = $pdo->prepare(
        "UPDATE hotspot_users
         SET password = ?, profile = ?, comment = ?, limit_uptime = ?::interval, limit_bytes_total = ?
         WHERE username = ?"
    );

    $result = [
        'total' => count($rows),
        'valid' => count($validRows),
        'invalid' => count($rows) - count($validRows),
        'new' => 0,
        'updated' => 0,
        'commands' => [],
        'errors' => array_map(static fn(array $e): array => ['line' => $e['line'], 'username' => $e['username'], 'error' => $e['error']], array_filter(array_map(static function (array $row): ?array {
            if (empty($row['errors'])) return null;
            return ['line' => $row['line'], 'username' => $row['username'], 'error' => implode(' ', $row['errors'])];
        }, $rows))),
    ];

    foreach ($validRows as $row) {
        $key = strtolower($row['username']);
        $isExisting = isset($existing[$key]);
        $limitUptime = $row['time_limit'] === '' ? null : $row['time_limit'];
        $limitBytes = $row['data_limit'];

        if ($isExisting) {
            $upsertExisting->execute([$row['password'], $row['profile'], $row['comment'], $limitUptime, $limitBytes, $row['username']]);
            $result['updated']++;
            $action = 'update';
        } else {
            $upsertNew->execute([$row['username'], $row['password'], $row['profile'], $row['comment'], $limitUptime, $limitBytes]);
            $result['new']++;
            $action = 'create';
        }

        $payload = [
            'username' => $row['username'],
            'password' => $row['password'],
            'profile' => $row['profile'],
            'limit_uptime' => $limitUptime,
            'limit_bytes_total' => $limitBytes,
            'comment' => $row['comment'],
        ];
        $commandId = hotspot_csv_queue_command($pdo, $action, $row['username'], $payload);
        $result['commands'][] = [
            'id' => $commandId,
            'line' => $row['line'],
            'username' => $row['username'],
            'action' => strtoupper($action),
            'status' => 'PENDING',
        ];
    }

    return $result;
}
