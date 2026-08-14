<?php
declare(strict_types=1);

function ara_legacy_redirect(string $path, array $query = []): never
{
    $url = $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    // 307 preserves the original HTTP method and body for legacy POST forms.
    header('Location: ' . $url, true, 307);
    exit;
}
