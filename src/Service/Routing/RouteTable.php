<?php

declare(strict_types=1);

namespace App\Service\Routing;

use App\Module\ModuleRegistry;

/**
 * EVERY public route of this application, as one ordered, closed list
 * (docs/multilingual/ROUTING.md).
 *
 * This is what the seven narrow `RewriteRule`s and the single-segment
 * catch-all in `.htaccess` used to be. They moved here for one reason: a
 * language prefix would have meant duplicating every one of them per
 * language, in a static file, for a list of languages that is a database
 * table. Route knowledge now lives in code with tests, and `.htaccess` is
 * left with "an existing file wins, everything else goes to the dispatcher".
 *
 * A CLOSED LIST, like App\Service\Blocks\BlockDefinitions and
 * App\Service\RouteRegistry. Core's routes are written out below; a module's
 * come from App\Module\ModuleDefinition::publicRoutes(). No directory scan,
 * no reflection, and above all no template path ever built from a request:
 * `template` is a literal in this file or in a module class, and
 * dispatcher.php requires nothing else.
 *
 * EVERY REGISTERED MODULE CONTRIBUTES, enabled or not — exactly like
 * ModuleDefinition::reservedSlugs(). With the Blog switched off /blog/… still
 * reaches blog-post.php, which answers the site's own 404 through
 * App\Module\ModuleGuard. That is what those URLs did before this class
 * existed, and a disabled module must keep being indistinguishable from a URL
 * that never existed rather than suddenly producing a different 404.
 *
 * THE PATTERN GRAMMAR, deliberately small:
 *
 *     ''                         the site root
 *     'shop.php'                 a literal segment (a real template's name)
 *     '{blog.root}/{slug}'       a catalogue segment, then a capture
 *     '{slug+}'                  one to MAX_PAGE_SEGMENTS captures, joined by '/'
 *
 *   - `{a.b}` (with a dot) is a key of App\Service\Routing\RouteSegments and
 *     is matched in the request's language, with the other languages' words
 *     accepted as aliases that redirect;
 *   - `{name}` (no dot) captures one segment, and only ever matches
 *     CAPTURE_PATTERN — the same `[a-z0-9-]` charset `.htaccess` matched, so
 *     no URL that used to 404 silently starts resolving;
 *   - `{name+}` is the one variable-length capture, and only as a pattern's
 *     LAST part: the rest of the path, one to MAX_PAGE_SEGMENTS segments,
 *     each held to CAPTURE_PATTERN. It exists for a nested CMS page
 *     (/metaal-graveren/rvs-graveren, docs/pages/NESTING.md). The route says
 *     nothing about whether those segments form a real page path: pagina.php
 *     checks the whole chain (App\Service\PageContent::forPath());
 *   - anything else is a literal segment, compared byte for byte.
 *
 * ORDER IS THE ONLY PRECEDENCE RULE. Core's fixed routes come first, then
 * every module's, and the generic one-segment page route comes LAST so a
 * module namespace can never be swallowed by a CMS page with the same name.
 */
final class RouteTable
{
    /** What a `{capture}` may contain — `.htaccess`'s own slug charset. */
    public const CAPTURE_PATTERN = '/\A[a-z0-9-]+\z/';

    /** The generic CMS page route's key, needed by the dispatcher's 404 path. */
    public const PAGE_ROUTE = 'core.page';

    /** The site root's key. */
    public const HOME_ROUTE = 'core.home';

    /**
     * The most segments a CMS page path may have: the deepest a page can sit
     * (App\Service\PagePath::MAX_DEPTH). A longer path is no page route.
     */
    public const MAX_PAGE_SEGMENTS = 8;

    /**
     * Core's own routes, in matching order and WITHOUT the generic page route
     * (which is appended last by all()).
     *
     * The `.php` entries are this project's real root-level templates. They
     * are listed because a language-prefixed URL never reaches them through
     * Apache: /shop.php is a file and is served directly, /en/shop.php is not
     * and arrives here. Their unprefixed form keeps being served by Apache,
     * byte for byte as before — which is what keeps every existing canonical
     * URL answering 200.
     */
    private const CORE_ROUTES = [
        ['key' => self::HOME_ROUTE, 'pattern' => '', 'template' => 'index.php'],
        ['key' => 'core.home.file', 'pattern' => 'index.php', 'template' => 'index.php'],
        ['key' => 'core.contact', 'pattern' => 'contact.php', 'template' => 'contact.php'],
        ['key' => 'core.diensten', 'pattern' => 'diensten.php', 'template' => 'diensten.php'],
        ['key' => 'core.over-mij', 'pattern' => 'over-mij.php', 'template' => 'over-mij.php'],
        ['key' => 'core.cookiebeleid', 'pattern' => 'cookiebeleid.php', 'template' => 'cookiebeleid.php'],
        ['key' => 'core.herroeping', 'pattern' => 'herroeping.php', 'template' => 'herroeping.php'],
    ];

    /** @var list<array<string, mixed>>|null */
    private static ?array $routes = null;

    /** Forgets the merged list; App\Module\ModuleRegistry::reset() calls this. */
    public static function reset(): void
    {
        self::$routes = null;
    }

    /**
     * Every route, in matching order.
     *
     * @return list<array{key: string, pattern: string, template: string, query: array<string, string>}>
     */
    public static function all(): array
    {
        if (self::$routes !== null) {
            return self::$routes;
        }

        $routes = [];
        $seen = [];

        foreach (self::CORE_ROUTES as $route) {
            $routes[] = self::normalise($route);
            $seen[$route['key']] = true;
        }

        foreach (ModuleRegistry::all() as $moduleKey => $module) {
            foreach ($module->publicRoutes() as $route) {
                $key = (string) ($route['key'] ?? '');

                if ($key === '' || isset($seen[$key])) {
                    error_log(sprintf(
                        '[RouteTable] module "%s" contributed a route with a missing or duplicate key "%s"',
                        $moduleKey,
                        $key
                    ));

                    continue;
                }

                $seen[$key] = true;
                $routes[] = self::normalise($route);
            }
        }

        // Last on purpose: a page path matches almost anything, so every route
        // that owns a word must have had its chance first. One segment is a
        // root page, more are a nested one (docs/pages/NESTING.md); the first
        // segment of every page path is a root page's slug, which can never be
        // a word a route above owns (App\Service\Routing\ReservedPaths).
        $routes[] = self::normalise([
            'key' => self::PAGE_ROUTE,
            'pattern' => '{slug+}',
            'template' => 'pagina.php',
            'query' => ['slug' => 'slug'],
        ]);

        return self::$routes = $routes;
    }

    /**
     * @param array<string, mixed> $route
     * @return array{key: string, pattern: string, template: string, query: array<string, string>}
     */
    private static function normalise(array $route): array
    {
        return [
            'key' => (string) $route['key'],
            'pattern' => trim((string) $route['pattern'], '/'),
            'template' => (string) $route['template'],
            'query' => array_map('strval', (array) ($route['query'] ?? [])),
        ];
    }
}
