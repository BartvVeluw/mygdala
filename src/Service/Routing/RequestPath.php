<?php

declare(strict_types=1);

namespace App\Service\Routing;

/**
 * ONE request URI, taken apart safely (docs/multilingual/ROUTING.md).
 *
 * Everything the dispatcher decides — which language, which route, which
 * canonical form — is decided on the pieces this class produces, so the
 * parsing rules are stated once and are the same for every route.
 *
 * THE NORMAL FORM. A path is a list of decoded segments plus two flags:
 *
 *     /blog/mijn-bericht/?pagina=2   ->  ['blog', 'mijn-bericht'] + trailing slash + query
 *     //blog//x                      ->  ['blog', 'x']
 *     /                              ->  []                        + trailing slash
 *
 * Empty segments are dropped (a doubled slash is a typo, not a route), and
 * every segment is rawurldecode()d exactly once — AFTER the split, never
 * before, so an encoded "%2F" can never grow into a path separator.
 *
 * WHAT IS REFUSED OUTRIGHT, as null rather than repaired:
 *
 *   - a "." or ".." segment, before or after decoding. Nothing legitimate
 *     produces one and collapsing it would answer a different URL than the
 *     one that was asked for. Same rule, same reason, as
 *     App\Service\Redirects\RedirectPath.
 *   - a control character or a NUL, which must never reach a Location
 *     header, a query parameter or a log line;
 *   - a backslash, which enough clients rewrite into a separator that a path
 *     containing one could never be matched reliably;
 *   - a path longer than MAX_LENGTH, or with more than MAX_SEGMENTS parts.
 *
 * A refused path is not an error page of its own: the dispatcher answers it
 * with the site's ordinary 404, because from a visitor's point of view a URL
 * that cannot be a route and a URL that is not a route are the same thing.
 *
 * THE QUERY STRING IS CARRIED, NEVER PARSED. It is kept verbatim so a
 * canonical redirect can hand it on unchanged; what is in it is the
 * template's business, exactly as it was when Apache's [QSA] appended it.
 */
final class RequestPath
{
    public const MAX_LENGTH = 2048;
    public const MAX_SEGMENTS = 24;

    /**
     * @param list<string> $segments decoded, non-empty, in order
     */
    private function __construct(
        public readonly array $segments,
        public readonly bool $hadTrailingSlash,
        public readonly string $query,
    ) {
    }

    /**
     * The pieces of a REQUEST_URI, or null when it is not a path this
     * application will route.
     */
    public static function fromRequestUri(string $requestUri): ?self
    {
        if ($requestUri === '' || strlen($requestUri) > self::MAX_LENGTH) {
            return null;
        }

        $query = '';
        $path = $requestUri;

        $questionMark = strpos($path, '?');
        if ($questionMark !== false) {
            $query = substr($path, $questionMark + 1);
            $path = substr($path, 0, $questionMark);
        }

        // A fragment never reaches the server, but a hand-made request can
        // still carry one and it is not part of any path.
        $hash = strpos($path, '#');
        if ($hash !== false) {
            $path = substr($path, 0, $hash);
        }

        if (str_contains($path, chr(92)) || str_contains($query, chr(92))) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path . $query) === 1) {
            return null;
        }

        $hadTrailingSlash = $path === '' || str_ends_with($path, '/');

        $segments = [];
        foreach (explode('/', $path) as $raw) {
            if ($raw === '') {
                continue;
            }

            $segment = rawurldecode($raw);

            if ($segment === '' || $segment === '.' || $segment === '..' || $raw === '.' || $raw === '..') {
                return null;
            }

            if (str_contains($segment, '/') || str_contains($segment, chr(92))) {
                return null;
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
                return null;
            }

            $segments[] = $segment;

            if (count($segments) > self::MAX_SEGMENTS) {
                return null;
            }
        }

        return new self($segments, $hadTrailingSlash, $query);
    }

    /** Is this the site root — no segments at all? */
    public function isRoot(): bool
    {
        return $this->segments === [];
    }

    public function firstSegment(): ?string
    {
        return $this->segments[0] ?? null;
    }

    /** The same path without its first segment. */
    public function withoutFirstSegment(): self
    {
        return new self(array_slice($this->segments, 1), $this->hadTrailingSlash, $this->query);
    }

    /**
     * The site-relative path these segments spell, leading slash included and
     * no trailing slash except for the root itself.
     */
    public function path(): string
    {
        return $this->segments === [] ? '/' : '/' . implode('/', $this->segments);
    }

    /** path() with the query string back on it, ready for a Location header. */
    public function pathWithQuery(): string
    {
        return $this->query === '' ? $this->path() : $this->path() . '?' . $this->query;
    }

    /** Put this request's query string back on any other path. */
    public function withQuery(string $path): string
    {
        if ($this->query === '') {
            return $path;
        }

        return $path . (str_contains($path, '?') ? '&' : '?') . $this->query;
    }
}
