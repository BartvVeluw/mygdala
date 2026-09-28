<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductImageRepository;
use App\Repository\ProductOptionRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Service\Inventory\Inventory;
use App\Service\Inventory\StockUnit;

/**
 * ONE active product as a visitor's browser gets it: the words in one
 * language, the pool of pictures, the options and variants, what the stock
 * allows, and — only for a product that is sold directly — its prices.
 *
 * THE ONE PAYLOAD. assets/js/shop/shop.js draws a product from exactly this
 * shape wherever it appears: on product.php, which asks GET /api/product.php
 * for it, and in the Uitgelicht product block
 * (App\Service\Blocks\FeaturedProductBlock), which prints it into its own
 * section. Both go through forPublic(), so the block can never show a product
 * the product page would not, a price the page would hide, or a stock figure
 * the page keeps to itself.
 *
 * What it decides, in one place:
 *   - visibility: only an active product (ProductRepository::findActiveById());
 *     anything else is null, and the caller shows nothing;
 *   - op aanvraag (App\Service\PurchaseMode): no price leaves the server —
 *     not the product's and not a variant's — and `inquiry` says so;
 *   - stock (App\Service\Inventory): per unit only "sold out" and the most
 *     that may be ordered at once, never the figure itself; an untracked
 *     product says "unlimited" (null) for every unit;
 *   - pictures: the product's ONE pool; a variant shows the subset it links
 *     to, in its own order, or — when it links to none — the whole pool.
 *
 * NOT HERE: the product page's server-side extras (SEO, personalization, the
 * order questions, the specifications) — they have their own services — and
 * any decision about whether this product can be put in the cart, which is
 * App\Service\ProductPurchasePath.
 */
final class ProductDetail
{
    /**
     * @return array<string, mixed>|null null for a product that is not active
     *         (or does not exist): nothing of it may reach a visitor
     */
    public static function forPublic(int $productId, string $language): ?array
    {
        if ($productId < 1) {
            return null;
        }

        $product = (new ProductRepository())->findActiveById($productId);
        if ($product === null) {
            return null;
        }

        // The words of the page's language, the fallback applied. Descriptions
        // are sanitized on write (see api/admin/_product_validation.php) AND
        // again on the way out of App\Service\ShopLocalization, which protects
        // against anything ever written directly to the database.
        $product['name'] = ShopLocalization::product($productId, ShopLocalization::NAME, $language);
        $product['description'] = ShopLocalization::productDescription($productId, $language);

        // The product's ONE pool of pictures. A variant shows the subset it
        // links to, in its own order, or - when it links to none - this whole
        // pool (assets/js/shop/shop.js). Adding a variant never hides a picture.
        $product['images'] = array_map(self::picture(...), (new ProductImageRepository())->findByProductId($productId));
        $product['options'] = (new ProductOptionRepository())->findByProductId($productId);
        $product['variants'] = (new ProductVariantRepository())->findActiveByProductId($productId);
        $product['has_variants'] = $product['variants'] !== [];

        // A variant's description is its own text in this language, or else the
        // product's (App\Service\ShopLocalization::variantDescription()), so the
        // page can swap it with the selection and never shows an empty one.
        // What the page can DO with the stock (App\Service\Inventory): whether a
        // unit is sold out, and at most how many of it can be ordered. The
        // figure itself is not published, and an untracked product says
        // "unlimited" (null) for every unit, exactly as before stock existed.
        $stock = (new Inventory())->forProduct($productId);
        $product['stock_tracked'] = $stock->tracked;
        $product += self::availability($stock->hasVariants() ? null : $stock->productUnit());
        $variantUnits = $stock->variantUnits();

        // Op aanvraag (App\Service\PurchaseMode): the product is shown, its
        // variants can be chosen, but no price leaves the server — not the
        // product's and not a variant's — and the page offers no cart.
        $inquiry = PurchaseMode::isInquiry($product['purchase_mode'] ?? null);
        unset($product['purchase_mode']);
        $product['inquiry'] = $inquiry;
        if ($inquiry) {
            $product['price'] = null;
        }

        ShopLocalization::preloadVariants(array_map(static fn (array $v): int => (int) $v['id'], $product['variants']));
        foreach ($product['variants'] as &$variant) {
            $variant['images'] = array_map(self::picture(...), $variant['images']);
            $variant['description'] = ShopLocalization::variantDescription((int) $variant['id'], $productId, $language);
            $variant += self::availability($variantUnits[(int) $variant['id']] ?? null);
            if ($inquiry) {
                $variant['price'] = null;
            }
        }
        unset($variant);

        return $product;
    }

    /**
     * The same payload without any price: for a place that shows the product
     * but not its price, so the price is not even in the page (the Uitgelicht
     * product block with its price switched off — also when it sells: its
     * cart line asks GET /api/product.php for the price at the moment of
     * adding). A price the product itself hides is already absent.
     *
     * @param array<string, mixed> $payload a forPublic() result
     * @return array<string, mixed>
     */
    public static function withoutPrices(array $payload): array
    {
        $payload['price'] = null;
        foreach ($payload['variants'] ?? [] as $index => $variant) {
            $payload['variants'][$index]['price'] = null;
        }

        return $payload;
    }

    /**
     * A unit's availability as the product page needs it: sold out or not, and
     * the most that may be put in the cart at once (null: no limit from stock).
     * No unit (a variant product's own row) is simply not sold out.
     *
     * @return array{sold_out: bool, max_quantity: ?int}
     */
    private static function availability(?StockUnit $unit): array
    {
        if ($unit === null || !$unit->tracked) {
            return ['sold_out' => false, 'max_quantity' => null];
        }

        return ['sold_out' => $unit->isSoldOut(), 'max_quantity' => (int) $unit->available()];
    }

    /**
     * One picture as the product page needs it: its id in the product's pool,
     * the path, and the library's alt text and dimensions when it has them.
     *
     * @param array<string, mixed> $row a ProductImageRepository / ProductVariantImageRepository row
     * @return array<string, mixed>
     */
    private static function picture(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'image_path' => (string) $row['image_path'],
            'alt_text' => $row['alt_text'] ?? null,
            'width' => isset($row['width']) ? (int) $row['width'] : null,
            'height' => isset($row['height']) ? (int) $row['height'] : null,
            'is_primary' => (int) ($row['is_primary'] ?? 0) === 1,
        ];
    }
}
