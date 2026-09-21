<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\Routing\LanguageResolver;
use App\Service\Routing\LocalizedUrl;
use App\Service\Routing\RequestLanguage;
use App\Service\Routing\RouteSegments;

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
     * @return array<int, array{id:int, slug:string, name:string, description:string, image_path:?string, product_count:int, url:string}>
     */
    public static function activeForShop(): array
    {
        if (array_key_exists('shop|' . RequestLanguage::current(), self::$cache)) {
            return self::$cache['shop|' . RequestLanguage::current()];
        }

        try {
            $rows = (new CollectionRepository())->findActiveWithActiveProductCounts();
        } catch (\Throwable $e) {
            error_log('[CollectionContent] activeForShop lookup failed: ' . $e->getMessage());

            return self::$cache['shop|' . RequestLanguage::current()] = [];
        }

        // One query for the words of every tile on /shop.
        ShopLocalization::preloadCollections(array_map(
            static fn (array $row): int => (int) $row['id'],
            $rows
        ));

        $language = RequestLanguage::current();

        return self::$cache['shop|' . $language] = array_map(
            static fn (array $row): array => self::mapRow($row, (int) $row['product_count'], $language),
            $rows
        );
    }

    /**
     * One collection for its public page, or null when it does not exist or
     * is not published. collectie.php turns null into the same 404 an unknown
     * slug produces, so an inactive collection is indistinguishable from a
     * non-existent one — unpublished content cannot leak through this route.
     *
     * @return array{id:int, slug:string, name:string, description:string, image_path:?string, product_count:int, url:string}|null
     */
    public static function forPublicPage(string $slug, ?string $language = null): ?array
    {
        if ($slug === '') {
            return null;
        }

        $language ??= RequestLanguage::current();

        /**
         * THE ADDRESS BELONGS TO ONE LANGUAGE (docs/multilingual/ROUTING.md):
         * /en/collections/wood asks for the collection whose ENGLISH address
         * it is, and gets nothing when only its Dutch address matches.
         *
         * The neutral `collections.slug` still answers for the DEFAULT
         * language, so every /collecties/... URL that existed before phase 6
         * keeps working.
         */
        try {
            $repository = new CollectionRepository();
            $id = ShopLocalization::collections()->ownerForSlug($slug, $language);
            $collection = $id === null ? null : $repository->findById($id);

            if ($collection === null && $language === LanguageResolver::defaultLanguage()) {
                $collection = $repository->findBySlug($slug);

                // ...and only for a collection with NO address of its own in
                // this language (App\Service\Routing\LocalizedSlug::answersTo()).
                if ($collection !== null && ShopLocalization::collectionSlug($collection, $language) !== $slug) {
                    $collection = null;
                }
            }
        } catch (\Throwable $e) {
            error_log('[CollectionContent] forPublicPage lookup failed for "' . $slug . '": ' . $e->getMessage());

            return null;
        }

        if ($collection === null || !(bool) $collection['is_active']) {
            return null;
        }

        return self::mapRow($collection, null, $language);
    }

    /**
     * The public URL of a collection. The single place that knows the
     * /collecties/ prefix — canonical tags, shop tiles and admin "live on"
     * links all go through here (or through AppUrl::canonical() with this
     * path), so the route exists in exactly one string.
     */
    public static function publicPath(string $slug, ?string $language = null): string
    {
        $language ??= RequestLanguage::current();

        // The namespace word itself is localized — "collecties" in Dutch,
        // "collections" in English — and comes from the same catalogue the
        // router matches against (App\Service\Routing\RouteSegments), so a
        // link and the route that answers it cannot spell it differently.
        return LocalizedUrl::path(
            '/' . RouteSegments::value('shop.collections', $language) . '/' . $slug,
            $language
        );
    }

    /**
     * A collection's URL in the language this page is being read in, falling
     * back to the DEFAULT language's when this one has no address for it: a
     * tile is somebody asking to go there, and landing on a real page beats
     * landing on nothing (docs/multilingual/ROUTING.md).
     *
     * @param array<string, mixed> $collection a `collections` row
     */
    public static function urlFor(array $collection): string
    {
        $language = RequestLanguage::current();
        $slug = ShopLocalization::collectionSlug($collection, $language);

        if ($slug !== null) {
            return self::publicPath($slug, $language);
        }

        $default = LanguageResolver::defaultLanguage();

        return self::publicPath(
            ShopLocalization::collectionSlug($collection, $default) ?? (string) ($collection['slug'] ?? ''),
            $default
        );
    }

    /**
     * Every language one collection can be read in, code => site-relative
     * path, for App\Service\Routing\LanguageAlternates. Only the languages
     * whose address really exists: an alternate may never name a URL that
     * 404s.
     *
     * @param array<string, mixed> $collection a `collections` row
     * @return array<string, string>
     */
    public static function alternates(array $collection): array
    {
        $paths = [];

        foreach (\App\Service\Language\SiteLanguages::activeCodes() as $code) {
            $slug = ShopLocalization::collectionSlug($collection, $code);

            if ($slug !== null) {
                $paths[$code] = self::publicPath($slug, $code);
            }
        }

        return $paths;
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
        return AppUrl::canonical(self::urlFor($collection));
    }

    /**
     * The same URL from a bare slug, for callers that hold a raw
     * `collections` row rather than a mapped one — App\Service\Sitemap, which
     * must build a collection's URL with exactly this code and not a second
     * copy of it.
     */
    public static function canonicalUrlForSlug(string $slug, ?string $language = null): string
    {
        return AppUrl::canonical(self::publicPath($slug, $language));
    }

    /**
     * The complete <title> text for one language.
     *
     * A custom SEO title IS the whole title and is
     * rendered verbatim — the same rule PageContent::seoTitle() and
     * ProductSeo::title() apply. Left empty it falls back to
     * "<collection name> | Shop — <site name>", the exact wording
     * collectie.php hardcoded before collections had SEO fields, so switching
     * the fields on changes no existing title.
     *
     * @param array<string, mixed> $collection a mapped row from forPublicPage()/activeForShop()
     */
    public static function seoTitle(array $collection, ?string $lang = null): string
    {
        $lang ??= RequestLanguage::current();
        $id = (int) ($collection['id'] ?? 0);
        $custom = ShopLocalization::collection($id, ShopLocalization::META_TITLE, $lang);

        if ($custom !== '') {
            return $custom;
        }

        return Seo::shopTitle(ShopLocalization::collection($id, ShopLocalization::NAME, $lang));
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
    public static function metaDescription(array $collection, ?string $lang = null): string
    {
        $lang ??= RequestLanguage::current();
        $id = (int) ($collection['id'] ?? 0);
        $custom = ShopLocalization::collection($id, ShopLocalization::META_DESCRIPTION, $lang);

        if ($custom !== '') {
            return $custom;
        }

        return self::excerpt(ShopLocalization::collectionDescription($id, $lang));
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
     * The row's own, language-neutral fields plus its WORDS in one language,
     * one string each — since Multilingual 2.0 phase 5 wave C the words are
     * rows in collection_translations, read through
     * App\Service\ShopLocalization with the fallback already applied.
     *
     * The SEO copy is deliberately NOT in this shape: seoTitle() and
     * metaDescription() are the only things that interpret it, and they ask
     * ShopLocalization for the one language they are building a head for.
     * `description` arrives sanitized per language (DescriptionSanitizer),
     * the "sanitize again on read" half of the pattern.
     *
     * @param array<string, mixed> $row
     * @return array{id:int, slug:string, name:string, description:string, image_path:?string, product_count:int, url:string}
     */
    private static function mapRow(array $row, ?int $productCount, string $language): array
    {
        $id = (int) $row['id'];
        $imagePath = (string) ($row['image_path'] ?? '');
        $ogImagePath = trim((string) ($row['og_image_path'] ?? ''));

        return [
            'id' => $id,
            'slug' => (string) $row['slug'],
            'name' => ShopLocalization::collection($id, ShopLocalization::NAME, $language),
            'description' => ShopLocalization::collectionDescription($id, $language),
            'image_path' => $imagePath === '' ? null : $imagePath,
            'og_image_path' => $ogImagePath === '' ? null : $ogImagePath,
            'product_count' => $productCount ?? 0,
            'url' => self::urlFor($row),
        ];
    }

}
