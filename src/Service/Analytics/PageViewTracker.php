<?php

namespace App\Service\Analytics;

use App\Repository\PageViewRepository;
use DateTimeImmutable;
use Throwable;

/**
 * The request-time half of the first-party website statistics: decides
 * whether the current request is a public pageview worth counting, and if so
 * writes exactly one row.
 *
 * Called from partials/header.php — the one file every public page includes,
 * and nothing under /admin or /api does. That placement is the whole reason
 * CMS pageviews cannot end up in the statistics: they never reach this code.
 * shouldTrack() still refuses /admin and /api paths, so moving the call or
 * adding a new template cannot quietly start counting the back office.
 *
 * Server-side rather than a tracking pixel or a JavaScript beacon, on
 * purpose:
 *  - it counts visitors with JavaScript disabled, and cannot be blocked by an
 *    ad blocker, so the numbers are the site's own truth rather than a
 *    filtered sample;
 *  - it needs no cookie and no client-side storage at all, which is what
 *    keeps this outside the cookie-consent obligation (see
 *    App\Service\CookieConsentConfig — its 'analytics' category stays unused
 *    precisely because nothing here is stored on the visitor's device);
 *  - it is one INSERT inside a page that already talks to the database, not
 *    an extra HTTP request per pageview.
 *
 * Nothing here may ever break a page. Every failure — a missing table on a
 * deploy where the migration has not run yet, an unreachable database, a
 * malformed URL — is swallowed: a visitor must never see an error because a
 * statistic could not be written. That is also why the only public entry
 * point does its own try/catch rather than leaving it to the caller.
 */
final class PageViewTracker
{
    /**
     * The query parameters that are part of a page's IDENTITY and may be kept
     * in the stored path, per path. Everything not listed here is discarded
     * before anything is written — order tokens on /bestelling-status.php,
     * utm_* campaign tags, and anything a visitor typed into a URL.
     *
     * /product.php is the only entry because it is the only public page whose
     * content is chosen by a query parameter (the pretty URLs for
     * collections, portfolio projects and CMS pages already carry their slug
     * in the path). Without it "top 5 pages" would report one line reading
     * "/product.php" for the entire catalogue.
     */
    private const IDENTIFYING_QUERY = [
        '/product.php' => ['id'],
    ];

    /** Matches the `path` column; a longer URL is truncated, never dropped. */
    private const MAX_PATH_LENGTH = 190;

    /**
     * Records the current request if it qualifies. Safe to call
     * unconditionally from a public template.
     *
     * @param array<string, mixed>|null $server     defaults to $_SERVER
     * @param PageViewRepository|null   $repository injected by the tests
     */
    public static function trackCurrentRequest(?array $server = null, ?PageViewRepository $repository = null): void
    {
        try {
            $server ??= $_SERVER;

            $method = strtoupper(trim((string) ($server['REQUEST_METHOD'] ?? 'GET')));
            $userAgent = (string) ($server['HTTP_USER_AGENT'] ?? '');
            $path = self::normalizePath((string) ($server['REQUEST_URI'] ?? '/'));

            // http_response_code() returns false outside a web SAPI; a page
            // that never set a status is a 200.
            $status = http_response_code();
            $status = is_int($status) ? $status : 200;

            if (!self::shouldTrack($method, $path, $userAgent, $status)) {
                return;
            }

            $repository ??= new PageViewRepository();
            $now = new DateTimeImmutable('now');

            // The IP address exists only inside this call: it goes into the
            // hash and is never assigned, logged or returned.
            $visitorHash = VisitorHash::compute(
                $repository->visitorSaltForDate($now->format('Y-m-d')),
                VisitorHash::clientIp($server),
                $userAgent
            );

            $repository->record(
                $path,
                $visitorHash,
                self::referrerHost(
                    (string) ($server['HTTP_REFERER'] ?? ''),
                    (string) ($server['HTTP_HOST'] ?? '')
                ),
                DeviceType::fromUserAgent($userAgent),
                $now->format('Y-m-d H:i:s')
            );
        } catch (Throwable) {
            // Statistics are never worth a broken page. See the class docblock.
        }
    }

    /**
     * Whether a request is a public pageview a person actually looked at.
     *
     * Only successful GETs count: a POST is an action rather than a page, a
     * HEAD is a probe, and a 404 or a redirect is not a page anyone read — if
     * 404s were counted, a scanner walking made-up URLs would fill "top
     * pages" with paths that do not exist.
     */
    public static function shouldTrack(string $method, string $path, string $userAgent, int $statusCode): bool
    {
        if ($method !== 'GET' || $statusCode !== 200) {
            return false;
        }

        // Belt and braces: the CMS and the API never include the template
        // that calls this, and they are also refused here by path.
        if (self::isAdminOrApiPath($path)) {
            return false;
        }

        return !BotDetector::isBot($userAgent);
    }

    private static function isAdminOrApiPath(string $path): bool
    {
        foreach (['/admin', '/api'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduces a request URI to the stored `path`: the URL path, plus only the
     * query parameters listed in IDENTIFYING_QUERY.
     *
     * The path is kept exactly as the browser sent it (still percent-encoded)
     * rather than decoded — every public URL this site generates is made of
     * [a-z0-9-] and ".php", so decoding would buy nothing and would let an
     * arbitrary byte sequence into a column the CMS renders.
     *
     * "/index.php" and "/" are the same page and must not be two rows in the
     * top-pages list; the homepage is stored as "/".
     */
    public static function normalizePath(string $requestUri): string
    {
        $requestUri = trim($requestUri);

        // Leading slashes are collapsed BEFORE parsing: a request URI of
        // "//collecties//hout" is a valid thing for a browser to send, but
        // parse_url reads a leading "//" as a protocol-relative URL and would
        // hand back "/hout" — the first path segment silently gone.
        $requestUri = preg_replace('#^/+#', '/', $requestUri) ?? $requestUri;

        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';

        // Collapse "//a///b" to "/a/b" and guarantee a leading slash, so the
        // same page reached through a sloppy link is one row, not several.
        $path = '/' . trim(preg_replace('#/+#', '/', $path) ?? '', '/');

        if ($path === '/index.php') {
            $path = '/';
        }

        $path .= self::identifyingQuery($path, $requestUri);

        return substr($path, 0, self::MAX_PATH_LENGTH);
    }

    /**
     * The allowlisted part of the query string, ready to append — '' when the
     * path has no identifying parameters or the request carried none.
     */
    private static function identifyingQuery(string $path, string $requestUri): string
    {
        $allowed = self::IDENTIFYING_QUERY[$path] ?? null;
        if ($allowed === null) {
            return '';
        }

        $rawQuery = parse_url($requestUri, PHP_URL_QUERY);
        if (!is_string($rawQuery) || $rawQuery === '') {
            return '';
        }

        parse_str($rawQuery, $params);

        $kept = [];
        foreach ($allowed as $name) {
            $value = $params[$name] ?? null;

            // Digits only: every identifying parameter this site has is a
            // database id, and refusing anything else keeps free text out of
            // the column without needing to escape it later.
            if (is_string($value) && preg_match('/^\d{1,10}$/', $value) === 1) {
                $kept[] = $name . '=' . $value;
            }
        }

        return $kept === [] ? '' : '?' . implode('&', $kept);
    }

    /**
     * The host a visitor arrived from, or null when there is no external
     * source: no referrer at all (a direct visit, a bookmark, a browser that
     * suppresses it) or a link from this site to itself.
     *
     * Only the host is kept — never the referrer's path or query, which can
     * carry a search phrase or a private URL. "www." is stripped so
     * www.google.com and google.com are one row.
     */
    public static function referrerHost(string $referrer, string $currentHost): ?string
    {
        $referrer = trim($referrer);
        if ($referrer === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = self::canonicalHost($host);
        if ($host === '' || $host === self::canonicalHost($currentHost)) {
            return null;
        }

        return substr($host, 0, 190);
    }

    private static function canonicalHost(string $host): string
    {
        // HTTP_HOST may carry a port ("localhost:8000"); a referrer host
        // never does, so strip it on both sides before comparing.
        $host = strtolower(trim($host));
        $host = explode(':', $host, 2)[0];

        return preg_replace('/^www\./', '', $host) ?? $host;
    }
}
