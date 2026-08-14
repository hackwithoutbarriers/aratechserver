<?php
declare(strict_types=1);

function ara_legacy_redirect(string $path, array $query = []): never
{
    $url = $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    header('Location: ' . $url, true, 302);
    exit;
}
