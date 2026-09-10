<?php

namespace App\Service\Shipping;

/**
 * Thrown by ShippingCalculationService whenever a destination, weight or
 * shipping method combination has no matching, enabled shipping_rates row —
 * or the destination country isn't part of any configured shipping zone.
 *
 * Callers (api/checkout.php, api/shipping-quote.php) must treat this as a
 * hard stop: never fall back to a €0 or guessed price, and never proceed to
 * create a Mollie payment. See MAIN.MD "Missing rate handling".
 */
final class ShippingUnavailableException extends \RuntimeException
{
    private const CUSTOMER_MESSAGE = 'Voor deze bestelling is geen verzendmethode beschikbaar. Neem contact met ons op.';

    public static function create(): self
    {
        return new self(self::CUSTOMER_MESSAGE);
    }
}
