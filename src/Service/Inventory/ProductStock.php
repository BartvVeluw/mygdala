<?php

declare(strict_types=1);

namespace App\Service\Inventory;

/**
 * THE ONE RESOLVER of a product's sellable units (MODULES.md "Voorraad"):
 *
 *   - untracked: one unlimited unit, whatever variant is asked for;
 *   - tracked, WITHOUT variants: the product itself, `products.stock`;
 *   - tracked, WITH variants: every variant is its own unit,
 *     `product_variants.stock`, and the product's own stock is ignored. The
 *     two never compete: a line for such a product that names no variant has
 *     no unit at all (unitFor() is null), so it cannot be sold.
 *
 * "With variants" means the product has variant rows, active or not — the
 * same test the product editor uses to decide where the stock field goes.
 */
final class ProductStock
{
    /**
     * @param array<int, array{stock: int, active: bool}> $variants variant id => state
     */
    public function __construct(
        public readonly int $productId,
        public readonly bool $tracked,
        private readonly int $productStock,
        private readonly array $variants
    ) {
    }

    public function hasVariants(): bool
    {
        return $this->variants !== [];
    }

    /**
     * The unit a line for this product (and this variant) takes its stock
     * from, or null when a tracked product cannot say: a variant product and
     * no variant, or a variant that is not this product's.
     */
    public function unitFor(?int $variantId): ?StockUnit
    {
        if (!$this->tracked) {
            return new StockUnit($this->productId, $variantId, false, 0);
        }

        if (!$this->hasVariants()) {
            return $variantId === null ? new StockUnit($this->productId, null, true, $this->productStock) : null;
        }

        if ($variantId === null || !isset($this->variants[$variantId])) {
            return null;
        }

        return new StockUnit($this->productId, $variantId, true, $this->variants[$variantId]['stock']);
    }

    /** The product's own unit (meaningful for a product without variants). */
    public function productUnit(): StockUnit
    {
        return new StockUnit($this->productId, null, $this->tracked, $this->productStock);
    }

    /** @return array<int, StockUnit> variant id => unit, for every variant */
    public function variantUnits(): array
    {
        $units = [];
        foreach ($this->variants as $variantId => $state) {
            $units[$variantId] = new StockUnit($this->productId, $variantId, $this->tracked, $state['stock']);
        }

        return $units;
    }
}
