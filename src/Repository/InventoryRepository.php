<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All stock SQL (Shop Product & Ordering 2.0, MODULES.md "Voorraad"): which
 * products track stock, the stock of a product and of its variants, taking
 * units when an order is created and giving them back once when its payment
 * ends without money. App\Service\Inventory\Inventory decides; this only
 * reads and writes.
 *
 * NEVER READ, THEN WRITE. Every change to a stock counter is ONE conditional
 * statement: take() only succeeds while enough is left (`stock >= :qty` in the
 * same UPDATE), and an admin's new value only lands on the value that admin
 * saw (`stock = :seen`). InnoDB locks the row for the statement and evaluates
 * the condition against the committed value, so two checkouts for the last
 * unit cannot both succeed, whatever each of them read before.
 *
 * Placeholders are never reused within one statement: the connection uses
 * native prepares (App\Database), which refuse a repeated name.
 */
class InventoryRepository extends Repository
{
    /**
     * Whether a product tracks stock, and its own stock, whatever its active
     * state. Null for a product that does not exist.
     *
     * @return array{track_stock: bool, stock: int}|null
     */
    public function productState(int $productId): ?array
    {
        return $this->productStates([$productId])[$productId] ?? null;
    }

    /**
     * @param array<int, int> $productIds
     * @return array<int, array{track_stock: bool, stock: int}> by product id
     */
    public function productStates(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id, track_stock, stock FROM products WHERE id IN ({$placeholders})");
        $stmt->execute($ids);

        $states = [];
        foreach ($stmt->fetchAll() as $row) {
            $states[(int) $row['id']] = ['track_stock' => (int) $row['track_stock'] === 1, 'stock' => (int) $row['stock']];
        }

        return $states;
    }

    /**
     * The stock of every variant of these products, active or not: a product
     * with variants keeps its stock on them.
     *
     * @param array<int, int> $productIds
     * @return array<int, array<int, array{stock: int, active: bool}>> product id => variant id => state
     */
    public function variantStates(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, product_id, stock, active FROM product_variants WHERE product_id IN ({$placeholders}) ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute($ids);

        $states = [];
        foreach ($stmt->fetchAll() as $row) {
            $states[(int) $row['product_id']][(int) $row['id']] = [
                'stock' => (int) $row['stock'],
                'active' => (int) $row['active'] === 1,
            ];
        }

        return $states;
    }

    /**
     * Takes $quantity units of a product WITHOUT variants, if it tracks stock
     * and has them. False when it did not: too few left, or not tracked (the
     * caller reads the state again to tell which).
     */
    public function takeFromProduct(int $productId, int $quantity): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE products SET stock = stock - :quantity
             WHERE id = :id AND track_stock = 1 AND stock >= :minimum'
        );
        $stmt->execute(['quantity' => $quantity, 'id' => $productId, 'minimum' => $quantity]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Takes $quantity units of one variant of a product that tracks stock, if
     * it has them. The variant must belong to $productId.
     */
    public function takeFromVariant(int $productId, int $variantId, int $quantity): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE product_variants v
             INNER JOIN products p ON p.id = v.product_id
             SET v.stock = v.stock - :quantity
             WHERE v.id = :variant_id AND v.product_id = :product_id
               AND p.track_stock = 1 AND v.stock >= :minimum'
        );
        $stmt->execute(['quantity' => $quantity, 'variant_id' => $variantId, 'product_id' => $productId, 'minimum' => $quantity]);

        return $stmt->rowCount() === 1;
    }

    /**
     * The stock of one counter, locked for the rest of the caller's
     * transaction: a release reads it right before it adds, to know whether
     * the unit was sold out. Null when the row is gone.
     */
    public function lockedProductStock(int $productId): ?int
    {
        $stmt = $this->db->prepare('SELECT stock FROM products WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $productId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    public function lockedVariantStock(int $variantId): ?int
    {
        $stmt = $this->db->prepare('SELECT stock FROM product_variants WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $variantId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /**
     * Gives units back to the counter they came from. A negative legacy value
     * on a product counts as 0 first, so a release never leaves less than it
     * returned.
     */
    public function giveBackToProduct(int $productId, int $quantity): void
    {
        $stmt = $this->db->prepare('UPDATE products SET stock = GREATEST(stock, 0) + :quantity WHERE id = :id');
        $stmt->execute(['quantity' => $quantity, 'id' => $productId]);
    }

    public function giveBackToVariant(int $variantId, int $quantity): void
    {
        $stmt = $this->db->prepare('UPDATE product_variants SET stock = stock + :quantity WHERE id = :id');
        $stmt->execute(['quantity' => $quantity, 'id' => $variantId]);
    }

    /**
     * Claims the release of an order's reserved stock: true exactly once, for
     * the one caller that set the marker, and only while the order is failed,
     * canceled or expired and actually reserved something. A paid order never
     * gives stock back; an order that reserved nothing never gets a marker, so
     * an order from before stock tracking is never written to here.
     */
    public function claimRelease(int $orderId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE orders SET stock_released_at = NOW()
             WHERE id = :id
               AND stock_released_at IS NULL
               AND status IN ('failed', 'canceled', 'expired')
               AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = :order_id AND oi.stock_reserved > 0)"
        );
        $stmt->execute(['id' => $orderId, 'order_id' => $orderId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * The lines of an order that took units from stock, with where they came
     * from. A line whose product or variant has since been deleted still
     * comes back (with a NULL id); the caller skips it, because that counter
     * is gone.
     *
     * @return list<array{product_id: ?int, variant_id: ?int, stock_reserved: int, stock_source: string}>
     */
    public function reservedLines(int $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT product_id, variant_id, stock_reserved, stock_source
             FROM order_items
             WHERE order_id = :order_id AND stock_reserved > 0
             ORDER BY id ASC'
        );
        $stmt->execute(['order_id' => $orderId]);

        return array_map(static fn (array $row): array => [
            'product_id' => $row['product_id'] !== null ? (int) $row['product_id'] : null,
            'variant_id' => $row['variant_id'] !== null ? (int) $row['variant_id'] : null,
            'stock_reserved' => (int) $row['stock_reserved'],
            'stock_source' => (string) $row['stock_source'],
        ], $stmt->fetchAll());
    }

    /** "Voorraad bijhouden", from the product editor. */
    public function setTracking(int $productId, bool $track): void
    {
        $stmt = $this->db->prepare('UPDATE products SET track_stock = :track WHERE id = :id');
        $stmt->execute(['track' => $track ? 1 : 0, 'id' => $productId]);
    }

    /**
     * The product's own stock as the admin typed it, but only over the value
     * that admin saw ($seen): a sale in between is never overwritten by a
     * stale screen. $seen null writes unconditionally (a counter the screen
     * did not show). False: the stock changed in the meantime.
     */
    public function setProductStock(int $productId, int $stock, ?int $seen): bool
    {
        if ($seen === null) {
            $stmt = $this->db->prepare('UPDATE products SET stock = :stock WHERE id = :id');
            $stmt->execute(['stock' => $stock, 'id' => $productId]);

            return true;
        }

        $stmt = $this->db->prepare('UPDATE products SET stock = :stock WHERE id = :id AND stock = :seen');
        $stmt->execute(['stock' => $stock, 'id' => $productId, 'seen' => $seen]);

        return $stmt->rowCount() === 1 || $this->productStock($productId) === $stock;
    }

    public function setVariantStock(int $variantId, int $productId, int $stock, ?int $seen): bool
    {
        if ($seen === null) {
            $stmt = $this->db->prepare('UPDATE product_variants SET stock = :stock WHERE id = :id AND product_id = :product_id');
            $stmt->execute(['stock' => $stock, 'id' => $variantId, 'product_id' => $productId]);

            return true;
        }

        $stmt = $this->db->prepare(
            'UPDATE product_variants SET stock = :stock WHERE id = :id AND product_id = :product_id AND stock = :seen'
        );
        $stmt->execute(['stock' => $stock, 'id' => $variantId, 'product_id' => $productId, 'seen' => $seen]);

        return $stmt->rowCount() === 1 || $this->variantStock($variantId) === $stock;
    }

    public function productStock(int $productId): ?int
    {
        $stmt = $this->db->prepare('SELECT stock FROM products WHERE id = :id');
        $stmt->execute(['id' => $productId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    public function variantStock(int $variantId): ?int
    {
        $stmt = $this->db->prepare('SELECT stock FROM product_variants WHERE id = :id');
        $stmt->execute(['id' => $variantId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }
}
