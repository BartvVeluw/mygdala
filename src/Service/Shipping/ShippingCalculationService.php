<?php

namespace App\Service\Shipping;

use App\Repository\ProductRepository;
use App\Repository\ShippingRateRepository;
use App\Repository\ShippingZoneRepository;

/**
 * The single, central shipping calculation component (MAIN.MD "Shipping
 * calculation system", point 7: "Do not duplicate shipping logic between
 * cart, checkout and order creation") — every place that needs a shipping
 * price (the checkout price preview and the checkout order-creation
 * endpoint) calls calculateForShipping() here, never reimplements the rules
 * itself.
 *
 * Pickup is intentionally NOT handled by this class: a pickup order's
 * shipping cost is always exactly €0 with no zone/weight/rate lookup
 * involved at all (MAIN.MD "Pickup"), so callers short-circuit to that
 * before ever reaching here.
 *
 * The three steps that actually decide the price — total weight, which
 * profile the whole cart must ship under, and which configured rate matches
 * that profile+weight — are implemented as pure static methods
 * (totalWeightGrams / determineMethod / pickRate) that take only plain
 * arrays and never touch the database. That keeps the one part of this
 * system with real branching logic (weight brackets, the parcel/letterbox/
 * letter priority rule) fully unit-testable without a database, while
 * calculateForShipping() itself stays a thin, obviously-correct
 * orchestrator around them.
 */
final class ShippingCalculationService
{
    public function __construct(
        private readonly ProductRepository $productRepository = new ProductRepository(),
        private readonly ShippingZoneRepository $zoneRepository = new ShippingZoneRepository(),
        private readonly ShippingRateRepository $rateRepository = new ShippingRateRepository(),
    ) {
    }

    /**
     * @param array<int, array{product_id:int, quantity:int}> $cartLines
     *
     * @throws ShippingUnavailableException when the destination country isn't
     *         in any configured zone, or no enabled rate matches the
     *         resulting method + total weight for that zone.
     */
    public function calculateForShipping(array $cartLines, string $countryCode): ShippingResult
    {
        if ($cartLines === []) {
            throw ShippingUnavailableException::create();
        }

        $zone = $this->zoneRepository->findZoneForCountry($countryCode);
        if ($zone === null) {
            throw ShippingUnavailableException::create();
        }

        $productIds = array_values(array_unique(array_map(
            static fn (array $line): int => $line['product_id'],
            $cartLines
        )));
        $shippingDataById = $this->productRepository->findShippingDataByIds($productIds);

        foreach ($cartLines as $line) {
            if (!isset($shippingDataById[$line['product_id']])) {
                // A cart line whose product has no (or no longer active)
                // shipping data — fail safe rather than guessing a weight/profile.
                throw ShippingUnavailableException::create();
            }
        }

        $weightGrams = self::totalWeightGrams($cartLines, $shippingDataById);
        $method = self::determineMethod($cartLines, $shippingDataById);

        $rates = $this->rateRepository->findEnabledForZone((int) $zone['id']);
        $rate = self::pickRate($rates, $method, $weightGrams);

        if ($rate === null) {
            throw ShippingUnavailableException::create();
        }

        return new ShippingResult((string) $zone['code'], $method, (float) $rate['price'], $weightGrams);
    }

    /**
     * @param array<int, array{product_id:int, quantity:int}> $cartLines
     * @param array<int, array{shipping_profile:string, shipping_weight_grams:int, requires_parcel:bool}> $shippingDataById
     */
    public static function totalWeightGrams(array $cartLines, array $shippingDataById): int
    {
        $total = 0;
        foreach ($cartLines as $line) {
            $total += $shippingDataById[$line['product_id']]['shipping_weight_grams'] * $line['quantity'];
        }

        return $total;
    }

    /**
     * Step 4-6 of MAIN.MD's calculation rules: if any line requires parcel
     * shipping the whole order uses parcel regardless of anything else,
     * otherwise the highest-priority profile actually present wins (see
     * ShippingProfile::highestOf()).
     *
     * @param array<int, array{product_id:int, quantity:int}> $cartLines
     * @param array<int, array{shipping_profile:string, shipping_weight_grams:int, requires_parcel:bool}> $shippingDataById
     */
    public static function determineMethod(array $cartLines, array $shippingDataById): string
    {
        $profiles = [];
        foreach ($cartLines as $line) {
            $data = $shippingDataById[$line['product_id']];
            if ($data['requires_parcel']) {
                return ShippingProfile::PARCEL;
            }
            $profiles[] = $data['shipping_profile'];
        }

        return ShippingProfile::highestOf($profiles);
    }

    /**
     * Finds the enabled rate for $method whose weight bracket covers
     * $weightGrams. A rate matches when its min bound (if any) is <= the
     * weight and its max bound (if any) is >= the weight; among all matches,
     * the one with the smallest max_weight_grams wins (nulls sort as
     * "unlimited", so a flat/no-limit rate is only picked when nothing
     * tighter matches). Returns null when nothing matches — callers must
     * treat that as "shipping unavailable", never as €0.
     *
     * @param array<int, array{shipping_profile:string, min_weight_grams:?int, max_weight_grams:?int, price:float|string, enabled:bool}> $rates
     */
    public static function pickRate(array $rates, string $method, int $weightGrams): ?array
    {
        $best = null;

        foreach ($rates as $rate) {
            if (!$rate['enabled'] || $rate['shipping_profile'] !== $method) {
                continue;
            }
            if ($rate['min_weight_grams'] !== null && $weightGrams < $rate['min_weight_grams']) {
                continue;
            }
            if ($rate['max_weight_grams'] !== null && $weightGrams > $rate['max_weight_grams']) {
                continue;
            }

            if ($best === null || self::maxWeightSortValue($rate) < self::maxWeightSortValue($best)) {
                $best = $rate;
            }
        }

        return $best;
    }

    /**
     * @param array{max_weight_grams:?int} $rate
     */
    private static function maxWeightSortValue(array $rate): int
    {
        return $rate['max_weight_grams'] ?? PHP_INT_MAX;
    }
}
