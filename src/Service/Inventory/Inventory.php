<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Database;
use App\Repository\InventoryRepository;
use PDO;

/**
 * STOCK, per sellable unit, for the products whose owner switched it on
 * (Shop Product & Ordering 2.0, MODULES.md "Voorraad"). The one class that
 * changes a stock counter outside the product editor.
 *
 * THE LIFECYCLE OF A UNIT IN AN ORDER:
 *
 *   1. reserve()         inside the checkout's order transaction: every line
 *                        takes its units in one conditional UPDATE per unit
 *                        (App\Repository\InventoryRepository). Too few left
 *                        and the whole order rolls back: no order, no line,
 *                        no unit taken. What each line took, and from which
 *                        counter, is stored on the line.
 *   2. paid              nothing happens: the units are sold.
 *   3. failed, canceled, releaseForOrder(): the order's marker
 *      expired           (`orders.stock_released_at`) is claimed in one
 *                        conditional UPDATE and, in the same transaction,
 *                        every line gives its units back to the counter they
 *                        came from. A second webhook, a status page refresh
 *                        or two syncs at once find the marker set and give
 *                        nothing back again.
 *   4. payment could not App\Service\Inventory\PaymentStartFailure marks the
 *      be started        order failed first, so step 3 applies: an order
 *                        nobody can pay never keeps its units.
 *
 * CONCURRENCY. There is no "read the stock, then write it": the check is in
 * the UPDATE itself, so InnoDB's row lock decides between two customers for
 * the last unit and the second one is refused. Units are taken in a fixed
 * order (by counter), so two orders for the same two units cannot deadlock.
 *
 * Untracked products are never touched: they reserve nothing, release
 * nothing and are always available — exactly what every product was before.
 */
final class Inventory
{
    private InventoryRepository $repository;
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->repository = new InventoryRepository($this->db);
    }

    /** A product's units as they are now. An unknown product is an untracked one with no variants. */
    public function forProduct(int $productId): ProductStock
    {
        return $this->forProducts([$productId])[$productId];
    }

    /**
     * @param array<int, int> $productIds
     * @return array<int, ProductStock> by product id, one for every id asked
     */
    public function forProducts(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $states = $this->repository->productStates($ids);
        $variants = $this->repository->variantStates($ids);

        $result = [];
        foreach ($ids as $id) {
            $state = $states[$id] ?? ['track_stock' => false, 'stock' => 0];
            $result[$id] = new ProductStock($id, $state['track_stock'], $state['stock'], $variants[$id] ?? []);
        }

        return $result;
    }

    /**
     * Takes the units of these order lines, inside the caller's transaction.
     * Lines for the same unit are added up first, so two lines of one variant
     * (two different engravings, say) need the stock for both.
     *
     * @param list<array{product_id: int, variant_id: ?int, quantity: int}> $lines
     * @return list<array{stock_reserved: int, stock_source: ?string}> per line, in the same order
     *
     * @throws InsufficientStockException for the first line that cannot be served
     */
    public function reserve(array $lines): array
    {
        $stocks = $this->forProducts(array_map(static fn (array $line): int => (int) $line['product_id'], $lines));

        $units = [];
        $needed = [];
        $lineUnits = [];

        foreach ($lines as $index => $line) {
            $unit = $stocks[(int) $line['product_id']]->unitFor($line['variant_id'] !== null ? (int) $line['variant_id'] : null);
            if ($unit === null) {
                throw new InsufficientStockException($index, (int) $line['product_id'], $line['variant_id'] !== null ? (int) $line['variant_id'] : null, 0);
            }

            $lineUnits[$index] = $unit;
            if (!$unit->tracked) {
                continue;
            }

            $units[$unit->key()] = $unit;
            $needed[$unit->key()] = ($needed[$unit->key()] ?? 0) + (int) $line['quantity'];
        }

        // One fixed order for every checkout, so two of them never wait on
        // each other's rows in opposite order.
        ksort($needed, SORT_STRING);

        $taken = [];
        foreach ($needed as $key => $quantity) {
            $unit = $units[$key];
            $ok = $unit->variantId === null
                ? $this->repository->takeFromProduct($unit->productId, $quantity)
                : $this->repository->takeFromVariant($unit->productId, $unit->variantId, $quantity);

            if ($ok) {
                $taken[$key] = true;
                continue;
            }

            // Refused: too few left, or the owner switched tracking off a
            // moment ago. Only the first is a refusal.
            $now = $this->forProduct($unit->productId)->unitFor($unit->variantId);
            if ($now !== null && !$now->tracked) {
                continue;
            }

            foreach ($lineUnits as $index => $lineUnit) {
                if ($lineUnit->key() === $key) {
                    throw new InsufficientStockException($index, $unit->productId, $unit->variantId, $now?->available() ?? 0);
                }
            }
        }

        $reserved = [];
        foreach ($lines as $index => $line) {
            $unit = $lineUnits[$index];
            $reserved[] = isset($taken[$unit->key()])
                ? ['stock_reserved' => (int) $line['quantity'], 'stock_source' => $unit->source()]
                : ['stock_reserved' => 0, 'stock_source' => null];
        }

        return $reserved;
    }

    /**
     * Gives an order's reserved units back, once: only for a failed, canceled
     * or expired order that reserved something and did not give it back yet.
     * Joins the caller's transaction when there is one.
     *
     * @return list<StockUnit> the units that were sold out and are orderable
     *                         again because of this release, as they are now
     */
    public function releaseForOrder(int $orderId): array
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            if (!$this->repository->claimRelease($orderId)) {
                if ($ownTransaction) {
                    $this->db->commit();
                }

                return [];
            }

            // Per counter, in one fixed order, like reserve().
            $giveBack = [];
            foreach ($this->repository->reservedLines($orderId) as $line) {
                if ($line['stock_source'] === StockUnit::SOURCE_VARIANT) {
                    if ($line['variant_id'] === null || $line['product_id'] === null) {
                        continue;
                    }
                    $key = 'v' . $line['variant_id'];
                } else {
                    if ($line['product_id'] === null) {
                        continue;
                    }
                    $key = 'p' . $line['product_id'];
                }

                $giveBack[$key] ??= ['product_id' => $line['product_id'], 'variant_id' => $line['stock_source'] === StockUnit::SOURCE_VARIANT ? $line['variant_id'] : null, 'quantity' => 0];
                $giveBack[$key]['quantity'] += $line['stock_reserved'];
            }
            ksort($giveBack, SORT_STRING);

            $cameBack = [];
            foreach ($giveBack as $item) {
                $before = $item['variant_id'] === null
                    ? $this->repository->lockedProductStock($item['product_id'])
                    : $this->repository->lockedVariantStock($item['variant_id']);
                if ($before === null) {
                    continue;
                }

                if ($item['variant_id'] === null) {
                    $this->repository->giveBackToProduct($item['product_id'], $item['quantity']);
                } else {
                    $this->repository->giveBackToVariant($item['variant_id'], $item['quantity']);
                }

                if ($before <= 0) {
                    $cameBack[] = [$item['product_id'], $item['variant_id']];
                }
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        // Only a unit whose product still tracks stock is "back": an
        // untracked one was never sold out for a customer.
        $units = [];
        foreach ($cameBack as [$productId, $variantId]) {
            $unit = $this->forProduct($productId)->unitFor($variantId);
            if ($unit !== null && $unit->tracked && !$unit->isSoldOut()) {
                $units[] = $unit;
            }
        }

        return $units;
    }
}
