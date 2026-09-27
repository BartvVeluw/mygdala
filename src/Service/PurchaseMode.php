<?php

declare(strict_types=1);

namespace App\Service;

/**
 * How a product is sold (Shop Product & Ordering 2.0, `products.purchase_mode`,
 * MODULES.md "Op aanvraag"). A closed list of two:
 *
 *   DIRECT    Direct bestellen: price, quantity and "Toevoegen aan
 *             winkelwagen" — what every product was before this existed and
 *             what a new product is
 *   INQUIRY   Op aanvraag: the product is shown — name, pictures,
 *             description, the variant picker, specifications — but its price
 *             is nowhere: not on its page, not on a card, not in the API and
 *             not in its structured data. It cannot be put in the cart, and
 *             the server refuses it at every step (api/cart-check.php,
 *             api/checkout.php), whatever the browser sends.
 *
 * A value off the list is DIRECT, never itself: the safe reading of a
 * stored value nobody wrote here is "as before".
 */
final class PurchaseMode
{
    public const DIRECT = 'direct';
    public const INQUIRY = 'inquiry';

    public const ALL = [self::DIRECT, self::INQUIRY];

    public static function normalise(mixed $value): string
    {
        return is_string($value) && in_array($value, self::ALL, true) ? $value : self::DIRECT;
    }

    /** Whether a posted value is one of the list (a refused value is said, not swallowed). */
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::ALL, true);
    }

    public static function isInquiry(mixed $value): bool
    {
        return self::normalise($value) === self::INQUIRY;
    }
}
