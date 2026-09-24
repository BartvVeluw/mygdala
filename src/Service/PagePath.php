<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PageRepository;
use App\Service\Language\SiteLanguages;
use App\Service\Routing\RouteTable;

/**
 * THE public path of a CMS page, now that a page can sit under another page
 * (Pagina's 2.0, docs/pages/NESTING.md).
 *
 *     Metaal graveren                    /metaal-graveren
 *     └── Aluminium visitekaartjes       /metaal-graveren/aluminium-visitekaartjes
 *                                        /en/metal-engraving/aluminium-business-cards
 *
 * A page's path is the slug of every ancestor, root first, followed by its
 * own — each in the language being asked for. The hierarchy (`parent_id`) is
 * the same in every language; the slugs are per language.
 *
 * ONE SOURCE. App\Service\PageContent::localizedPath() is built on this, and
 * everything that prints a page URL goes through that: menus, the footer,
 * the breadcrumb, a Page link in a block, the language switch, canonical,
 * hreflang, the sitemap, the CMS. pagina.php asks the same class the reverse
 * question (PageContent::forPath()). Nothing else strings slugs together.
 *
 * NO NEW LANGUAGE RULE. Each segment is App\Service\PageContent::localizedSlug()
 * — the page's address in that language, the neutral column counting only for
 * the default language, no fallback to another language
 * (docs/multilingual/ROUTING.md §2). A path exists in a language only when
 * EVERY page on it has an address there: /en/<something>/child cannot exist
 * while /en/<something> does not, so an untranslated ancestor means the child
 * has no English URL either. Links then go to the default language's version
 * (PageContent::publicUrl()), the language switch shows English as
 * unavailable, and neither hreflang nor the sitemap names it — exactly what
 * happens to a page that has no English slug of its own.
 *
 * A PAGE WITH A FIXED URL IS NEVER PART OF A TREE: the homepage, the Shop and
 * the other pages served by their own template have a route instead of a
 * slug. They cannot be a parent (App\Service\PageService::validateParent())
 * and their own path ignores `parent_id`. A chain that nevertheless meets one
 * (a row edited in SQL) has no path.
 *
 * DEFENSIVE ABOUT CYCLES. PageService refuses to store one, but a chain is
 * walked with a visited set and a depth limit all the same: a loop that
 * reached the database some other way ends as "no path" and a log line, never
 * as a request that does not return.
 *
 * PERFORMANCE. The whole tree is ONE query (PageRepository::findStructure()),
 * loaded the first time a nested page is asked about and kept for the rest of
 * the request; the slugs of every page in it come in one more
 * (PageLocalization::preload()). A root page — every page that existed before
 * this class — needs neither: its path is its own slug, as it always was, and
 * costs nothing extra. A sitemap or a pages overview with a hundred nested
 * pages therefore does not grow in queries with its size
 * (Tests\Service\PageNestingTest pins two for twenty).
 *
 * READS NEVER THROW, like PageContent: a tree that could not be loaded is an
 * empty one, so a nested page then has no path and a public request degrades
 * to a 404 rather than an error. This class never writes anything.
 */
final class PagePath
{
    /**
     * The deepest a page may sit, counted in path segments. A guard, not a
     * design goal: the router accepts no longer page path
     * (RouteTable::MAX_PAGE_SEGMENTS), and PageService refuses a move that
     * would go past it.
     */
    public const MAX_DEPTH = RouteTable::MAX_PAGE_SEGMENTS;

    /** @var array<int, array<string, mixed>>|null page id => structure row; null = not loaded */
    private static ?array $nodes = null;

    /** @var array<int, list<int>> parent id (0 = root) => child ids in sibling order */
    private static array $children = [];

    /** @var bool whether the slugs of every page in the tree are in PageLocalization's cache */
    private static bool $slugsLoaded = false;

    /**
     * Every page's structure row, keyed by id: id, parent_id, admin_group,
     * slug, route_path, is_system, status, sort_order.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function nodes(): array
    {
        if (self::$nodes !== null) {
            return self::$nodes;
        }

        try {
            $rows = (new PageRepository())->findStructure();
        } catch (\Throwable $e) {
            error_log('[PagePath] the page tree could not be read: ' . $e->getMessage());
            $rows = [];
        }

        self::index($rows);

        return self::$nodes ?? [];
    }

    /** @return array<string, mixed>|null one page's structure row */
    public static function node(int $pageId): ?array
    {
        return self::nodes()[$pageId] ?? null;
    }

    /**
     * The pages directly under one page, or the root pages for null, in
     * sibling order (sort_order, then id).
     *
     * @return list<int>
     */
    public static function childIds(?int $parentId): array
    {
        self::nodes();

        return self::$children[($parentId !== null && $parentId > 0) ? $parentId : 0] ?? [];
    }

    /** Has this page any page under it? */
    public static function hasChildren(int $pageId): bool
    {
        return self::childIds($pageId) !== [];
    }

    /**
     * Every page below this one, a parent always before its own children.
     * Cycle-safe: a page is never listed twice, nor the page itself.
     *
     * @return list<int>
     */
    public static function descendantIds(int $pageId): array
    {
        $found = [];
        $seen = [$pageId => true];
        $queue = self::childIds($pageId);

        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $found[] = $id;

            foreach (self::childIds($id) as $childId) {
                $queue[] = $childId;
            }
        }

        return $found;
    }

    /**
     * The pages above this one, ROOT FIRST, without the page itself: [] for a
     * root page, null when the chain is broken — a parent that does not exist,
     * a loop, or deeper than MAX_DEPTH.
     *
     * @return list<int>|null
     */
    public static function ancestorIds(int $pageId): ?array
    {
        $node = self::node($pageId);

        return $node === null ? null : self::ancestorsOf(self::parentOf($node), $pageId);
    }

    /**
     * The root page of the tree this page is in (the page itself for a root
     * page), or null when its chain is broken.
     */
    public static function rootId(int $pageId): ?int
    {
        $ancestors = self::ancestorIds($pageId);

        if ($ancestors === null) {
            return null;
        }

        return $ancestors === [] ? $pageId : $ancestors[0];
    }

    /**
     * The one admin group this page is listed under: its root page's
     * (App\Service\PageAdminGroup). A page whose chain is broken reads its own
     * stored value, so it is still listed somewhere.
     */
    public static function effectiveGroup(int $pageId): string
    {
        $rootId = self::rootId($pageId) ?? $pageId;

        return PageAdminGroup::normalise(self::node($rootId)['admin_group'] ?? null);
    }

    /**
     * How deep the subtree under this page goes: 0 for a page without
     * children. What PageService adds to a new parent's depth before it
     * allows a move.
     */
    public static function subtreeHeight(int $pageId): int
    {
        $height = 0;
        $level = self::childIds($pageId);
        $seen = [$pageId => true];

        while ($level !== [] && $height <= self::MAX_DEPTH) {
            $height++;
            $next = [];

            foreach ($level as $id) {
                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                array_push($next, ...self::childIds($id));
            }

            $level = $next;
        }

        return $height;
    }

    /**
     * The slugs of a page's path in one language, root first, or null when
     * the page has no path there.
     *
     * $page is a `pages` row: what a caller already holds. Its own
     * `parent_id` decides where it sits, so a row read a moment ago is not
     * second-guessed by the tree; only its ANCESTORS are read from the tree.
     *
     * @param array<string, mixed> $page
     * @return list<string>|null
     */
    public static function segments(array $page, string $language): ?array
    {
        $own = PageContent::localizedSlug($page, $language);

        if ($own === null) {
            return null;
        }

        $parentId = self::parentOf($page);

        if ($parentId === null) {
            return [$own];
        }

        $prefix = self::parentSegments($parentId, $language, (int) ($page['id'] ?? 0));

        return $prefix === null ? null : [...$prefix, $own];
    }

    /**
     * The page's path in one language WITHOUT a language prefix ('/a/b'), or
     * null when it has none there. What App\Service\PageContent::localizedPath()
     * hands App\Service\Routing\LocalizedUrl.
     *
     * @param array<string, mixed> $page
     */
    public static function path(array $page, string $language): ?string
    {
        $segments = self::segments($page, $language);

        return $segments === null ? null : '/' . implode('/', $segments);
    }

    /**
     * The same question for a page that is not saved yet, or not saved THERE:
     * the path a page with this slug would get under this parent. What the
     * editor's confirmation and the create screen show before anything is
     * written. $pageId excludes the page itself from the chain check, so a
     * move under one of its own descendants reads as "no path".
     */
    public static function prospective(?int $parentId, string $slug, string $language, int $pageId = 0): ?string
    {
        $slug = trim($slug, '/ ');

        if ($slug === '') {
            return null;
        }

        if ($parentId === null || $parentId < 1) {
            return '/' . $slug;
        }

        $prefix = self::parentSegments($parentId, $language, $pageId);

        return $prefix === null ? null : '/' . implode('/', [...$prefix, $slug]);
    }

    /**
     * A page's full path in one language, with that language's prefix
     * (LocalizedUrl): '/metaal-graveren/rvs' or '/en/metal-engraving/steel'.
     * The page is looked up in the tree by id.
     */
    public static function for(int $pageId, string $language): ?string
    {
        $node = self::node($pageId);

        if ($node === null) {
            return null;
        }

        self::loadSlugs();

        return PageContent::localizedPath($node, $language);
    }

    /**
     * Every unprefixed path of these pages in every active language, taken
     * BEFORE a save that may move them, so the paths after it can be compared
     * one by one (App\Service\Redirects\SlugChangeRedirects::recordMoves()).
     *
     * @param list<int> $pageIds
     * @return array<int, array<string, string|null>> page id => language code => path
     */
    public static function snapshot(array $pageIds): array
    {
        self::loadSlugs();

        $snapshot = [];
        foreach ($pageIds as $pageId) {
            $node = self::node((int) $pageId);

            foreach (SiteLanguages::activeCodes() as $code) {
                $snapshot[(int) $pageId][$code] = ($node === null || PageContent::isRouteBound($node))
                    ? null
                    : self::path($node, $code);
            }
        }

        return $snapshot;
    }

    /** Forget the tree. PageContent::clearCache() calls this, so every save does. */
    public static function clearCache(): void
    {
        self::$nodes = null;
        self::$children = [];
        self::$slugsLoaded = false;
    }

    /**
     * Test seam: pretend these are all the pages there are, without a
     * database. Reset with clearCache().
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function overrideForTests(array $rows): void
    {
        self::clearCache();
        self::index($rows);
        self::$slugsLoaded = true;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function index(array $rows): void
    {
        $nodes = [];
        $children = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }

            $nodes[$id] = $row;
        }

        // Sibling order: the rows arrive ordered by the repository, but a test
        // seam or a caller may hand them over in any order.
        uasort($nodes, static fn (array $a, array $b): int => [(int) ($a['sort_order'] ?? 0), (int) $a['id']]
            <=> [(int) ($b['sort_order'] ?? 0), (int) $b['id']]);

        foreach ($nodes as $id => $row) {
            $parentId = self::parentOf($row);
            // A parent that does not exist makes a page unreachable, not
            // invisible: it is listed where an editor can find it and fix it.
            $children[($parentId !== null && isset($nodes[$parentId])) ? $parentId : 0][] = $id;
        }

        self::$nodes = $nodes;
        self::$children = $children;
    }

    /**
     * The slugs from the root down to and including $parentId, or null when
     * any of them has no address in this language or the chain is broken.
     *
     * @return list<string>|null
     */
    private static function parentSegments(int $parentId, string $language, int $pageId): ?array
    {
        $chain = self::ancestorsOf($parentId, $pageId);

        if ($chain === null || count($chain) + 1 > self::MAX_DEPTH) {
            return null;
        }

        self::loadSlugs();

        $segments = [];
        foreach ($chain as $ancestorId) {
            $ancestor = self::node($ancestorId);

            if ($ancestor === null || PageContent::isRouteBound($ancestor)) {
                return null;
            }

            $slug = PageContent::localizedSlug($ancestor, $language);
            if ($slug === null) {
                return null;
            }

            $segments[] = $slug;
        }

        return $segments;
    }

    /**
     * The chain from the root down to AND INCLUDING $parentId, for a page
     * $pageId that sits (or would sit) under it: [] for no parent, null when
     * the chain loops, meets $pageId, leaves the tree or gets too deep.
     *
     * @return list<int>|null
     */
    private static function ancestorsOf(?int $parentId, int $pageId): ?array
    {
        if ($parentId === null) {
            return [];
        }

        $chain = [];
        $seen = [$pageId => true];
        $current = $parentId;

        while ($current !== null) {
            if (isset($seen[$current])) {
                error_log('[PagePath] the page tree loops at page ' . $current . '; page ' . $pageId . ' has no path');

                return null;
            }

            $node = self::node($current);
            if ($node === null) {
                return null;
            }

            $seen[$current] = true;
            array_unshift($chain, $current);

            if (count($chain) > self::MAX_DEPTH) {
                return null;
            }

            $current = self::parentOf($node);
        }

        return $chain;
    }

    /**
     * The slugs of EVERY page in the tree, in one query, the first time a
     * nested path is built in this request — so a list of a hundred nested
     * pages (the sitemap, the Pages overview, a menu) asks for its slugs once,
     * not once per page and once per ancestor. A site's pages times its
     * languages is a small table. A root page on its own never gets here: its
     * path is its own slug, loaded the way it always was.
     */
    private static function loadSlugs(): void
    {
        if (self::$slugsLoaded) {
            return;
        }

        self::$slugsLoaded = true;
        PageLocalization::preload(array_keys(self::nodes()));
    }

    /** @param array<string, mixed> $row */
    private static function parentOf(array $row): ?int
    {
        $parentId = (int) ($row['parent_id'] ?? 0);

        return $parentId > 0 ? $parentId : null;
    }
}
