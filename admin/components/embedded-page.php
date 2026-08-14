<?php
declare(strict_types=1);

/**
 * Render an existing admin page inside a canonical workspace without
 * duplicating its business logic or rendering the global admin shell twice.
 */
function ara_render_embedded_page(string $file, array $query = []): void
{
    $previousQuery = $_GET;
    $_GET = array_merge($previousQuery, $query, ['embed' => '1']);

    ob_start();
    try {
        require $file;
        $html = (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        $_GET = $previousQuery;
        throw $e;
    }

    $_GET = $previousQuery;

    // Legacy pages historically emitted their own closing document tags.
    // Those tags are stripped so the view can live safely inside a workspace.
    $html = preg_replace('/<\/body>\s*<\/html>\s*$/i', '', $html) ?? $html;
    echo $html;
}
