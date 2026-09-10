<?php

declare(strict_types=1);

/**
 * GET /sitemap.xml — served from here via the `^sitemap\.xml$` rewrite in
 * .htaccess, so crawlers keep asking for the conventional .xml URL that
 * robots.txt has always advertised while the document itself is generated
 * from the database on every request (see App\Service\Sitemap for what goes
 * in, what can never get in, and why there is no stored file).
 *
 * Deliberately as thin as an endpoint gets: no query parameters, nothing
 * read from the request, no session and no database logic of its own. Every
 * URL it prints comes from the same canonicalUrl() helpers the corresponding
 * pages render their <link rel="canonical"> with, resolved against APP_URL
 * — never against the Host header — so the same XML is correct on Vimexx and
 * in local Docker.
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Service\Sitemap;

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

header('Content-Type: ' . Sitemap::CONTENT_TYPE);
// A sitemap is public, cacheable content; an hour keeps a crawl from
// re-querying the catalogue on every URL it discovers, while still picking
// up a newly published product well within the day.
header('Cache-Control: public, max-age=3600');

echo Sitemap::xml();
