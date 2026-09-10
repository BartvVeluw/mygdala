<?php

declare(strict_types=1);

namespace App\Service\Redirects;

/**
 * THE normalizer for redirect source paths — the single answer to "are these
 * two URLs the same path?", used identically at save time and at request time.
 *
 * There is one form, and everything is stored in it:
 *
 *     /foo   /foo/   /foo//   //foo   foo   /./foo   →  /foo
 *
 * Without that, /oude-pagina and /oude-pagina/ would be two independent rows
 * for one URL, an editor would fix one and wonder why the other still 404s,
 * and the unique index would not be protecting anything. .htaccess already
 * treats them as one route (`^([a-z0-9-]+)/?$` — the trailing slash is
 * optional), so this class is not inventing a policy, it is writing down the
 * one the router already has.
 *
 * WHAT IT DELIBERATELY DOES NOT DO:
 *
 *   - it does not lowercase. This site's routing is case-SENSITIVE:
 *     /Oude-Pagina 404s while /oude-pagina resolves, because Apache matches a
 *     filesystem path and .htaccess's slug pattern is `[a-z0-9-]`. Folding
 *     case here would make a redirect fire for URLs this site never had. (The
 *     `redirects.source_path` column is utf8mb4_bin for the same reason — the
 *     project's usual utf8mb4_unicode_ci compares case-insensitively.)
 *   - it does not keep the query string. Matching is on the path alone; see
 *     RedirectResolver for what happens to the query the visitor arrived with.
 *   - it does not resolve "..". A source path containing one is rejected
 *     outright rather than collapsed: nothing legitimate produces it, and
 *     silently rewriting somebody's input into a different URL than the one
 *     they typed is worse than telling them it is invalid.
 */
final class RedirectPath
{
    /** Matches `redirects.source_path`'s column width. */
    public const MAX_LENGTH = 255;

    /**
     * The canonical form of a path an administrator typed, or null when it
     * cannot be one: an absolute URL, a protocol-relative path, something
     * containing a "." or ".." segment, a control character, or a result
     * longer than the column.
     */
    public static function normalize(string $raw): ?string
    {
        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        // A source path is site-relative by definition: a scheme or an
        // authority means the administrator is describing somebody's URL,
        // possibly somebody else's, and this table cannot match on hosts.
        if (str_contains($value, '://') || str_starts_with($value, '//')) {
            return null;
        }

        // Query string and fragment are not part of the identity of a path.
        // Cutting them here means /oude-pagina?utm_source=x typed by hand
        // normalizes to the same row a plain /oude-pagina does.
        $value = (string) preg_replace('/[?#].*$/s', '', $value);

        $value = rawurldecode($value);

        // Control characters (a newline above all) must never reach a
        // Location: header or an admin table.
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        // A backslash is not a path separator on this server, but enough
        // clients rewrite it into one that a source containing it could never
        // be matched reliably.
        if (str_contains($value, chr(92))) {
            return null;
        }

        $value = '/' . ltrim($value, '/');
        $value = (string) preg_replace('#/{2,}#', '/', $value);

        foreach (explode('/', $value) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        if ($value !== '/') {
            $value = rtrim($value, '/');
        }

        if ($value === '') {
            $value = '/';
        }

        if (strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        return $value;
    }

    /**
     * The normalized path of a live request. Both places that consult the
     * redirect table read $_SERVER['REQUEST_URI'] — 404.php (Apache's
     * ErrorDocument, so the original URI, not this script's) and pagina.php
     * (an internal rewrite, which leaves REQUEST_URI untouched) — so one
     * implementation serves both.
     */
    public static function fromRequestUri(string $requestUri): ?string
    {
        return self::normalize(self::splitRequestUri($requestUri)[0]);
    }

    /**
     * The raw query string a request arrived with, '' when it had none. Kept
     * exactly as sent: it is only ever appended to an internal target that
     * has no query of its own, never parsed, merged or rewritten.
     */
    public static function queryFromRequestUri(string $requestUri): string
    {
        return self::splitRequestUri($requestUri)[1];
    }

    /** @return array{0: string, 1: string} path, query */
    private static function splitRequestUri(string $requestUri): array
    {
        // The fragment never reaches the server, but a hand-built URI in a
        // test could carry one; drop it before anything else looks at it.
        $withoutFragment = (string) preg_replace('/#.*$/s', '', $requestUri);

        $questionMark = strpos($withoutFragment, '?');

        if ($questionMark === false) {
            return [$withoutFragment, ''];
        }

        return [
            substr($withoutFragment, 0, $questionMark),
            substr($withoutFragment, $questionMark + 1),
        ];
    }
}
