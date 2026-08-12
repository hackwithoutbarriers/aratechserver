<?php
declare(strict_types=1);

/**
 * lib/api_client.php — Client HTTP interne pour appeler api.php depuis les pages admin.
 * -----------------------------------------------------------------------------------
 * Point d'entrée UNIQUE pour toute page admin qui doit parler à api.php :
 *   - envoie le token admin en header X-Admin-Token (JAMAIS en query string,
 *     ni côté PHP ni côté JS — voir ara_api_headers() plus bas) ;
 *   - centralise le timeout, la gestion d'erreur et le décodage JSON ;
 *   - une seule fonction à corriger si le contrat API évolue.
 *
 * Ne change AUCUNE route existante côté api.php : consomme le contrat déjà en place.
 * Deux formes de réponse coexistent côté api.php :
 *   - routes "Hotspot" (json_api_success)      : {"success":true,"data":{...}}
 *   - routes historiques (json_response/json_error) : {"success":true,"logs":[...],...}
 * ara_api_call() normalise les deux : $result['data'] contient toujours le
 * contenu utile, que la route utilise 'data' ou des clés à plat.
 *
 * Utilisation typique dans une page admin :
 *
 *   require_once __DIR__ . '/../lib/api_client.php';
 *   $result = ara_api_call($config, 'hotspot-profiles', ['search' => $q]);
 *   if (!$result['success']) {
 *       $error = $result['message'];
 *   } else {
 *       $items = $result['data']['items'] ?? [];
 *   }
 */

/**
 * Appelle une route de api.php et retourne un tableau normalisé :
 *   ['success' => bool, 'data' => mixed|null, 'message' => string|null, 'status' => int]
 *
 * @param array  $config  Le tableau retourné par config.php (utilisé pour le token admin).
 * @param string $route   Nom de route (valeur de ?route=...), sans slash ni query string.
 * @param array  $query   Paramètres GET additionnels (search, page, limit, id, ...).
 * @param string $method  'GET' ou 'POST'.
 * @param array|null $body Corps JSON envoyé pour une requête POST/PUT/PATCH/DELETE.
 */
function ara_api_call(
    array $config,
    string $route,
    array $query = [],
    string $method = 'GET',
    ?array $body = null
): array {
    $token = (string)($config['admin']['token'] ?? '');

    $query['route'] = $route;
    $base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api.php';
    $url  = $base . '?' . http_build_query($query);

    $headers = ['Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'X-Admin-Token: ' . $token;
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ];

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, $opts);
    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['success' => false, 'data' => null, 'message' => "API injoignable ($err).", 'status' => 0];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['success' => false, 'data' => null, 'message' => 'Réponse API invalide.', 'status' => $status];
    }

    if (!($decoded['success'] ?? false)) {
        $message = $decoded['error']['message'] ?? ($decoded['message'] ?? 'Erreur API inconnue.');
        return ['success' => false, 'data' => null, 'message' => (string)$message, 'status' => $status];
    }

    // Contrat Hotspot (json_api_success) : la charge utile est déjà sous 'data'.
    // Contrat historique (json_response) : la charge utile est à plat aux côtés
    // de 'success' (ex: {success, logs, count} ou {success, summary, ads}).
    // On expose toujours 'data' pour que l'appelant n'ait qu'un seul chemin à lire.
    $data = array_key_exists('data', $decoded) ? $decoded['data'] : $decoded;

    return ['success' => true, 'data' => $data, 'message' => null, 'status' => $status];
}

/**
 * En-têtes HTTP prêts à l'emploi côté JavaScript pour appeler api.php depuis
 * le navigateur, sans jamais mettre le token admin dans l'URL (query string).
 * Injecter le résultat dans la page via json_encode() et le réutiliser tel
 * quel dans fetch(url, { headers: ARA_API_HEADERS, ... }).
 *
 * Exemple PHP → JS :
 *   const API_HEADERS = <?= ara_api_headers_json($config) ?>;
 *   fetch('/api.php?route=hotspot-users', { headers: API_HEADERS });
 */
function ara_api_headers_json(array $config): string
{
    $token = (string)($config['admin']['token'] ?? '');
    return json_encode(['X-Admin-Token' => $token], JSON_UNESCAPED_UNICODE);
}
