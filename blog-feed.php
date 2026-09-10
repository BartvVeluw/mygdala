<?php

declare(strict_types=1);

/**
 * GET /blog/feed.xml — the Blog's RSS 2.0 feed, generated from the database
 * on every request (see App\Service\Blog\BlogFeed for what goes in and what
 * can never get in), served from here through the `^blog/feed\.xml$` rewrite
 * in .htaccess.
 *
 * As thin as an endpoint gets, exactly like sitemap.php: no query parameters,
 * nothing read from the request, no session, and no logic of its own. Every
 * URL it prints comes from the same App\Service\Blog\BlogUrls the post pages
 * render their canonical tags with, resolved against APP_URL rather than the
 * Host header, so the same XML is correct on shared hosting and in local
 * Docker.
 *
 * TWO WAYS IT ANSWERS 404, and both are the same 404 a URL that never existed
 * gets: the Blog module is switched off, or the owner has switched the feed
 * off in Bloginstellingen. A feed nobody wants should not be discoverable,
 * and an empty or erroring document at a well-known URL is worse than no
 * document.
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Module\ModuleGuard;
use App\Service\Blog\BlogFeed;
use App\Service\Blog\BlogSettings;

ModuleGuard::requirePublicRoute('blog');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

if (!BlogSettings::rssEnabled()) {
    http_response_code(404);
    require __DIR__ . '/partials/route-not-found-page.php';
    exit;
}

header('Content-Type: ' . BlogFeed::CONTENT_TYPE);
// A feed is public, cacheable content, and readers poll it. An hour keeps a
// polling aggregator off the database without delaying a new post by a day.
header('Cache-Control: public, max-age=3600');

echo BlogFeed::xml();
