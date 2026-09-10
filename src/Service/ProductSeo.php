<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductImageRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;

/**
 * The SEO read model for one shop product: its resolved <title>, meta
 * description, canonical URL, social-sharing image and Product JSON-LD.
 *
 * This is the product-side counterpart of App\Service\PageContent's
 * seoTitle()/metaDescription()/canonicalPath() — same idea, same fallback
 * philosophy, same static/try-catch convention: a lookup failure must never
 * fatal a public request, so forPublicPage() returns null instead of
 * throwing and product.php then renders its "product not found" head.
 *
 * Why a class of its own rather than more methods on PageContent: a product
 * is not a `pages` row. It has variants, photos and a price, and it is the
 * only content type in this project that carries structured data. Everything
 * a product page's <head> needs is resolved here, once, so that the head, the
 * Open Graph tags, the JSON-LD and the sitemap can never disagree about what
 * a product's URL, title or price is.
 *
 * FALLBACKS (all deterministic, all "empty means: use the real content"):
 *
 *   title       custom meta_title, else "<product name> | Shop — <site name>"
 *               (App\Service\Seo::shopTitle — the exact wording product.php
 *               already rendered before this feature existed).
 *   description custom meta_description, else a plain-text excerpt of the
 *               product's own description. Never raw HTML.
 *   image       custom og_image_path, else the product's best public photo
 *               (see imagePaths() — the same photo the visitor sees), else
 *               the site-wide og_image_path Site Setting via
 *               partials/og-meta.php.
 *
 * NOT stored and never invented: SKU, GTIN, MPN, ratings, reviews, stock
 * quantities, priceValidUntil, shipping or return-policy claims. The `stock`
 * column exists on `products` but nothing in the shop reads or maintains it
 * (checkout does not check it), so it is not represented in structured data
 * at all — see jsonLd().
 */
class ProductSeo
{
    /** @var array<int, array<string, mixed>|null> */
    private static array $cache = [];

    /**
     * The public route of a product detail page. The single place that knows
     * it — canonical tags, og:url, JSON-LD `url` and the sitemap all resolve
     * a product URL through here.
     *
     * A product deliberately has no pretty URL and no /collecties/-nested
     * URL: it is one page at /product.php?id=… however many collections it
     * appears in (see .htaccess and MAIN.MD). The `?id=` query string is
     * therefore part of the canonical URL, not a parameter contaminating it
     * — it is built from a validated integer, never from request input, so
     * tracking parameters on the incoming request cannot leak into it.
     */
    public static function publicPath(int $productId): string
    {
        return '/product.php?id=' . $productId;
    }

    public static function canonicalUrl(int $productId): string
    {
        return AppUrl::canonical(ltrim(self::publicPath($productId), '/'));
    }

    /**
     * Everything product.php needs for its <head>, or null when the product
     * does not exist, is inactive, or the lookup failed — in which case the
     * page renders a noindex "not found" head and a 404, exactly like
     * collectie.php does for an unpublished collection. An inactive product
     * therefore never emits a title, description, canonical, Open Graph tag
     * or a line of structured data.
     *
     * @return array{
     *     id:int, name_nl:string, name_en:string,
     *     title_nl:string, title_en:string,
     *     description_nl:string, description_en:string,
     *     canonical_url:string, og_image_path:?string,
     *     json_ld:array<string, mixed>
     * }|null
     */
    public static function forPublicPage(int $productId): ?array
    {
        if ($productId < 1) {
            return null;
        }

        if (array_key_exists($productId, self::$cache)) {
            return self::$cache[$productId];
        }

        try {
            $product = (new ProductRepository())->findActiveByIdWithSeo($productId);
        } catch (\Throwable $e) {
            error_log('[ProductSeo] lookup failed for product ' . $productId . ': ' . $e->getMessage());

            return self::$cache[$productId] = null;
        }

        if ($product === null) {
            return self::$cache[$productId] = null;
        }

        return self::$cache[$productId] = self::resolve($product);
    }

    /**
     * Resolves one already-loaded `products` row. Split out from
     * forPublicPage() so the resolution rules can be unit-tested against a
     * plain array without a database round trip.
     *
     * @param array<string, mixed> $product a `products` row (SEO columns included)
     * @param list<string>|null    $imagePaths pre-resolved public image paths; loaded when null
     * @param list<float>|null     $variantPrices effective prices of the active variants; loaded when null
     *
     * @return array<string, mixed>
     */
    public static function resolve(array $product, ?array $imagePaths = null, ?array $variantPrices = null): array
    {
        $id = (int) $product['id'];
        $nameNl = trim((string) ($product['name'] ?? ''));
        $nameEn = Seo::pick($product['name'] ?? '', $product['name_en'] ?? '', 'en');

        $imagePaths ??= self::imagePaths($id, $product['image_path'] ?? null);
        $variantPrices ??= self::variantPrices($id, (float) ($product['price'] ?? 0));

        $ogImagePath = trim((string) ($product['og_image_path'] ?? ''));
        if ($ogImagePath === '') {
            $ogImagePath = $imagePaths[0] ?? '';
        }

        $resolved = [
            'id' => $id,
            'name_nl' => $nameNl,
            'name_en' => $nameEn,
            'title_nl' => self::title($product, 'nl'),
            'title_en' => self::title($product, 'en'),
            'description_nl' => self::metaDescription($product, 'nl'),
            'description_en' => self::metaDescription($product, 'en'),
            'canonical_url' => self::canonicalUrl($id),
            'og_image_path' => $ogImagePath === '' ? null : $ogImagePath,
        ];

        $resolved['json_ld'] = self::jsonLd($product, $imagePaths, $variantPrices, $resolved);

        return $resolved;
    }

    /**
     * The complete <title> text for one language. A custom meta_title IS the
     * whole title and is rendered verbatim (the same rule
     * PageContent::seoTitle() applies to a page), so the owner can write a
     * title that does not end in the site name if they want to.
     *
     * @param array<string, mixed> $product
     */
    public static function title(array $product, string $lang = 'nl'): string
    {
        $custom = Seo::pick($product['meta_title'] ?? '', $product['meta_title_en'] ?? '', $lang);

        if ($custom !== '') {
            return $custom;
        }

        return Seo::shopTitle(Seo::pick($product['name'] ?? '', $product['name_en'] ?? '', $lang));
    }

    /**
     * The product's meta description for one language, or '' when it has
     * none — in which case product.php renders no <meta name="description">
     * at all, the same rule partials/page-head.php applies to a CMS page.
     *
     * A custom value is returned exactly as typed. Only the automatic
     * fallback — a plain-text excerpt of the product's own description — is
     * shortened, and it is stripped of HTML first so no markup can ever
     * reach a meta attribute.
     *
     * @param array<string, mixed> $product
     */
    public static function metaDescription(array $product, string $lang = 'nl'): string
    {
        $custom = Seo::pick($product['meta_description'] ?? '', $product['meta_description_en'] ?? '', $lang);

        if ($custom !== '') {
            return $custom;
        }

        return Seo::excerpt(Seo::pick($product['description'] ?? '', $product['description_en'] ?? '', $lang));
    }

    /**
     * The product's own description as full plain text — what JSON-LD's
     * `description` uses, because structured data must describe the same
     * thing the visitor reads, not a shortened search-result snippet.
     *
     * @param array<string, mixed> $product
     */
    public static function descriptionText(array $product, string $lang = 'nl'): string
    {
        return Seo::plainText(Seo::pick($product['description'] ?? '', $product['description_en'] ?? '', $lang));
    }

    /**
     * The product's public photos, in the order a visitor sees them, as
     * stored site-relative paths.
     *
     * Mirrors exactly what the shop renders (see api/products.php and
     * assets/js/shop/shop.js): a product WITH active variants shows its default
     * variant's gallery instead of its own product-level photos, so that is
     * what the social image and the structured data describe too. Falling
     * back to `products.image_path` covers legacy rows that predate
     * product_images.
     *
     * @return list<string>
     */
    public static function imagePaths(int $productId, ?string $legacyImagePath = null): array
    {
        $paths = [];

        try {
            $defaultVariant = (new ProductVariantRepository())->findDefaultForProduct($productId);

            if ($defaultVariant !== null) {
                foreach ($defaultVariant['images'] as $image) {
                    $path = trim((string) ($image['image_path'] ?? ''));
                    if ($path !== '') {
                        $paths[] = $path;
                    }
                }
            } else {
                foreach ((new ProductImageRepository())->findByProductId($productId) as $image) {
                    $path = trim((string) ($image['image_path'] ?? ''));
                    if ($path !== '') {
                        $paths[] = $path;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[ProductSeo] image lookup failed for product ' . $productId . ': ' . $e->getMessage());
        }

        if ($paths === []) {
            $legacy = trim((string) $legacyImagePath);
            if ($legacy !== '') {
                $paths[] = $legacy;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * The effective price of every ACTIVE variant, in display order. A
     * variant with a NULL price inherits the product's own price — the exact
     * rule api/checkout.php enforces server-side and assets/js/shop/shop.js shows
     * on the page, so a JSON-LD price can never disagree with what a visitor
     * is charged.
     *
     * An empty result means "this product has no variants"; the caller then
     * uses the product price itself.
     *
     * @return list<float>
     */
    public static function variantPrices(int $productId, float $productPrice): array
    {
        try {
            $variants = (new ProductVariantRepository())->findActiveByProductId($productId);
        } catch (\Throwable $e) {
            error_log('[ProductSeo] variant lookup failed for product ' . $productId . ': ' . $e->getMessage());

            return [];
        }

        return self::variantPricesFrom($variants, $productPrice);
    }

    /**
     * The pure half of variantPrices(): the NULL-price-inherits-the-product-
     * price rule on an already-loaded list of variant rows.
     *
     * @param array<int, array<string, mixed>> $variants
     *
     * @return list<float>
     */
    public static function variantPricesFrom(array $variants, float $productPrice): array
    {
        $prices = [];
        foreach ($variants as $variant) {
            $prices[] = ($variant['price'] ?? null) !== null ? (float) $variant['price'] : $productPrice;
        }

        return $prices;
    }

    /**
     * Google Product / Merchant Listing structured data for one product,
     * built as a PHP array and encoded by the caller with json_encode() —
     * never by string concatenation, so a name or description containing
     * quotes, "</script>", HTML or any Unicode cannot break the page (see
     * partials/shop-seo-head.php for the encoding flags).
     *
     * Every value is derived from the row that renders the page. Only what
     * the application genuinely knows is emitted:
     *
     *   sku/gtin/mpn   omitted — this project stores no article numbers, and
     *                  the URL slug is not one.
     *   brand          the site name: these products really are made by
     *                  Van Veluw Laserdesign, so it is truthful.
     *   availability   InStock for an active product, because that is exactly
     *                  what "active" means in this shop — an active product
     *                  is orderable and checkout performs no stock check. The
     *                  unused `stock` column is deliberately NOT published as
     *                  an inventory level. An inactive product never reaches
     *                  here at all (forPublicPage() returns null).
     *   offers         one Offer when there is a single public price;
     *                  AggregateOffer with lowPrice/highPrice/offerCount only
     *                  when the active variants genuinely have DIFFERENT
     *                  prices. Variants that all share one price stay a plain
     *                  Offer — an AggregateOffer would imply a range the shop
     *                  does not have.
     *   reviews,       never emitted. Nothing in this project collects them.
     *   ratings,
     *   priceValidUntil,
     *   shipping/returns
     *
     * @param array<string, mixed>  $product
     * @param list<string>          $imagePaths
     * @param list<float>           $variantPrices
     * @param array<string, mixed>  $resolved the already-resolved title/description/canonical
     *
     * @return array<string, mixed>
     */
    public static function jsonLd(array $product, array $imagePaths, array $variantPrices, array $resolved): array
    {
        $canonical = (string) $resolved['canonical_url'];

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => (string) $resolved['name_nl'],
            'url' => $canonical,
        ];

        $description = self::descriptionText($product, 'nl');
        if ($description === '') {
            // No product description at all: fall back to whatever the page's
            // own meta description says, so the structured data still matches
            // the visible <head> rather than being dropped silently.
            $description = (string) $resolved['description_nl'];
        }
        if ($description !== '') {
            $data['description'] = $description;
        }

        $images = [];
        foreach ($imagePaths as $path) {
            $url = Seo::absoluteImageUrl($path);
            if ($url !== null) {
                $images[] = $url;
            }
        }
        if ($images !== []) {
            $data['image'] = $images;
        }

        $data['brand'] = [
            '@type' => 'Brand',
            'name' => SiteSettings::get('site_name'),
        ];

        $data['offers'] = self::offers($product, $variantPrices, $canonical);

        return $data;
    }

    /**
     * The Offer / AggregateOffer node — see jsonLd()'s docblock for why the
     * choice between them depends on the actual prices rather than on
     * "does this product have variants".
     *
     * @param array<string, mixed> $product
     * @param list<float>          $variantPrices
     *
     * @return array<string, mixed>
     */
    private static function offers(array $product, array $variantPrices, string $canonical): array
    {
        $productPrice = (float) ($product['price'] ?? 0);
        $prices = $variantPrices !== [] ? $variantPrices : [$productPrice];

        $low = min($prices);
        $high = max($prices);

        $common = [
            'url' => $canonical,
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
            'itemCondition' => 'https://schema.org/NewCondition',
        ];

        if ($low === $high) {
            return ['@type' => 'Offer', 'price' => self::money($low)] + $common;
        }

        return [
            '@type' => 'AggregateOffer',
            'lowPrice' => self::money($low),
            'highPrice' => self::money($high),
            'offerCount' => count($prices),
        ] + $common;
    }

    /** Prices as a plain "34.95" string — no thousands separator, always 2 decimals. */
    private static function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
