<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;

/**
 * The public read model for shop collections: what /shop's Collections
 * section and the /collecties/{slug} page render.
 *
 * Same shape and responsibilities as App\Service\PortfolioGalleryContent —
 * a thin, cached, sanitizing layer between the repository and the public
 * templates, so no public page ever touches raw rows, and every read is
 * safe against an unreachable database (it degrades to "no collections"
 * rather than a fatal error).
 *
 * Two rules are enforced here rather than in the templates, so they hold for
 * every caller:
 *   - an INACTIVE collection is invisible: it is neither listed on /shop nor
 *     reachable as a collection page (forPublicPage() returns null, which is
 *     what makes collectie.php answer the project's standard 404).
 *   - a collection's products are always the CURRENTLY ACTIVE ones, read
 *     through ProductRepository, in the collection's own pivot order. A
 *     product deactivated in the CMS therefore disappears from every
 *     collection at once, without any relation being rewritten.
 *
 * Descriptions are sanitized again on read, defensively, even though
 * api/admin/create-collection.php and update-collection.php already sanitize
 * on write — the same "sanitize on write, sanitize again on read" pattern
 * DescriptionSanitizer/RichTextSanitizer are used with everywhere else in
 * this project.
 */
class CollectionContent
{
    /** @var array<string, mixed> */
    private static array $cache = [];

    /**
     * Active collections that actually contain at least one active product,
     * for the Collections section on /shop — in CMS order (sort_order).
     *
     * Empty collections are skipped on purpose: a tile that opens onto an
     * empty product grid is worse than no tile, and the owner can publish
     * the collection before filling it without that half-finished state
     * showing up on the shop.
     *
     * @return array<int, array{id:int, slug:string, name_nl:string, name_en:string, description_nl:string, description_en:string, image_path:?string, product_count:int, url:string}>
     */
    public static function activeForShop(): array
    {
        if (array_key_exists('shop', self::$cache)) {
            return self::$cache['shop'];
        }

        try {
            $rows = (new CollectionRepository())->findActiveWithActiveProductCounts();
        } catch (\Throwable $e) {
            error_log('[CollectionContent] activeForShop lookup failed: ' . $e->getMessage());

            return self::$cache['shop'] = [];
        }

        return self::$cache['shop'] = array_map(
            static fn (array $row): array => self::mapRow($row, (int) $row['product_count']),
            $rows
        );
    }

    /**
     * One collection for its public page, or null when it does not exist or
     * is not published. collectie.php turns null into the same 404 an unknown
     * slug produces, so an inactive collection is indistinguishable from a
     * non-existent one — unpublished content cannot leak through this route.
     *
     * @return array{id:int, slug:string, name_nl:string, name_en:string, description_nl:string, description_en:string, image_path:?string, product_count:int, url:string}|null
     */
    public static function forPublicPage(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        try {
            $collection = (new CollectionRepository())->findBySlug($slug);
        } catch (\Throwable $e) {
            error_log('[CollectionContent] forPublicPage lookup failed for "' . $slug . '": ' . $e->getMessage());

            return null;
        }

        if ($collection === null || !(bool) $collection['is_active']) {
            return null;
        }

        return self::mapRow($collection, null);
    }

    /**
     * The public URL of a collection. The single place that knows the
     * /collecties/ prefix — canonical tags, shop tiles and admin "live on"
     * links all go through here (or through AppUrl::canonical() with this
     * path), so the route exists in exactly one string.
     */
    public static function publicPath(string $slug): string
    {
        return '/collecties/' . $slug;
    }

    /**
     * The absolute canonical URL of a collection page. The counterpart of
     * App\Service\PageContent::canonicalUrl() and
     * App\Service\ProductSeo::canonicalUrl() — the sitemap and the page's own
     * <link rel="canonical"> both call this, so the two cannot drift apart.
     *
     * @param array<string, mixed> $collection a mapped row from forPublicPage()/activeForShop()
     */
    public static function canonicalUrl(array $collection): string
    {
        return self::canonicalUrlForSlug((string) $collection['slug']);
    }

    /**
     * The same URL from a bare slug, for callers that hold a raw
     * `collections` row rather than a mapped one — App\Service\Sitemap, which
     * must build a collection's URL with exactly this code and not a second
     * copy of it.
     */
    public static function canonicalUrlForSlug(string $slug): string
    {
        return AppUrl::canonical(ltrim(self::publicPath($slug), '/'));
    }

    /**
     * The complete <title> text for one language.
     *
     * A custom SEO title (meta_title/meta_title_en) IS the whole title and is
     * rendered verbatim — the same rule PageContent::seoTitle() and
     * ProductSeo::title() apply. Left empty it falls back to
     * "<collection name> | Shop — <site name>", the exact wording
     * collectie.php hardcoded before collections had SEO fields, so switching
     * the fields on changes no existing title.
     *
     * @param array<string, mixed> $collection a mapped row from forPublicPage()/activeForShop()
     */
    public static function seoTitle(array $collection, string $lang = 'nl'): string
    {
        $custom = Seo::pick($collection['meta_title'] ?? '', $collection['meta_title_en'] ?? '', $lang);

        if ($custom !== '') {
            return $custom;
        }

        return Seo::shopTitle(Seo::pick($collection['name_nl'] ?? '', $collection['name_en'] ?? '', $lang));
    }

    /**
     * The collection's meta description for one language.
     *
     * A custom meta description is returned exactly as the owner typed it.
     * With none, the description they already wrote is stripped of its
     * markup, whitespace-collapsed and cut at a sensible length on a word
     * boundary — which is what a search result should show, and avoids a
     * second copy of the same sentence that could drift. Returns '' when
     * there is neither, so collectie.php can omit the tag entirely rather
     * than emit an empty one — the same rule partials/page-head.php applies
     * for CMS pages.
     *
     * @param array<string, mixed> $collection a mapped row from forPublicPage()/activeForShop()
     */
    public static function metaDescription(array $collection, string $lang = 'nl'): string
    {
        $custom = Seo::pick($collection['meta_description'] ?? '', $collection['meta_description_en'] ?? '', $lang);

        if ($custom !== '') {
            return $custom;
        }

        return self::excerpt(Seo::pick($collection['description_nl'] ?? '', $collection['description_en'] ?? '', $lang));
    }

    /**
     * The collection's social-sharing image as a stored site-relative path,
     * or null to let partials/og-meta.php fall back to the site-wide
     * `og_image_path` Site Setting.
     *
     * Deterministic, most specific first:
     *   1. the collection's own SEO/social image (og_image_path);
     *   2. its normal collection image — the one already shown on the shop
     *      tile and at the top of the collection page;
     *   3. the first public photo of the first active product in the
     *      collection, resolved through App\Service\ProductSeo::imagePaths()
     *      so a variant product contributes the same photo the shop grid
     *      shows for it;
     *   4. null -> the global site image.
     *
     * Step 3 only runs when the collection has no image of its own, and stops
     * at the first product that actually has a photo.
     *
     * @param array<string, mixed> $collection a mapped row from forPublicPage()/activeForShop()
     */
    public static function socialImagePath(array $collection): ?string
    {
        $own = trim((string) ($collection['og_image_path'] ?? ''));
        if ($own !== '') {
            return $own;
        }

        $image = trim((string) ($collection['image_path'] ?? ''));
        if ($image !== '') {
            return $image;
        }

        try {
            $products = (new ProductRepository())->findAllActive((int) $collection['id']);
        } catch (\Throwable $e) {
            error_log('[CollectionContent] social image product lookup failed: ' . $e->getMessage());

            return null;
        }

        foreach ($products as $product) {
            $paths = ProductSeo::imagePaths((int) $product['id'], $product['image_path'] ?? null);
            if ($paths !== []) {
                return $paths[0];
            }
        }

        return null;
    }

    /**
     * A short plain-text teaser from a collection's description — used by
     * metaDescription() above and by the optional line of copy on the /shop
     * collection tiles, so both read from the one description field.
     *
     * Delegates to App\Service\Seo so pages, products and collections all
     * derive a fallback description with exactly one implementation.
     */
    public static function excerpt(string $html, int $maxLength = Seo::FALLBACK_DESCRIPTION_LENGTH): string
    {
        return Seo::excerpt($html, $maxLength);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * The four SEO text columns and og_image_path are carried through RAW
     * (trimmed, never sanitized as HTML and never rewritten): they are plain
     * text destined for <meta> attributes, and seoTitle()/metaDescription()
     * are the only things that interpret them. An empty value stays '' so
     * those two methods can apply the fallback.
     *
     * @param array<string, mixed> $row
     * @return array{id:int, slug:string, name_nl:string, name_en:string, description_nl:string, description_en:string, image_path:?string, meta_title:string, meta_title_en:string, meta_description:string, meta_description_en:string, og_image_path:?string, product_count:int, url:string}
     */
    private static function mapRow(array $row, ?int $productCount): array
    {
        $nameNl = (string) $row['name'];
        $descriptionNl = RichTextSanitizer::sanitize($row['description'] ?? null) ?? '';
        $descriptionEn = RichTextSanitizer::sanitize($row['description_en'] ?? null);
        $imagePath = (string) ($row['image_path'] ?? '');
        $ogImagePath = trim((string) ($row['og_image_path'] ?? ''));

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name_nl' => $nameNl,
            'name_en' => self::valueOrDefault($row['name_en'] ?? null, $nameNl),
            'description_nl' => $descriptionNl,
            'description_en' => $descriptionEn ?? $descriptionNl,
            'image_path' => $imagePath === '' ? null : $imagePath,
            'meta_title' => trim((string) ($row['meta_title'] ?? '')),
            'meta_title_en' => trim((string) ($row['meta_title_en'] ?? '')),
            'meta_description' => trim((string) ($row['meta_description'] ?? '')),
            'meta_description_en' => trim((string) ($row['meta_description_en'] ?? '')),
            'og_image_path' => $ogImagePath === '' ? null : $ogImagePath,
            'product_count' => $productCount ?? 0,
            'url' => self::publicPath((string) $row['slug']),
        ];
    }

    /**
     * The project-wide bilingual fallback: a blank `_en` field falls back to
     * the Dutch value (see PortfolioGalleryContent's identical helper).
     */
    private static function valueOrDefault(?string $value, string $default): string
    {
        $value = trim((string) $value);

        return $value === '' ? $default : $value;
    }
}
