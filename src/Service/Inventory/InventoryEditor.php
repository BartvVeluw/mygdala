<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Database;
use App\Repository\InventoryRepository;
use App\Service\Language\AdminTranslator;
use PDO;

/**
 * The Voorraad section of the product editor (admin/_product_inventory.php)
 * as part of the product's ONE save (api/admin/update-product.php,
 * create-product.php): "Voorraad bijhouden" and, for a product without
 * variants, its own stock. A variant's stock is part of its row in
 * Varianten (App\Service\ProductVariantEditor), the same rule.
 *
 * Only when the form carried the section (`inventory_present`), so a request
 * without it changes nothing here.
 *
 * AN ADMIN NEVER UNDOES A SALE. The screen sends the value it showed
 * (`stock_seen`) with the one the admin typed. The same value is not written
 * at all, so an admin who only changed the price never touches the stock a
 * customer just took; a new value is written only over the value that admin
 * saw (App\Repository\InventoryRepository::setProductStock()). Changed in the
 * meantime: StockConflictException, the whole save rolls back, and the
 * editor says what the stock is now.
 */
final class InventoryEditor
{
    /** The largest stock a counter may be given here: six digits. */
    public const MAX_STOCK = 999999;

    /** @var array<string, string>|null */
    private ?array $errors = null;

    private function __construct(
        private readonly bool $posted,
        private readonly bool $track,
        private readonly ?string $stock,
        private readonly ?int $seen
    ) {
    }

    /** @param array<string, mixed> $post */
    public static function fromRequest(array $post): self
    {
        if (!isset($post['inventory_present'])) {
            return new self(false, false, null, null);
        }

        $stock = is_string($post['stock'] ?? null) ? trim($post['stock']) : null;
        $seen = is_string($post['stock_seen'] ?? null) && preg_match('/^-?\d{1,9}$/', $post['stock_seen']) === 1
            ? (int) $post['stock_seen']
            : null;

        return new self(true, ($post['track_stock'] ?? null) === '1', $stock, $seen);
    }

    /**
     * Whether $value is a stock an admin may give: a whole number from 0 to
     * MAX_STOCK, digits only.
     */
    public static function isValidStock(string $value): bool
    {
        return preg_match('/^\d{1,6}$/', $value) === 1 && (int) $value <= self::MAX_STOCK;
    }

    /** @return array<string, string> messages by field name */
    public function validate(): array
    {
        $errors = [];
        if ($this->posted && $this->stock !== null && $this->stock !== '' && !self::isValidStock($this->stock)) {
            $errors['stock'] = AdminTranslator::trans('validation.stock_invalid');
        }
        if ($this->posted && $this->track && $this->stock === '') {
            $errors['stock'] = AdminTranslator::trans('validation.stock_invalid');
        }

        return $this->errors = $errors;
    }

    public function posted(): bool
    {
        return $this->posted;
    }

    /** The switch as posted, or null when the section was not on the form. */
    public function tracking(): ?bool
    {
        return $this->posted ? $this->track : null;
    }

    /**
     * Writes the section inside the caller's transaction.
     *
     * @param bool $hasVariants a product with variants keeps its stock on them: its own is not written
     *
     * @throws StockConflictException when the product's stock changed since the screen was drawn
     */
    public function save(int $productId, bool $hasVariants, ?PDO $db = null): void
    {
        if ($this->errors !== []) {
            throw new \LogicException('InventoryEditor::save() needs a validate() without errors first.');
        }
        if (!$this->posted) {
            return;
        }

        $repository = new InventoryRepository($db ?? Database::connection());
        $repository->setTracking($productId, $this->track);

        if ($hasVariants || $this->stock === null || $this->stock === '') {
            return;
        }

        $stock = (int) $this->stock;
        if ($this->seen !== null && $stock === $this->seen) {
            return;
        }

        if (!$repository->setProductStock($productId, $stock, $this->seen)) {
            throw new StockConflictException('stock', (int) $repository->productStock($productId));
        }
    }
}
