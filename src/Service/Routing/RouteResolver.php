<?php

declare(strict_types=1);

namespace App\Service\Routing;

/**
 * Which route answers a path, in one language (docs/multilingual/ROUTING.md).
 *
 * The matcher for App\Service\Routing\RouteTable's patterns, and nothing
 * else: it does not read the database, does not know what a page or a post
 * is, and never decides whether the entity behind a slug exists. That last
 * distinction is the whole point of the separation — resolving the ROUTE says
 * "this shape of URL is served by this template"; whether there is content at
 * it stays the template's own lookup, which is where the language-specific
 * "this page has no German version" 404 belongs (see
 * docs/multilingual/ROUTING.md, "Route existence versus field fallback").
 *
 * MATCHING IS EXACT AND ORDERED. The first pattern in the table whose segment
 * count and segments all match wins. A capture only ever accepts
 * RouteTable::CAPTURE_PATTERN, so a path with an underscore, a capital or a
 * dot resolves to nothing at all — byte for byte the set of URLs Apache
 * refused before this class existed.
 *
 * FIXED SEGMENTS ARE MATCHED IN THE REQUEST'S LANGUAGE FIRST, then in every
 * other language the catalogue knows (App\Service\Routing\RouteSegments). A
 * match on one of those aliases still resolves, but reports the canonical
 * path alongside it so the dispatcher can send exactly one permanent redirect
 * — /en/blog/categorie/hout keeps working and lands on
 * /en/blog/category/hout, rather than 404'ing a link somebody already has.
 */
final class RouteResolver
{
    /**
     * The route that answers this UNPREFIXED path in this language, or null
     * when nothing does.
     *
     * @param list<string> $segments the decoded path segments, no language prefix
     */
    public static function resolve(array $segments, string $language): ?RouteMatch
    {
        foreach (RouteTable::all() as $route) {
            $match = self::matchRoute($route, $segments, $language);

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param array{key: string, pattern: string, template: string, query: array<string, string>} $route
     * @param list<string> $segments
     */
    private static function matchRoute(array $route, array $segments, string $language): ?RouteMatch
    {
        $pattern = $route['pattern'] === '' ? [] : explode('/', $route['pattern']);

        if (count($pattern) !== count($segments)) {
            return null;
        }

        $captures = [];
        $canonical = [];
        $needsRedirect = false;

        foreach ($pattern as $index => $part) {
            $segment = $segments[$index];

            if (!str_starts_with($part, '{') || !str_ends_with($part, '}')) {
                if ($part !== $segment) {
                    return null;
                }

                $canonical[] = $segment;
                continue;
            }

            $name = substr($part, 1, -1);

            if (str_contains($name, '.')) {
                $variants = RouteSegments::variants($name, $language);

                if (!in_array($segment, $variants, true)) {
                    return null;
                }

                $canonicalWord = $variants[0];
                if ($segment !== $canonicalWord) {
                    $needsRedirect = true;
                }

                $canonical[] = $canonicalWord;
                continue;
            }

            if (preg_match(RouteTable::CAPTURE_PATTERN, $segment) !== 1) {
                return null;
            }

            $captures[$name] = $segment;
            $canonical[] = $segment;
        }

        $query = [];
        foreach ($route['query'] as $parameter => $capture) {
            if (array_key_exists($capture, $captures)) {
                $query[$parameter] = $captures[$capture];
            }
        }

        return new RouteMatch(
            key: $route['key'],
            template: $route['template'],
            query: $query,
            language: $language,
            canonicalPath: $needsRedirect ? '/' . implode('/', $canonical) : null,
        );
    }
}
