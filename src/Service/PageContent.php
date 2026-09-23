<?php

declare(strict_types=1);

namespace App\Service;

use App\Module\ModuleRegistry;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\Routing\RequestLanguage;

/**
 * Public read side of the unified CMS page model (pagina.php, the six
 * system page templates, App\Service\LinkResolver, admin/page.php) — plus
 * the small set of derived values every caller would otherwise re-derive:
 * a page's public URL, its canonical path, and its resolved SEO title/meta
 * description per language.
 *
 * A `pages` row carries no text since Multilingual 2.0 phase 2: the title and
 * the SEO fields live per language in `page_translations`, and the SEO
 * methods below read them through App\Service\PageLocalization, the one
 * owner of that table and of the fallback.
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
    public static function forSlug(string $slug, ?string $language = null): ?array
    {
        if ($slug === '') {
            return null;
        }

        // Since Multilingual 2.0 phase 6 a slug belongs to ONE language
        // (docs/multilingual/ROUTING.md): /over-ons asks for the page whose
        // Dutch address is "over-ons", /en/about-us for the page whose
        // English address is "about-us". A lookup that fell through to
        // another language would answer a German URL with Dutch content,
        // which is precisely what this phase exists to make impossible.
        $language ??= RequestLanguage::current();

        $cacheKey = 'slug:' . $language . ':' . $slug;
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $page = PageLocalization::pageForSlug($slug, $language);

        /**
         * THE NEUTRAL COLUMN IS THE DEFAULT LANGUAGE'S ADDRESS, and it is
         * consulted when `page_translations` has no row for it.
         *
         * db/migrations/20260920100000 keeps the two byte-identical, which is
         * what makes every URL of every existing installation keep answering.
         * This fallback is what keeps that true for a page created AFTER the
         * migration by a path that only wrote `pages.slug` — a fixture, a
         * script, a future endpoint — instead of letting it become a page
         * that exists and cannot be visited.
         *
         * ONLY FOR THE DEFAULT LANGUAGE. Doing it for any other would answer
         * /de/over-ons with the Dutch page, which is the one thing this phase
         * exists to make impossible.
         */
        if ($page === null && $language === \App\Service\Routing\LanguageResolver::defaultLanguage()) {
            try {
                $page = (new PageRepository())->findBySlugPublished($slug);
            } catch (\Throwable $e) {
                error_log('[PageContent] forSlug fallback failed for "' . $slug . '": ' . $e->getMessage());
            }

            // ...and only for a page that has NO address of its own in this
            // language. One that has is reached by that address and by no
            // other (App\Service\Routing\LocalizedSlug::answersTo()).
            if ($page !== null && !\App\Service\Routing\LocalizedSlug::answersTo(
                $slug,
                PageLocalization::slug((int) $page['id'], $language),
                (string) ($page['slug'] ?? ''),
                $language
            )) {
                $page = null;
            }
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

        // Switched off, or switched on but not answering at this path right
        // now (ModuleDefinition::pausedPublicPaths()): either way the URL does
        // not show this page, so nothing may advertise it.
        return ModuleRegistry::disabledModuleForRoutePath((string) $page['route_path']) === null
            && !ModuleRegistry::isPausedRoutePath((string) $page['route_path']);
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
    public static function publicUrl(array $page, ?string $language = null): string
    {
        $language ??= RequestLanguage::current();

        $localized = self::localizedPath($page, $language);
        if ($localized !== null) {
            return $localized;
        }

        /**
         * NO VERSION IN THIS LANGUAGE, so this link goes to the DEFAULT
         * language's address (docs/multilingual/ROUTING.md, "Links to content
         * without a route in this language").
         *
         * That is the opposite of what the language SWITCH does, and
         * deliberately so. A menu item, a card or a CTA is somebody asking to
         * go THERE: landing on a real page in another language beats landing
         * on nothing, and the page they land on says in its own canonical tag
         * and <html lang> which language it is. "Read this page in German",
         * on the other hand, has no honest answer when there is no German
         * page — so App\Service\Routing\LanguageSwitch renders that option
         * unavailable instead of sending the visitor somewhere else.
         */
        return self::localizedPath($page, \App\Service\Routing\LanguageResolver::defaultLanguage())
            ?? \App\Service\Routing\LocalizedUrl::path('/' . ltrim((string) $page['slug'], '/'), $language);
    }

    /**
     * This page's address IN THIS LANGUAGE, or null when it has no version
     * there.
     *
     * The honest half of publicUrl(): what the language switch, the hreflang
     * block and the sitemap ask, because all three may only ever name a URL
     * that really answers.
     *
     * A ROUTE-BOUND page has a version in every language: its address is its
     * route, there is no slug that could be missing, and /en/shop.php is the
     * English rendering of the same template. A page whose module is switched
     * off has none at all — that URL answers 404 in every language.
     *
     * @param array<string, mixed> $page
     */
    public static function localizedPath(array $page, string $language): ?string
    {
        if (self::isRouteBound($page)) {
            return self::isServedByAnEnabledModule($page)
                ? \App\Service\Routing\LocalizedUrl::path(trim((string) $page['route_path']), $language)
                : null;
        }

        $slug = self::localizedSlug($page, $language);

        return $slug === null
            ? null
            : \App\Service\Routing\LocalizedUrl::path('/' . $slug, $language);
    }

    /**
     * THE address of one page in one language, as a bare slug, or null when
     * it has none there.
     *
     * One definition, used by the URL builder above and by the write side
     * (App\Service\PageService), so "has this language an address" cannot be
     * answered two ways.
     *
     * The rule itself is App\Service\Routing\LocalizedSlug's, shared with
     * the Blog and the Shop so all three answer it the same way.
     *
     * A route-bound page has no slug in any language; its address is its
     * route.
     *
     * @param array<string, mixed> $page
     */
    public static function localizedSlug(array $page, string $language): ?string
    {
        if (self::isRouteBound($page)) {
            return null;
        }

        return \App\Service\Routing\LocalizedSlug::resolve(
            PageLocalization::slug((int) ($page['id'] ?? 0), $language),
            (string) ($page['slug'] ?? ''),
            $language
        );
    }

    /**
     * Every language this page can be reached in, code => site-relative path,
     * for App\Service\Routing\LanguageAlternates.
     *
     * @param array<string, mixed> $page
     * @return array<string, string>
     */
    public static function localizedPaths(array $page): array
    {
        $paths = [];

        foreach (\App\Service\Language\SiteLanguages::activeCodes() as $code) {
            $path = self::localizedPath($page, $code);

            if ($path !== null) {
                $paths[$code] = $path;
            }
        }

        return $paths;
    }

    /**
     * Whether a page stays in the Pages overview for what an editor typed in
     * its search field: its name — what the overview itself prints,
     * PageLocalization::name() — or the address it is served at — the
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

        return mb_stripos(PageLocalization::name((int) ($page['id'] ?? 0)), $query) !== false
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
    public static function canonicalPath(array $page, ?string $language = null): string
    {
        return ltrim(self::publicUrl($page, $language), '/');
    }

    /**
     * The page's absolute canonical URL. The counterpart of
     * App\Service\ProductSeo::canonicalUrl() and
     * App\Service\CollectionContent::canonicalUrl(): partials/page-head.php
     * and the sitemap both call this, so a page can never be listed in the
     * sitemap under a URL different from its own <link rel="canonical">.
     */
    public static function canonicalUrl(array $page, ?string $language = null): string
    {
        return AppUrl::canonical(self::canonicalPath($page, $language));
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
     * Both halves follow the field fallback of App\Service\PageLocalization:
     * the SEO title in $lang, else the default language's SEO title; and only
     * when neither exists, the page's name in $lang, else in the default
     * language. An English page with an English name therefore never
     * advertises its Dutch one, and a page nobody translated reads exactly
     * like the default language.
     */
    public static function seoTitle(?array $page, string $lang): string
    {
        $siteName = SiteSettings::get('site_name');

        if ($page === null) {
            return $siteName;
        }

        $pageId = (int) ($page['id'] ?? 0);

        $custom = PageLocalization::value($pageId, PageTranslation::META_TITLE, $lang);

        if ($custom !== '') {
            return $custom;
        }

        $title = PageLocalization::title($pageId, $lang);

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
     * The page's meta description for one language — with the default
     * language's as the fallback (App\Service\PageLocalization) — or '' when
     * it has none (in which case no <meta name="description"> tag is rendered
     * at all — an empty description tag is worse than none).
     */
    public static function metaDescription(?array $page, string $lang): string
    {
        if ($page === null) {
            return '';
        }

        return PageLocalization::value((int) ($page['id'] ?? 0), PageTranslation::META_DESCRIPTION, $lang);
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
        PageLocalization::clearCache();
    }
}
