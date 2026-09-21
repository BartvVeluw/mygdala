<?php

namespace App\Service;

use App\Repository\PageRepository;
use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\PortfolioItemImageRepository;

/**
 * The Portfolio CATALOGUE: the items themselves, their categories and the page
 * each item may link to. It is a content catalogue with its own CRUD,
 * taxonomy and uploaded media (admin/portfolio.php), deliberately outside
 * App\Service\SectionRegistry — see that class's docblock.
 *
 * Where those items are SHOWN is a separate question, and since phase 4 of
 * docs/content-blocks/ROADMAP.md this class no longer answers it. It used to
 * own two page sections of its own — a hardcoded "portfolio:gallery"
 * (page_slug, section_key) pair for the Portfolio grid and a
 * featuredForHomepage() read path for the homepage teaser, each with its own
 * visibility flag and its own hardcoded fallback item list. Both are now
 * instances of one ordinary block, App\Service\ItemGalleryContent, which
 * reads its items through catalogueItems() below. This class supplies items;
 * the block decides which of them to render, with what settings, on which
 * page.
 *
 * Categories are CMS-managed (App\Repository\PortfolioCategoryRepository,
 * portfolio_categories + the portfolio_item_categories many-to-many), not a
 * fixed list — filterCategories() builds the values a gallery block's filter
 * bar renders as `data-filter`, and mapItemRow() builds the matching
 * `data-category` tokens per item, using each category's stable `slug` so
 * `assets/js/blocks/item-gallery.js`'s `initFilters()` keeps matching on the same
 * space-separated-token scheme it always has.
 *
 * A PROJECT PAGE IS AN ORDINARY CMS PAGE. An item stores at most one `page_id`
 * (db/migrations/20260914200000_link_a_portfolio_item_to_a_page.php), and this
 * class turns it into an address per request, only ever through a page the
 * public may see: published, and not served by a switched-off module — the
 * rule App\Service\LinkResolver applies to a menu link. A renamed page is
 * followed, a draft or deleted page is no link, and no address is stored
 * anywhere. That page's words, SEO, canonical and sitemap entry are its own.
 *
 * THE OLD PROJECT PAGE, kept for its address during the transition. Before the
 * link the Portfolio owned its project pages at /portfolio/<slug>
 * (has_detail_page, slug, intro, description, portfolio_item_images). Nothing
 * edits them any more; the columns and the photos stay (MODULES.md,
 * "Portfolio"). /portfolio/<slug> is a compatibility route now: it redirects,
 * temporarily, to the published page its item links to
 * (legacyProjectRedirectUrl()), or, while there is none, still shows the old
 * page (itemForDetailPage()) — never a silent 404. Meanwhile the item's card
 * links to that old page (mapItemRow()), and legacyProjectPagesForSitemap()
 * lists exactly the addresses that still show one.
 *
 * WORDS PER LANGUAGE (Multilingual 2.0 phase 5 wave A). An item's alt text,
 * title and subtitle, a category's name, and the old project page's intro,
 * description and photo alt texts are stored per website language in the
 * typed tables of App\Service\PortfolioLocalization and come out of it as one
 * LocalizedValue each, the fallback already applied. Everything else about an
 * item — which page it links to, its categories, its image, whether it is
 * active or featured, its order — is language-neutral and unchanged. This
 * class decides no language itself, and a third language is a row in
 * `site_languages`.
 *
 * There is deliberately no hardcoded DEFAULTS item list any more. It existed
 * as a "database unreachable" safety net for a template that rendered the
 * grid directly; a block is only reached through
 * App\Service\SectionRegistry::renderPage(), which renders nothing at all
 * when the page lookup fails, so the net could never actually catch
 * anything. Every block since phase 2 follows the same rule: no row, no
 * render — see docs/content-blocks/DECISIONS.md.
 */
class PortfolioGalleryContent
{
    /** @var array<string, list<array<string, mixed>>> */
    private static array $cache = [];

    /**
     * The catalogue's visible items, ready for a gallery block to render —
     * every active item in its CMS order, or only the ones curated for a
     * homepage-style teaser ("Toon op homepage" per item, in their own
     * `featured_sort_order`).
     *
     * Whatever comes back — including an empty list — is authoritative: the
     * items an administrator has left visible are the items to show.
     *
     * @return list<array<string, mixed>> see mapItemRow() for the shape
     */
    public static function catalogueItems(bool $featuredOnly = false): array
    {
        $cacheKey = $featuredOnly ? 'featured' : 'all';
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $repository = new PortfolioGalleryRepository();
            $catalogue = $repository->findCatalogue();
        } catch (\Throwable $e) {
            error_log('[PortfolioGalleryContent] catalogue lookup failed: ' . $e->getMessage());

            return self::$cache[$cacheKey] = [];
        }

        if ($catalogue === null) {
            return self::$cache[$cacheKey] = [];
        }

        try {
            $items = $featuredOnly
                ? $repository->findFeaturedItemsByGalleryId((int) $catalogue['id'])
                : $repository->findItemsByGalleryId((int) $catalogue['id'], true);
        } catch (\Throwable $e) {
            error_log('[PortfolioGalleryContent] item lookup failed: ' . $e->getMessage());

            return self::$cache[$cacheKey] = [];
        }

        $categoriesByItemId = self::categorySlugsByItemIds($items);
        $pagesById = self::publishedPagesById($items);

        // One query for the words of every card on the page, the way
        // App\Service\Blocks\BlockLocalization::preloadSections() does it for
        // a block's own words.
        PortfolioLocalization::preloadItems(array_map(
            static fn (array $item): int => (int) $item['id'],
            $items
        ));

        return self::$cache[$cacheKey] = array_map(
            static fn (array $item): array => self::mapItemRow($item, $categoriesByItemId, $pagesById),
            $items
        );
    }

    /**
     * Categories to show in a gallery block's filter bar: only those used by
     * at least one currently visible item, in their own admin-managed order
     * — see App\Repository\PortfolioCategoryRepository::findUsedByActiveItems().
     * A lookup that fails is no filter bar, the same "no row, no render" rule
     * every block follows; there is deliberately no hardcoded fallback list
     * any more, for the reason the class docblock gives about the DEFAULTS
     * items: the bar is only ever reached through
     * App\Service\SectionRegistry::renderPage(), which renders nothing at all
     * when the page lookup fails, so the net could never catch anything — and
     * a hardcoded pair of Dutch and English names would be the last fixed
     * NL/EN storage of this module (Multilingual 2.0 phase 5).
     *
     * @return list<array{slug: string, name: \App\Service\Language\LocalizedValue}>
     */
    public static function filterCategories(): array
    {
        try {
            $categories = (new PortfolioCategoryRepository())->findUsedByActiveItems();
        } catch (\Throwable $e) {
            error_log('[PortfolioGalleryContent] filterCategories found none: ' . $e->getMessage());

            return [];
        }

        PortfolioLocalization::preloadCategories(array_map(
            static fn (array $category): int => (int) $category['id'],
            $categories
        ));

        return array_map(static fn (array $category): array => [
            'slug' => (string) $category['slug'],
            'name' => PortfolioLocalization::categoryName((int) $category['id']),
        ], $categories);
    }

    /**
     * Whether an item may link to this page as its project page: an ordinary
     * page at its own /<slug>, built in the page builder and served by
     * pagina.php. Not a page with a template of its own (the homepage, the
     * Portfolio page itself) and not one at a fixed route: neither is a
     * project's page, and a card pointing at one would be a menu link in
     * disguise.
     *
     * A draft may be linked, unlike in the menu and footer pickers
     * (admin/navigation-item.php): a card only ever links to a PUBLISHED page
     * (publishedPagesById()), so an editor can link a page first and publish it
     * when it is ready, while nothing unpublished is shown in the meantime.
     *
     * @param array<string, mixed> $page a `pages` row
     */
    public static function isLinkablePage(array $page): bool
    {
        return !PageContent::hasOwnTemplate($page) && !PageContent::isRouteBound($page);
    }

    /**
     * Every page isLinkablePage() accepts, in the Pages overview's own order —
     * the choices admin/portfolio-item.php offers. Admin-only, so a failing
     * lookup is not caught: an editor must never be shown an empty list and
     * save an item's link away.
     *
     * @return list<array<string, mixed>>
     */
    public static function linkablePages(): array
    {
        return array_values(array_filter(
            (new PageRepository())->findAllForAdmin(),
            static fn (array $page): bool => self::isLinkablePage($page)
        ));
    }

    /**
     * Bulk-fetches the category slugs for a list of item rows in one query
     * (avoids an N+1 when mapping every item in a gallery).
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, list<string>> keyed by portfolio_gallery_items.id
     */
    private static function categorySlugsByItemIds(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $ids = array_map(static fn (array $item): int => (int) $item['id'], $items);

        try {
            return (new PortfolioGalleryRepository())->categorySlugsByItemIds($ids);
        } catch (\Throwable $e) {
            error_log('[PortfolioGalleryContent] categorySlugsByItemIds lookup failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * The pages a list of items links to that a visitor may reach, in one
     * query, keyed by page id. A draft, a deleted page (whose link the
     * database has already set to NULL) and a page whose address a switched-off
     * module took away are simply absent, so an item linking to one gets no
     * link — the rule App\Service\LinkResolver applies to a menu item.
     *
     * An unreachable pages table degrades to no links rather than no gallery.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>> `pages` rows keyed by pages.id
     */
    private static function publishedPagesById(array $items): array
    {
        $pageIds = array_values(array_filter(array_map(
            static fn (array $item): int => (int) ($item['page_id'] ?? 0),
            $items
        )));

        if ($pageIds === []) {
            return [];
        }

        try {
            $pages = (new PageRepository())->findPublishedByIds($pageIds);
        } catch (\Throwable $e) {
            error_log('[PortfolioGalleryContent] linked page lookup failed: ' . $e->getMessage());

            return [];
        }

        return array_filter(
            $pages,
            static fn (array $page): bool => PageContent::isServedByAnEnabledModule($page)
        );
    }

    /**
     * Clears the in-process cache — used by the admin save handlers right
     * after writing a new value, and by tests.
     *
     * Also clears App\Service\ItemGalleryContent, which caches whole
     * rendered item lists built from these rows: a catalogue change that
     * left that derived cache standing would show the old items for the rest
     * of the request. And App\Service\PortfolioLocalization, which caches
     * the words of those same rows per request — a renamed category or a
     * deleted item must not keep answering with what it used to say.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        ItemGalleryContent::clearCache();
        PortfolioLocalization::clearCache();
    }

    private static function valueOrDefault(?string $value, string $default): string
    {
        return ($value !== null && $value !== '') ? $value : $default;
    }

    /**
     * One catalogue row as the normalised gallery item every source of
     * App\Service\ItemGalleryContent returns — so the rendering partial has
     * one code path and knows nothing about portfolios.
     *
     * Deliberately excludes is_featured/featured_sort_order: those are
     * admin/homepage-selection concerns, not something a template needs to
     * render a card.
     *
     * THE LINK, in this order (MODULES.md, "Portfolio"):
     *
     *   1. the current address of the published page the item links to;
     *   2. otherwise, while the item still has its old project page
     *      (has_detail_page and a slug to reach it by), that page's
     *      /portfolio/<slug>: a compatibility link, so a site keeps working
     *      after the upgrade until every old project has an ordinary page;
     *   3. otherwise none: a plain card without the "opens its own page" arrow.
     *
     * A link to a draft or a deleted page is no link, so rule 2 or 3 applies
     * and no unpublished address ever reaches the page.
     *
     * `follows_fallback_link` is false for every portfolio item: the block's
     * `fallback_link_url` never stands in for rule 3. A portfolio card without
     * a page of its own stays unclickable, whatever the block sets for the
     * cards of other sources.
     *
     * @param array<string, mixed> $item
     * @param array<int, list<string>> $categoriesByItemId from categorySlugsByItemIds()
     * @param array<int, array<string, mixed>> $pagesById from publishedPagesById()
     * @return array<string, mixed>
     */
    private static function mapItemRow(array $item, array $categoriesByItemId = [], array $pagesById = []): array
    {
        $itemId = (int) $item['id'];

        $page = $pagesById[(int) ($item['page_id'] ?? 0)] ?? null;
        $oldSlug = (string) ($item['slug'] ?? '');

        if ($page !== null) {
            $url = PageContent::publicUrl($page);
        } elseif (!empty($item['has_detail_page']) && $oldSlug !== '') {
            // In the language the visitor is reading, like the page link above:
            // the old address answers under every prefix.
            $url = \App\Service\Routing\LocalizedUrl::path(self::publicPath($oldSlug));
        } else {
            $url = '';
        }

        return [
            'image_path' => (string) $item['image_path'],
            'alt' => PortfolioLocalization::itemValue($itemId, PortfolioLocalization::ALT),
            'title' => PortfolioLocalization::itemValue($itemId, PortfolioLocalization::TITLE),
            'subtitle' => PortfolioLocalization::itemValue($itemId, PortfolioLocalization::SUBTITLE),
            'categories' => implode(' ', $categoriesByItemId[$itemId] ?? []),
            'url' => $url,
            'is_detail_link' => $url !== '',
            'follows_fallback_link' => false,
        ];
    }

    /**
     * Where an old project address sends its visitor now: the canonical URL of
     * the published page its item links to, or null when there is none — no
     * item with that slug, no link, or a link to a draft, a deleted page or a
     * page whose module is off. portfolio-detail.php answers a URL with a
     * TEMPORARY redirect to it (App\Service\Redirects\Redirect::STATUS_TEMPORARY,
     * the CMS's own "borrowed for now") before it reads anything of the old page.
     *
     * Temporary, not permanent: during the transition /portfolio/<slug> is a
     * compatibility route, and the link behind it is still an editor's to
     * change. Take the link off and the old page answers again; link another
     * page and the address follows it. A 301 would be cached by browsers and
     * keep sending earlier visitors to a target the item no longer has.
     *
     * Resolved per request on the page's id, like the card, so a renamed page
     * is followed without a stored redirect to keep up to date. The target is
     * App\Service\PageContent::canonicalUrl(), the same URL the page declares
     * canonical and the sitemap lists. That is never an address under
     * /portfolio/, so this can neither loop nor chain into another old address.
     *
     * Deliberately not the Redirect Manager (REDIRECTS.md): it refuses a source
     * under the reserved /portfolio/ namespace, never sees a request Apache
     * already routed to portfolio-detail.php, and would store a target that
     * goes stale the moment the link, the page's slug or its status changes.
     * No query string is carried over either: this template is reached through
     * ?slug=, and handing that parameter on to pagina.php would pick a page by
     * it.
     *
     * The item's own visibility plays no part. It decides whether the item is
     * in a gallery; whether its page may be visited is that page's publication.
     */
    public static function legacyProjectRedirectUrl(string $slug): ?string
    {
        if ($slug === '') {
            return null;
        }

        try {
            $item = (new PortfolioGalleryRepository())->findItemBySlug($slug);
        } catch (\Throwable $e) {
            error_log('[PortfolioGalleryContent] legacyProjectRedirectUrl lookup failed for "' . $slug . '": ' . $e->getMessage());

            return null;
        }

        if ($item === null) {
            return null;
        }

        $page = self::publishedPagesById([$item])[(int) ($item['page_id'] ?? 0)] ?? null;

        return $page !== null ? PageContent::canonicalUrl($page) : null;
    }

    /**
     * The old project addresses that still show a page, for
     * App\Module\PortfolioModule's sitemap collector: slug plus last-modified
     * timestamp.
     *
     * Exactly what portfolio-detail.php answers with a page: a visible item
     * with its old project page switched on and a slug to reach it by (the
     * repository's query), and no published page linked — such an address
     * redirects (legacyProjectRedirectUrl()), and an address that redirects
     * does not belong in a sitemap. The linked page is listed by Core's own
     * pages collector under its own canonical, so a project is never listed
     * twice.
     *
     * @return list<array{slug: string, updated_at: ?string}>
     */
    public static function legacyProjectPagesForSitemap(): array
    {
        $items = (new PortfolioGalleryRepository())->findDetailPageItemsForSitemap();
        $pagesById = self::publishedPagesById($items);

        $entries = [];
        foreach ($items as $item) {
            if (isset($pagesById[(int) ($item['page_id'] ?? 0)])) {
                continue;
            }

            $entries[] = ['slug' => (string) $item['slug'], 'updated_at' => $item['updated_at']];
        }

        return $entries;
    }

    /**
     * The address of an old project page. The single place that knows the
     * /portfolio/ prefix: portfolio-detail.php's canonical tag, its og:url and
     * the sitemap collector all resolve it through here, so the sitemap can
     * never list an old project page under a URL different from the one the
     * page declares canonical.
     *
     * The counterpart of App\Service\CollectionContent::publicPath() and
     * App\Service\ProductSeo::publicPath().
     *
     * UNPREFIXED: the address is the same in every language, so a language is
     * only ever its prefix, which App\Service\Routing\LocalizedUrl puts on in
     * canonicalUrlForSlug() and on the card link in mapItemRow().
     */
    public static function publicPath(string $slug): string
    {
        return '/portfolio/' . $slug;
    }

    /**
     * The old project page's canonical in $language, the request's when null:
     * /en/portfolio/<slug> is that page's English version and says so, rather
     * than naming the default language's (docs/multilingual/ROUTING.md, §10).
     */
    public static function canonicalUrlForSlug(string $slug, ?string $language = null): string
    {
        return \App\Service\Routing\LocalizedUrl::absolute(self::publicPath($slug), $language);
    }

    /**
     * The old project page (portfolio-detail.php?slug=...) for an address that
     * has no published page to redirect to: the full item row (including
     * intro/description NL+EN) plus its additional gallery images, or null
     * when no active item with that page switched on matches this slug — the
     * template then renders a 404. That page either exists as the content it
     * always had or it doesn't; there is nothing to fall back to.
     *
     * @return array<string, mixed>|null
     */
    public static function itemForDetailPage(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        try {
            $repository = new PortfolioGalleryRepository();
            $item = $repository->findItemBySlug($slug);
        } catch (\Throwable $e) {
            error_log('[PortfolioGalleryContent] itemForDetailPage lookup failed for "' . $slug . '": ' . $e->getMessage());

            return null;
        }

        if ($item === null || !(bool) $item['is_active'] || !(bool) $item['has_detail_page']) {
            return null;
        }

        try {
            $images = (new PortfolioItemImageRepository())->findByPortfolioItemId((int) $item['id']);
        } catch (\Throwable $e) {
            error_log('[PortfolioGalleryContent] itemForDetailPage image lookup failed for "' . $slug . '": ' . $e->getMessage());
            $images = [];
        }

        $itemId = (int) $item['id'];

        try {
            $categories = $repository->categoriesForItemId($itemId);
        } catch (\Throwable $e) {
            error_log('[PortfolioGalleryContent] itemForDetailPage categories lookup failed for "' . $slug . '": ' . $e->getMessage());
            $categories = [];
        }

        PortfolioLocalization::preloadCategories(array_map(
            static fn (array $category): int => (int) $category['id'],
            $categories
        ));
        PortfolioLocalization::preloadImages(array_map(
            static fn (array $image): int => (int) $image['id'],
            $images
        ));

        return [
            'slug' => (string) $item['slug'],
            'image_path' => (string) $item['image_path'],
            'alt' => PortfolioLocalization::itemValue($itemId, PortfolioLocalization::ALT),
            'title' => PortfolioLocalization::itemValue($itemId, PortfolioLocalization::TITLE),
            'subtitle' => PortfolioLocalization::itemValue($itemId, PortfolioLocalization::SUBTITLE),
            'categories' => array_map(static fn (array $category): array => [
                'slug' => (string) $category['slug'],
                'name' => PortfolioLocalization::categoryName((int) $category['id']),
            ], $categories),
            // Sanitized per language on read, defensively: the editor that
            // wrote this HTML is gone, but nothing renders it without going
            // through PortfolioLocalization::itemRichValue() first — the
            // "sanitize again on read" half of the pattern
            // DescriptionSanitizer/api/product.php follow.
            'intro' => PortfolioLocalization::itemRichValue($itemId, PortfolioLocalization::INTRO),
            'description' => PortfolioLocalization::itemRichValue($itemId, PortfolioLocalization::DESCRIPTION),
            'images' => array_map(static function (array $image): array {
                $imagePath = (string) $image['image_path'];

                return [
                    'image_path' => $imagePath,
                    // Falls back to the full image when no thumbnail was
                    // generated (pre-existing rows — see the thumbnail_path
                    // migration's docblock): the gallery grid always has an
                    // image to show, just not always the smaller one.
                    'thumbnail_path' => self::valueOrDefault($image['thumbnail_path'] ?? null, $imagePath),
                    'alt' => PortfolioLocalization::imageAlt((int) $image['id']),
                ];
            }, $images),
        ];
    }
}
