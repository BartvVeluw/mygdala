<?php

declare(strict_types=1);

namespace App\Service\Address;

/**
 * The outcome of a single App\Service\Address\DutchAddressLookupService
 * lookup. `found` is the only thing callers branch on; the rest is only
 * meaningful when `found` is true, and is always PDOK's own canonical data —
 * never anything echoed back from what the customer originally typed (see
 * DutchAddressLookupService for why that distinction matters).
 */
final class DutchAddressLookupResult
{
    private function __construct(
        public readonly bool $found,
        public readonly ?string $street = null,
        public readonly ?string $city = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $houseNumber = null,
        public readonly ?string $houseNumberAddition = null,
    ) {
    }

    public static function found(
        string $street,
        string $city,
        string $postalCode,
        string $houseNumber,
        ?string $houseNumberAddition
    ): self {
        return new self(true, $street, $city, $postalCode, $houseNumber, $houseNumberAddition);
    }

    public static function notFound(): self
    {
        return new self(false);
    }
}
