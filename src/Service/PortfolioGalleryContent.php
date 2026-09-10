<?php

namespace App\Service;

use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\PortfolioItemImageRepository;

/**
 * The Portfolio CATALOGUE: the items themselves, their categories and their
 * project detail pages. It is a content catalogue with its own CRUD,
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
    /**
     * Filter-bar fallback when portfolio_categories is unreachable — the
     * three categories the site has always shipped with, so a database
     * outage degrades to a known set rather than to an empty/broken filter
     * bar.
     */
    private const FALLBACK_FILTER_CATEGORIES = [
        ['slug' => 'hout', 'name_nl' => 'Hout', 'name_en' => 'Wood'],
        ['slug' => 'metaal', 'name_nl' => 'Metaal', 'name_en' => 'Metal'],
        ['slug' => 'zakelijk', 'name_nl' => 'Zakelijk', 'name_en' => 'Business'],
    ];

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

        return self::$cache[$cacheKey] = array_map(
            static fn (array $item): array => self::mapItemRow($item, $categoriesByItemId),
            $items
        );
    }

    /**
     * Categories to show in a gallery block's filter bar: only those used by
     * at least one currently visible item, in their own admin-managed order
     * — see App\Repository\PortfolioCategoryRepository::findUsedByActiveItems().
     * Falls back to FALLBACK_FILTER_CATEGORIES if the database is
     * unreachable, so the filter bar never breaks.
     *
     * @return list<array{slug: string, name_nl: string, name_en: string}>
     */
    public static function filterCategories(): array
    {
        try {
            $categories = (new PortfolioCategoryRepository())->findUsedByActiveItems();
        } catch (\Throwable $e) {
            error_log('[PortfolioGalleryContent] filterCategories falling back: ' . $e->getMessage());

            return self::FALLBACK_FILTER_CATEGORIES;
        }

        return array_map(static function (array $category): array {
            $nameNl = (string) $category['name_nl'];

            return [
                'slug' => (string) $category['slug'],
                'name_nl' => $nameNl,
                'name_en' => self::valueOrDefault($category['name_en'] ?? null, $nameNl),
            ];
        }, $categories);
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
     * Clears the in-process cache — used by the admin save handlers right
     * after writing a new value, and by tests.
     *
     * Also clears App\Service\ItemGalleryContent, which caches whole
     * rendered item lists built from these rows: a catalogue change that
     * left that derived cache standing would show the old items for the rest
     * of the request.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        ItemGalleryContent::clearCache();
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
     * `url` is the item's own project page when it has one (which is what
     * gives the card its "opens its own page" arrow); a card without one
     * gets no URL here, and the BLOCK decides whether to point it somewhere
     * else (its `fallback_link_url`) or leave it plain and lightbox-able.
     *
     * @param array<string, mixed> $item
     * @param array<int, list<string>> $categoriesByItemId from categorySlugsByItemIds()
     * @return array<string, mixed>
     */
    private static function mapItemRow(array $item, array $categoriesByItemId = []): array
    {
        $titleNl = (string) $item['title_nl'];
        $subtitleNl = (string) $item['subtitle_nl'];

        $slug = (string) ($item['slug'] ?? '');
        $hasDetailPage = !empty($item['has_detail_page']) && $slug !== '';

        return [
            'image_path' => (string) $item['image_path'],
            'alt_nl' => (string) $item['alt_nl'],
            'alt_en' => self::valueOrDefault($item['alt_en'] ?? null, (string) $item['alt_nl']),
            'title_nl' => $titleNl,
            'title_en' => self::valueOrDefault($item['title_en'] ?? null, $titleNl),
            'subtitle_nl' => $subtitleNl,
            'subtitle_en' => self::valueOrDefault($item['subtitle_en'] ?? null, $subtitleNl),
            'categories' => implode(' ', $categoriesByItemId[(int) $item['id']] ?? []),
            'url' => $hasDetailPage ? self::publicPath($slug) : '',
            'is_detail_link' => $hasDetailPage,
        ];
    }

    /**
     * The public URL of a Portfolio project's detail page. The single place
     * that knows the /portfolio/ prefix: portfolio-detail.php's canonical
     * tag, its og:url and App\Service\Sitemap all resolve it through here,
     * so the sitemap can never list a project under a URL different from the
     * one its own page declares canonical.
     *
     * The counterpart of App\Service\CollectionContent::publicPath() and
     * App\Service\ProductSeo::publicPath().
     */
    public static function publicPath(string $slug): string
    {
        return '/portfolio/' . $slug;
    }

    public static function canonicalUrlForSlug(string $slug): string
    {
        return AppUrl::canonical(ltrim(self::publicPath($slug), '/'));
    }

    /**
     * The public project detail page (portfolio-detail.php?slug=...): the
     * full item row (including intro/description NL+EN) plus its additional
     * gallery images, or null when no active item with a detail page enabled
     * matches this slug — the template then renders a 404. A detail page
     * either exists as CMS content or it doesn't; there is nothing to fall
     * back to.
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

        $titleNl = (string) $item['title_nl'];
        $subtitleNl = (string) $item['subtitle_nl'];

        try {
            $categories = $repository->categoriesForItemId((int) $item['id']);
        } catch (\Throwable $e) {
            error_log('[PortfolioGalleryContent] itemForDetailPage categories lookup failed for "' . $slug . '": ' . $e->getMessage());
            $categories = [];
        }

        // Sanitized again here, defensively, even though
        // api/admin/update-portfolio-item.php already sanitizes on save —
        // nothing renders this HTML without going through this first, same
        // "sanitize on write, sanitize again on read" pattern as
        // DescriptionSanitizer/api/product.php.
        $introNl = RichTextSanitizer::sanitize($item['intro_nl'] ?? null) ?? '';
        $introEn = RichTextSanitizer::sanitize($item['intro_en'] ?? null);
        $descriptionNl = RichTextSanitizer::sanitize($item['description_nl'] ?? null) ?? '';
        $descriptionEn = RichTextSanitizer::sanitize($item['description_en'] ?? null);

        return [
            'slug' => (string) $item['slug'],
            'image_path' => (string) $item['image_path'],
            'alt_nl' => (string) $item['alt_nl'],
            'alt_en' => self::valueOrDefault($item['alt_en'] ?? null, (string) $item['alt_nl']),
            'title_nl' => $titleNl,
            'title_en' => self::valueOrDefault($item['title_en'] ?? null, $titleNl),
            'subtitle_nl' => $subtitleNl,
            'subtitle_en' => self::valueOrDefault($item['subtitle_en'] ?? null, $subtitleNl),
            'categories' => array_map(static function (array $category): array {
                $nameNl = (string) $category['name_nl'];

                return [
                    'slug' => (string) $category['slug'],
                    'name_nl' => $nameNl,
                    'name_en' => self::valueOrDefault($category['name_en'] ?? null, $nameNl),
                ];
            }, $categories),
            'intro_nl' => $introNl,
            'intro_en' => $introEn ?? $introNl,
            'description_nl' => $descriptionNl,
            'description_en' => $descriptionEn ?? $descriptionNl,
            'images' => array_map(static function (array $image): array {
                $altNl = (string) ($image['alt_nl'] ?? '');
                $imagePath = (string) $image['image_path'];

                return [
                    'image_path' => $imagePath,
                    // Falls back to the full image when no thumbnail was
                    // generated (pre-existing rows — see the thumbnail_path
                    // migration's docblock): the gallery grid always has an
                    // image to show, just not always the smaller one.
                    'thumbnail_path' => self::valueOrDefault($image['thumbnail_path'] ?? null, $imagePath),
                    'alt_nl' => $altNl,
                    'alt_en' => self::valueOrDefault($image['alt_en'] ?? null, $altNl),
                ];
            }, $images),
        ];
    }
}
