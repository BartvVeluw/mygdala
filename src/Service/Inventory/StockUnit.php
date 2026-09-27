<?php

declare(strict_types=1);

namespace App\Service\Inventory;

/**
 * One SELLABLE UNIT and its stock: a product without variants, or one variant
 * of a product with them (App\Service\Inventory\ProductStock decides which).
 * A value, read at one moment; the counter itself is only ever changed by
 * App\Service\Inventory\Inventory.
 *
 * Untracked ("Voorraad bijhouden" off) means unlimited: every question below
 * answers as if there were always enough, which is what every product was
 * before stock tracking existed.
 */
final class StockUnit
{
    public const SOURCE_PRODUCT = 'product';
    public const SOURCE_VARIANT = 'variant';

    public function __construct(
        public readonly int $productId,
        public readonly ?int $variantId,
        public readonly bool $tracked,
        public readonly int $stock
    ) {
    }

    /** Which counter this unit's stock is: the product's own, or its variant's. */
    public function source(): string
    {
        return $this->variantId === null ? self::SOURCE_PRODUCT : self::SOURCE_VARIANT;
    }

    /** A stable name for the counter, e.g. "p12" or "v34". */
    public function key(): string
    {
        return $this->variantId === null ? 'p' . $this->productId : 'v' . $this->variantId;
    }

    /** How many can be ordered, or null for unlimited (untracked). A negative legacy value is 0. */
    public function available(): ?int
    {
        return $this->tracked ? max(0, $this->stock) : null;
    }

    public function isSoldOut(): bool
    {
        return $this->tracked && $this->stock <= 0;
    }

    public function allows(int $quantity): bool
    {
        return !$this->tracked || $this->stock >= $quantity;
    }
}
