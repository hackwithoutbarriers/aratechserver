<?php
declare(strict_types=1);

// Ce fichier doit être inclus après avoir chargé $config.
// Il dépend de db.php pour la fonction ara_db.

/**
 * Envoie un pipeline de requêtes SQL vers Turso.
 */
function turso_pipeline(array $config, array $stmts): array
{
    if (empty($config['turso']['url']) || empty($config['turso']['token'])) {
        throw new RuntimeException('Turso non configuré.');
    }
    $url   = rtrim($config['turso']['url'], '/') . '/v2/pipeline';
    $token = $config['turso']['token'];

    $requests = [];
    foreach ($stmts as $stmt) {
        $requests[] = [
            'type' => 'execute',
            'stmt' => [
                'sql'  => $stmt['sql'],
                'args' => array_map(
                    static fn($v) => ['type' => 'text', 'value' => (string)$v],
                    $stmt['args'] ?? []
                ),
            ],
        ];
    }
    $requests[] = ['type' => 'close'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['requests' => $requests]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("Turso injoignable (cURL: $err).");
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Réponse Turso invalide (HTTP $code).");
    }

    foreach ($decoded['results'] ?? [] as $result) {
        if (($result['type'] ?? '') === 'error') {
            throw new RuntimeException('Turso SQL: ' . ($result['error']['message'] ?? 'erreur inconnue'));
        }
    }

    return $decoded['results'] ?? [];
}

/**
 * Transforme un résultat SELECT Turso en tableau associatif.
 */
function turso_rows(array $result): array
{
    $response = $result['response']['result'] ?? [];
    $cols     = array_column($response['cols'] ?? [], 'name');
    $rows     = [];
    foreach ($response['rows'] ?? [] as $row) {
        $assoc = [];
        foreach ($row as $i => $cell) {
            $assoc[$cols[$i]] = $cell['value'] ?? null;
        }
        $rows[] = $assoc;
    }
    return $rows;
}

/**
 * Si la table locale est vide, la remplit depuis Turso.
 * Retourne true si des données ont été restaurées.
 */
function restore_from_turso_if_empty(PDO $pdo, array $config, string $table, string $tursoQuery, array $tursoArgs, string $insertLocalSQL): bool
{
    // Vérifier si la locale est vide
    $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
    if ((int)$stmt->fetchColumn() > 0) {
        return false;
    }

    // Turso configuré ?
    if (empty($config['turso']['url']) || empty($config['turso']['token'])) {
        return false;
    }

    try {
        $results = turso_pipeline($config, [[
            'sql'  => $tursoQuery,
            'args' => $tursoArgs,
        ]]);
        $rows = [];
        foreach ($results as $r) {
            if (!empty($r['response']['result']['cols'])) {
                $rows = turso_rows($r);
                break;
            }
        }
        foreach ($rows as $row) {
            $insert = $pdo->prepare($insertLocalSQL);
            $insert->execute(array_values($row));
        }
        return !empty($rows);
    } catch (Throwable $e) {
        // Silencieux : on préfère ne pas casser la page si Turso est injoignable
        return false;
    }
}
