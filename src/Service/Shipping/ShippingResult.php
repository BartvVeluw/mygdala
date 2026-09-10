<?php

namespace App\Service\Shipping;

/**
 * The outcome of a shipping calculation for a shipped (non-pickup) order.
 */
final class ShippingResult
{
    public function __construct(
        public readonly string $zoneCode,
        public readonly string $method,
        public readonly float $price,
        public readonly int $weightGrams,
    ) {
    }
}
