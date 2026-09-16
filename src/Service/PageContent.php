<?php

declare(strict_types=1);

namespace App\Service;

use App\Module\ModuleRegistry;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\Language\LocalizedValue;

/**
 * Public read side of the unified CMS page model (pagina.php, the six
 * system page templates, App\Service\LinkResolver, admin/page.php) — plus
 * the small set of derived values every caller would otherwise re-derive:
 * a page's public URL, its canonical path, and its resolved SEO title/meta
 * description per language.
 *
 * Static, try/catch-with-fallback, same convention as SiteSettings /
 * NavigationService: a page-lookup failure must never fatal a public
 * request, so every lookup returns null instead of throwing.
 *
 * Unlike PageHeroContent/FeatureGridContent this class keeps NO hardcoded
 * per-page fallback copy. Those classes fall back to the exact text that
 * used to be hardcoded in one specific template; a page's title/SEO text is
 * now genuinely owned by the database for an open-ended set of pages, and
 * shipping a second, drifting copy of it in PHP is precisely what this
 * feature removes. If the database is unreachable, partials/page-head.php
 * degrades to the site name alone — which matters little in practice,
 * because in that same situation every section on the page (all of which
 * are database-backed) renders nothing either.
 */
class PageContent
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED];

    /**
     * Admin-facing status labels (Dutch, like the rest of the admin UI).
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Concept',
        self::STATUS_PUBLISHED => 'Gepubliceerd',
    ];

    /** @var array<string, array<string, mixed>|null> */
    private static array $cache = [];

    /** @var list<int>|null page ids carrying an application-critical block; null = not looked up yet */
    private static ?array $applicationCriticalPageIds = null;

    /**
     * One published page by its public URL slug, or null when it doesn't
     * exist, is still a draft, or the lookup failed. This is the only
     * lookup pagina.php performs — a draft is indistinguishable from a
     * missing page for a visitor.
     *
     * @return array<string, mixed>|null
     */
    public static function forSlug(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        $cacheKey = 'slug:' . $slug;
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        try {
            $page = (new PageRepository())->findBySlugPublished($slug);
        } catch (\Throwable $e) {
            error_log('[PageContent] forSlug lookup failed for "' . $slug . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = null;
        }

        return self::$cache[$cacheKey] = $page;
    }

    /**
     * One page by its immutable content_key, regardless of status — how the
     * six system page templates load their own <head> data (they are always
     * published, and their PHP file is reachable whatever the row says).
     *
     * @return array<string, mixed>|null
     */
    public static function forContentKey(string $contentKey): ?array
    {
        $cacheKey = 'key:' . $contentKey;
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        try {
            $page = (new PageRepository())->findByContentKey($contentKey);
        } catch (\Throwable $e) {
            error_log('[PageContent] forContentKey lookup failed for "' . $contentKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = null;
        }

        return self::$cache[$cacheKey] = $page;
    }

    /**
     * Does this page have its own hand-written PHP template at the project
     * root (index.php, shop.php, ...) instead of being served by pagina.php?
     *
     * This is a RENDERING fact and nothing else. It is deliberately kept
     * separate from isRouteBound()/isProtected() below (see
     * docs/content-blocks/ARCHITECTURE.md, "Pagina's versus routes"): a page
     * having its own template says nothing about whether an administrator
     * may rename or delete it, and the two must not be conflated again.
     */
    public static function hasOwnTemplate(array $page): bool
    {
        return (int) ($page['is_system'] ?? 0) === 1;
    }

    /**
     * Is this page served at a FIXED URL (`route_path`) rather than at
     * /<slug>? Derived from the page's own data — never from a hardcoded
     * list of page names.
     *
     * This answers "can the administrator move this page's URL?", nothing
     * more. It is NOT protection: a page whose URL is fixed is otherwise an
     * ordinary content page — freely editable, unpublishable and deletable
     * unless isProtected() says otherwise.
     */
    public static function isRouteBound(array $page): bool
    {
        return trim((string) ($page['route_path'] ?? '')) !== '';
    }

    /**
     * Does this page's URL still answer? True for every ordinary page. False
     * only for a page served from a MODULE's own template while that module
     * is switched off: the row is untouched and still editable, but
     * /shop.php answers 404 like any other unknown URL, so the sitemap must
     * not list it and nothing else may present it as reachable.
     *
     * Derived from the page's own `route_path` and the module registry —
     * never from a page name. See App\Module\ModuleRegistry.
     */
    public static function isServedByAnEnabledModule(array $page): bool
    {
        if (!self::isRouteBound($page)) {
            return true;
        }

        return ModuleRegistry::disabledModuleForRoutePath((string) $page['route_path']) === null;
    }

    /** Is this the site root — the one URL that must always render? */
    public static function isSiteRoot(array $page): bool
    {
        return trim((string) ($page['route_path'] ?? '')) === '/';
    }

    /**
     * May this page NOT be unpublished or deleted?
     *
     * Exactly two reasons, both derived, neither of them "this page has its
     * own PHP template" (see docs/content-blocks/ARCHITECTURE.md, "Pagina's
     * versus routes"):
     *
     *   1. it is the site root — "/" must always render something;
     *   2. it carries an application-critical block, i.e. functionality the
     *      webshop itself depends on rather than page copy. Today that is
     *      only the storefront's product grid
     *      (App\Service\SectionRegistry::applicationCriticalTypes()), which
     *      is why the Shop stays protected while Diensten, Portfolio, Over
     *      mij and Contact — content pages that merely happen to be served
     *      from their own file — do not.
     *
     * Real application routes (checkout, cart, order status, product and
     * collection detail, the APIs) are not `pages` rows at all, so they are
     * outside the CMS by construction and need no flag here.
     */
    public static function isProtected(array $page): bool
    {
        if (self::isSiteRoot($page)) {
            return true;
        }

        $criticalPageIds = self::applicationCriticalPageIds();

        if ($criticalPageIds === null) {
            // The lookup failed. Fall back to the strictest previous rule
            // (every fixed-URL page is protected) rather than offering a
            // delete button we cannot justify — this is the one place in
            // this class where degrading gracefully means degrading
            // CAUTIOUSLY, because the action behind it is destructive.
            return self::isRouteBound($page);
        }

        return in_array((int) ($page['id'] ?? 0), $criticalPageIds, true);
    }

    /**
     * Page ids carrying at least one application-critical block, or null
     * when that could not be determined. One query, cached per request.
     *
     * @return list<int>|null
     */
    private static function applicationCriticalPageIds(): ?array
    {
        if (self::$applicationCriticalPageIds !== null) {
            return self::$applicationCriticalPageIds;
        }

        try {
            return self::$applicationCriticalPageIds = (new PageSectionRepository())
                ->pageIdsWithSectionTypes(SectionRegistry::applicationCriticalTypes());
        } catch (\Throwable $e) {
            error_log('[PageContent] application-critical page lookup failed: ' . $e->getMessage());

            return null;
        }
    }

    public static function isPublished(array $page): bool
    {
        return (string) ($page['status'] ?? '') === self::STATUS_PUBLISHED;
    }

    /**
     * The page's public URL. A route-bound page keeps its fixed route_path
     * ('/', '/shop.php', ...); every other page lives at /<slug> via
     * .htaccess -> pagina.php.
     */
    public static function publicUrl(array $page): string
    {
        if (self::isRouteBound($page)) {
            return trim((string) $page['route_path']);
        }

        return '/' . ltrim((string) $page['slug'], '/');
    }

    /**
     * Whether a page stays in the Pages overview for what an editor typed in
     * its search field: its title, or the address it is served at — the
     * publicUrl() the overview shows, so a fixed-route page is found by its
     * route and not by a slug nobody sees. Case-insensitive, and an empty
     * search keeps every page.
     *
     * admin/pages.php filters the rows it already loaded with this instead of
     * querying again; a site's list of pages is never long enough to need it.
     *
     * @param array<string, mixed> $page
     */
    public static function matchesAdminSearch(array $page, string $query): bool
    {
        $query = trim($query);

        if ($query === '') {
            return true;
        }

        return mb_stripos((string) ($page['title'] ?? ''), $query) !== false
            || mb_stripos(self::publicUrl($page), $query) !== false;
    }

    /**
     * Where a block editor's "&larr; back" link goes: the page builder for
     * the page that block is attached to, addressed by its content key.
     *
     * There is one of these because the block editors used to guess it
     * themselves, and half of them guessed a URL that stopped existing:
     * /admin/pages.php?page=<slug> is the pages OVERVIEW, which ignores that
     * parameter — so "terug naar Diensten" dropped an editor on the list of
     * every page instead of on the page they were editing. Falling back to
     * that overview is still the right answer for a content key no page
     * carries; it is just no longer the answer for the ones that do.
     */
    public static function builderUrl(string $contentKey): string
    {
        $page = self::forContentKey($contentKey);

        return $page === null
            ? '/admin/pages.php'
            : '/admin/page.php?id=' . (int) $page['id'];
    }

    /**
     * The site-relative path to hand App\Service\AppUrl::canonical() — the
     * public URL without its leading slash, so the homepage ('/') canonicals
     * to the bare base URL exactly as index.php did before.
     */
    public static function canonicalPath(array $page): string
    {
        return ltrim(self::publicUrl($page), '/');
    }

    /**
     * The page's absolute canonical URL. The counterpart of
     * App\Service\ProductSeo::canonicalUrl() and
     * App\Service\CollectionContent::canonicalUrl(): partials/page-head.php
     * and the sitemap both call this, so a page can never be listed in the
     * sitemap under a URL different from its own <link rel="canonical">.
     */
    public static function canonicalUrl(array $page): string
    {
        return AppUrl::canonical(self::canonicalPath($page));
    }

    /**
     * The page's own NAME, as this project's one localized value.
     *
     * `pages.title` is the Dutch column and `title_en` its translation
     * (db/migrations/20260916140000). An empty translation means "the same as
     * the primary language" and never an empty name, which is the rule
     * App\Service\Language\LocalizedValue holds for every other pair of
     * columns in this project — so this is where the pair is read, and the
     * only place that has to know the column names.
     *
     * Callers that print it to a visitor take ::raw() for both halves and let
     * App\Service\Language\SiteText resolve which one is visible
     * (App\Service\Breadcrumbs\PageBreadcrumb); callers that need one
     * language take ::in(), like seoTitle() below. Neither asks "is this
     * English".
     *
     * @param array<string, mixed>|null $page
     */
    public static function titleValue(?array $page): LocalizedValue
    {
        return LocalizedValue::ofDutchEnglish(
            $page === null ? '' : (string) ($page['title'] ?? ''),
            $page === null ? null : ($page['title_en'] ?? null)
        );
    }

    /**
     * The complete <title> text for one language.
     *
     * meta_title, when set, IS the whole title — it is rendered verbatim,
     * which is what lets the six system pages keep their existing,
     * individually-worded titles ("Over mij | Van Veluw Laserdesign —
     * Nijmegen", "Van Veluw Laserdesign | Lasergraveren op hout & metaal in
     * Nijmegen") unchanged while still being fully editable. Left empty, it
     * falls back to "<Title> — <site name>", the convention the CMS
     * information pages already rendered with.
     *
     * An empty English value falls back to the Dutch one, matching
     * PageHeroContent and every other bilingual field in this project. That
     * holds for the automatic title too: the page name it is built from is
     * localized (titleValue()), so an English page with an English name no
     * longer advertises its Dutch one.
     */
    public static function seoTitle(?array $page, string $lang = 'nl'): string
    {
        $siteName = SiteSettings::get('site_name');

        if ($page === null) {
            return $siteName;
        }

        $custom = trim((string) ($page['meta_title'] ?? ''));
        if ($lang === 'en') {
            $customEn = trim((string) ($page['meta_title_en'] ?? ''));
            $custom = $customEn !== '' ? $customEn : $custom;
        }

        if ($custom !== '') {
            return $custom;
        }

        $title = self::titleValue($page)->in($lang);

        if ($title === '' || $title === $siteName) {
            // A page whose title already IS the site name must not get the
            // site name a second time: "Van Veluw Laserdesign — Van Veluw
            // Laserdesign" is the classic homepage title bug, and this is
            // where it would be produced.
            return $siteName !== '' ? $siteName : $title;
        }

        return $siteName === '' ? $title : $title . ' — ' . $siteName;
    }

    /**
     * The page's meta description for one language, or '' when it has none
     * (in which case no <meta name="description"> tag is rendered at all —
     * an empty description tag is worse than none).
     */
    public static function metaDescription(?array $page, string $lang = 'nl'): string
    {
        if ($page === null) {
            return '';
        }

        $value = trim((string) ($page['meta_description'] ?? ''));
        if ($lang === 'en') {
            $valueEn = trim((string) ($page['meta_description_en'] ?? ''));
            $value = $valueEn !== '' ? $valueEn : $value;
        }

        return $value;
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    /**
     * Clears the in-process cache — used by the admin save handlers right
     * after writing, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$applicationCriticalPageIds = null;
    }
}
