<?php

declare(strict_types=1);

/**
 * GET /robots.txt — served from here via the `^robots\.txt$` rewrite in
 * .htaccess, the same arrangement /sitemap.xml already uses. Crawlers keep
 * asking for the conventional URL; the document itself is generated, so the
 * sitemap it advertises is the configured one rather than a domain typed
 * into a file (see App\Service\Robots for what goes in it and why).
 *
 * Deliberately as thin as an endpoint gets: no query parameters, nothing
 * read from the request, no session and no database.
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Service\Robots;

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

header('Content-Type: ' . Robots::CONTENT_TYPE);
// Crawlers refetch robots.txt often; an hour is enough to keep that cheap
// and short enough that a change is picked up the same day. Same value the
// generated sitemap uses.
header('Cache-Control: public, max-age=3600');

echo Robots::txt();
