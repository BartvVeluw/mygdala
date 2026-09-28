<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductRepository;
use App\Service\Personalization\ProductPersonalizationContent;

/**
 * HOW a visible product can be bought, decided once for every place that
 * shows it: the product page (product.php) and the Uitgelicht product block
 * (App\Service\Blocks\FeaturedProductBlock). A place may offer LESS than this
 * — the block can be set to "Alleen product bekijken" — but never more: a
 * product the product page does not put in the cart, no block puts in the
 * cart either.
 *
 * Four answers, checked in this order:
 *
 *   inquiry      "Op aanvraag" (App\Service\PurchaseMode): shown without a
 *                price, no quantity, no cart; a personalization
 *                configurator — a way to order — is not offered either
 *   unorderable  personalization-only (`products.in_shop = 0`) with nothing
 *                to personalize (switched off, or unfinished): there is
 *                genuinely no way to order it right now
 *   personalize  the product has a personalization configurator
 *                (App\Service\Personalization\ProductPersonalizationContent):
 *                its ONE purchase action lives at the end of that
 *                configurator on the product page, never a second, plain
 *                "Toevoegen aan winkelwagen" that would skip it
 *   cart         the ordinary way: order questions, quantity, add to cart
 *
 * The server enforces every one of these again whatever a browser sends:
 * api/cart-check.php and api/checkout.php refuse an inquiry product, stock
 * that is not there and answers that do not fit; the checkout's
 * PersonalizationValidator refuses a plain line for a product that must be
 * personalized. This class only decides what a page OFFERS.
 *
 * Only for a product a visitor may see (active); the caller has checked that.
 */
final class ProductPurchasePath
{
    public const CART = 'cart';
    public const INQUIRY = 'inquiry';
    public const PERSONALIZE = 'personalize';
    public const UNORDERABLE = 'unorderable';

    /**
     * @return array{path: string, personalization: array<string, mixed>|null}
     *         `personalization` is the product's configurator as
     *         ProductPersonalizationContent resolves it, or null — always null
     *         for an inquiry product, which offers no configurator
     */
    public static function forProduct(int $productId): array
    {
        $products = new ProductRepository();

        if (PurchaseMode::isInquiry($products->purchaseMode($productId))) {
            return ['path' => self::INQUIRY, 'personalization' => null];
        }

        $personalization = ProductPersonalizationContent::forProduct($productId);

        if ($personalization !== null) {
            return ['path' => self::PERSONALIZE, 'personalization' => $personalization];
        }

        if (!$products->isShopPurchasable($productId)) {
            return ['path' => self::UNORDERABLE, 'personalization' => null];
        }

        return ['path' => self::CART, 'personalization' => null];
    }
}
