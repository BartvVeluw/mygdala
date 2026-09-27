<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Service\Inventory\Inventory;

/**
 * Whether the lines of a cart can be ordered RIGHT NOW, line by line: the
 * server's answer to a cart that lives in the visitor's browser (`vvl-cart`,
 * MODULES.md "Winkelwagen"). Asked by api/cart-check.php when a line is
 * added, when a quantity changes and when the cart or the checkout opens,
 * and by api/checkout.php before it creates anything.
 *
 * It is ADVICE up to the moment of ordering. What a line may cost and
 * whether its units are really there is decided again inside the checkout's
 * transaction (App\Service\Inventory\Inventory::reserve()), because a stock
 * figure is only true for the moment it was read.
 *
 * Lines of the same unit are added up (two engravings of one variant need
 * stock for both), and every line of such a unit gets the same answer.
 *
 * Statuses, one per line:
 *   ok            orderable in this quantity
 *   sold_out      the unit tracks stock and has none left
 *   insufficient  some left, fewer than asked ('available' says how many)
 *   unavailable   the product or variant is gone, switched off, or a tracked
 *                 variant product was asked for without a variant
 */
final class CartAvailability
{
    public const OK = 'ok';
    public const SOLD_OUT = 'sold_out';
    public const INSUFFICIENT = 'insufficient';
    public const UNAVAILABLE = 'unavailable';

    /**
     * @param list<array{id: int, variant_id: ?int, qty: int}> $lines
     * @return list<array{status: string, available: ?int}> in the same order
     */
    public function check(array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $productIds = array_values(array_unique(array_map(static fn (array $line): int => (int) $line['id'], $lines)));
        $products = (new ProductRepository())->findActiveByIds($productIds);
        $stocks = (new Inventory())->forProducts(array_keys($products));
        $variants = new ProductVariantRepository();

        $results = [];
        $units = [];
        $needed = [];

        foreach ($lines as $index => $line) {
            $productId = (int) $line['id'];
            $variantId = $line['variant_id'] !== null ? (int) $line['variant_id'] : null;

            if (!isset($products[$productId])
                || ($variantId !== null && $variants->findActiveForProduct($variantId, $productId) === null)) {
                $results[$index] = ['status' => self::UNAVAILABLE, 'available' => null];
                continue;
            }

            $unit = $stocks[$productId]->unitFor($variantId);
            if ($unit === null) {
                $results[$index] = ['status' => self::UNAVAILABLE, 'available' => null];
                continue;
            }

            if (!$unit->tracked) {
                $results[$index] = ['status' => self::OK, 'available' => null];
                continue;
            }

            $units[$index] = $unit;
            $needed[$unit->key()] = ($needed[$unit->key()] ?? 0) + max(1, (int) $line['qty']);
        }

        foreach ($units as $index => $unit) {
            $available = (int) $unit->available();
            $results[$index] = match (true) {
                $available <= 0 => ['status' => self::SOLD_OUT, 'available' => 0],
                $needed[$unit->key()] > $available => ['status' => self::INSUFFICIENT, 'available' => $available],
                default => ['status' => self::OK, 'available' => $available],
            };
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * The cart lines of a JSON request, as check() takes them: a positive
     * product id, an optional positive variant id and a quantity of 1 to 50
     * (the checkout's own limits). A line that is none of that is dropped
     * and reported as unavailable by its index.
     *
     * @return array{0: list<array{id: int, variant_id: ?int, qty: int}>, 1: array<int, int>} [lines, request index by line index]
     */
    public static function linesFromRequest(mixed $items): array
    {
        $lines = [];
        $indexes = [];

        if (!is_array($items)) {
            return [$lines, $indexes];
        }

        foreach (array_values($items) as $requestIndex => $item) {
            if (!is_array($item) || count($lines) >= 100) {
                continue;
            }

            $id = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
            $qty = filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT);
            $variantId = ($item['variant_id'] ?? null) === null ? null : filter_var($item['variant_id'], FILTER_VALIDATE_INT);

            if ($id === false || $id < 1 || $qty === false || $qty < 1 || $qty > 50 || $variantId === false || ($variantId !== null && $variantId < 1)) {
                continue;
            }

            $indexes[count($lines)] = $requestIndex;
            $lines[] = ['id' => $id, 'variant_id' => $variantId, 'qty' => $qty];
        }

        return [$lines, $indexes];
    }
}
