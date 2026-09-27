<?php

declare(strict_types=1);

namespace App\Service\Inventory;

/**
 * An order line asks for more than its unit has, or names no unit a tracked
 * product can sell from (a variant product without a variant). Thrown by
 * App\Service\Inventory\Inventory::reserve() inside the checkout's
 * transaction, which then rolls back: no order, no line and no unit taken.
 */
final class InsufficientStockException extends \RuntimeException
{
    /**
     * @param int $lineIndex the position of the line in what was reserved
     * @param int $available what is left of that unit (0 when there is no unit)
     */
    public function __construct(
        public readonly int $lineIndex,
        public readonly int $productId,
        public readonly ?int $variantId,
        public readonly int $available
    ) {
        parent::__construct('Not enough stock for product ' . $productId . ($variantId !== null ? ' variant ' . $variantId : '') . ': ' . $available . ' left.');
    }
}
