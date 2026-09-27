<?php

declare(strict_types=1);

namespace App\Service\Inventory;

/**
 * The stock of a unit changed between the moment the product editor was
 * drawn and the moment it was saved — a sale, most likely — and the admin
 * typed a new value over the old one. Writing it would silently undo that
 * sale, so the save is refused (inside its transaction, which rolls back)
 * with the value as it is now, keyed by the field it is about.
 */
final class StockConflictException extends \RuntimeException
{
    public function __construct(
        public readonly string $field,
        public readonly int $current
    ) {
        parent::__construct('Stock changed meanwhile (' . $field . ': now ' . $current . ').');
    }
}
