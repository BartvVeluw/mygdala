<?php

declare(strict_types=1);

namespace App\Service\Personalization;

use App\Module\ModuleRegistry;
use App\Repository\ProductRepository;

/**
 * The public Personalisatie catalogue: which products the /personaliseren
 * page lists.
 *
 * Same role and the same per-request cache + clearCache() convention as
 * App\Service\RelatedProductsContent — "what belongs on this page" is CMS
 * configuration, resolved once on the server, and the page renders the
 * answer rather than deciding it.
 *
 * ## Two conditions, deliberately kept apart
 *
 * A product appears here only when BOTH are true:
 *
 *   1. the owner put it in this channel — `products.active = 1` and
 *      `products.in_personalization_catalog = 1` (see
 *      db/migrations/20260908220000_add_product_availability_channels.php);
 *   2. it can ACTUALLY be personalized right now — an enabled configuration
 *      with at least one preview image and one usable zone, which is exactly
 *      what ProductPersonalizationContent::forProduct() returning non-null
 *      means.
 *
 * The second condition is what keeps this page honest: a product whose
 * personalization is half-finished or switched off would otherwise be listed
 * as personalizable and then show no configurator at all when opened. It is
 * checked through the existing resolver rather than re-implemented, so the
 * page and the product page can never disagree about which products offer
 * personalization.
 *
 * The result is a list of PRODUCT IDS in catalogue order. The cards
 * themselves are rendered by the shop's one product-card renderer
 * (assets/js/shop/shop.js, fed by GET /api/products.php?ids=…), exactly like
 * "Gerelateerde producten" — so image, title, price, link and availability
 * handling all come from the single shop implementation and this page adds no
 * second card design.
 */
class PersonalizationCatalog
{
    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * The catalogue, or null when there is nothing to show at all.
     *
     * Null rather than an empty list on purpose: the page renders an
     * explanatory empty state instead of an empty grid, and a caller never
     * has to decide what "zero products" looks like.
     *
     * @return array{product_ids: list<int>, count: int}|null
     */
    public static function forPublicPage(): ?array
    {
        // No catalogue at all when the module is off: the public page 404s
        // (App\Module\ModuleGuard) and the sitemap has nothing to list.
        if (!ModuleRegistry::isEnabled('personalization')) {
            return null;
        }

        if (self::$cache !== null) {
            return self::$cache['result'];
        }

        try {
            $productIds = [];

            foreach ((new ProductRepository())->findPersonalizationCatalog() as $product) {
                $productId = (int) $product['id'];

                // The one authority on "can this actually be personalized".
                if (ProductPersonalizationContent::forProduct($productId) === null) {
                    continue;
                }

                $productIds[] = $productId;
            }
        } catch (\Throwable $e) {
            // A catalogue lookup failing must never take the page down; it
            // degrades to "nothing to show", the same fallback philosophy
            // every Content class in this project follows.
            error_log('[PersonalizationCatalog] ' . $e->getMessage());
            $productIds = [];
        }

        $result = $productIds === []
            ? null
            : ['product_ids' => $productIds, 'count' => count($productIds)];

        self::$cache = ['result' => $result];

        return $result;
    }

    /**
     * Whether one product is listed in this catalogue — what the product page
     * uses to decide whether to offer a "back to Personalisatie" link, and
     * what the CMS overview badges.
     */
    public static function contains(int $productId): bool
    {
        $catalog = self::forPublicPage();

        return $catalog !== null && in_array($productId, $catalog['product_ids'], true);
    }

    /**
     * The canonical public path of the catalogue page.
     *
     * `.php` on purpose: every hand-written page on this site is reached at
     * /<name>.php (shop.php, diensten.php, contact.php, cart.php). The
     * extensionless pretty paths belong to the two things that really are
     * dynamic routes — CMS pages (/<slug>) and collections
     * (/collecties/<slug>) — and inventing a third rewrite for one fixed
     * template would add a rule to .htaccess that nothing else needs.
     */
    public static function publicPath(): string
    {
        return '/personaliseren.php';
    }
}
