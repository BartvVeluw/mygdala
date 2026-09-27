<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Service\Inventory\InventoryEditor;
use App\Service\Inventory\ProductStock;
use App\Service\Inventory\StockUnit;

/**
 * The product editor's Voorraad section (admin/product-form.php, Shop
 * Product & Ordering 2.0): "Voorraad bijhouden", and the stock of a product
 * without variants. A product with variants keeps its stock per variant, in
 * each variant's row under Varianten (admin/_product_variants.php); this
 * section then says so and sums up how many are sold out. The two never
 * compete (App\Service\Inventory\ProductStock).
 *
 * PART OF THE ONE SAVE (App\Service\Inventory\InventoryEditor). The field
 * carries the value it showed (`stock_seen`), so a save that did not change
 * the stock never touches it, and one that did lands only on that value: a
 * sale in the meantime is never undone. The section is a region of the
 * editor, so after a save it is drawn again with the stock as it is then.
 *
 * With tracking off every stock field is hidden (and still sent, unchanged):
 * the product is unlimited, as every product was before this section
 * existed. admin/assets/product-inventory.js shows them the moment the
 * switch goes on, variant rows included.
 */

/**
 * @param array<string, mixed>|null $old a refused save's fields (the PRG path), or null
 */
function product_inventory_section(ProductStock $stock, ?array $old): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $tracked = $old !== null && array_key_exists('track_stock', $old) ? (bool) $old['track_stock'] : $stock->tracked;
    $unit = $stock->productUnit();
    $stockValue = $old !== null && isset($old['stock_input']) ? (string) $old['stock_input'] : (string) max(0, $unit->stock);
    ?>
    <div class="admin-product-inventory" data-product-inventory>
      <div class="admin-alert admin-alert--error" data-admin-editor-errors="inventory" hidden></div>
      <input type="hidden" name="inventory_present" value="1">

      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-switch" role="switch" name="track_stock" value="1" data-stock-track<?= $tracked ? ' checked' : '' ?>>
        <strong><?= admin_te('shop.stock.track') ?></strong>
      </label>
      <p class="admin-text-muted" data-stock-untracked-only<?= $tracked ? ' hidden' : '' ?>><?= admin_te('shop.stock.untracked_note') ?></p>

      <?php if ($stock->hasVariants()): ?>
        <p class="admin-text-muted" data-stock-tracked-only<?= $tracked ? '' : ' hidden' ?>><?= admin_te('shop.stock.per_variant_note') ?></p>
        <p data-stock-tracked-only<?= $tracked ? '' : ' hidden' ?>><?= product_stock_variant_summary($stock) ?></p>
      <?php else: ?>
        <div class="admin-form-row admin-product-inventory__row" data-stock-tracked-only<?= $tracked ? '' : ' hidden' ?>>
          <div class="admin-field">
            <?= admin_field_label('product-stock', admin_t('shop.stock.quantity'), admin_t('help.shop.stock.quantity')) ?>
            <input type="number" id="product-stock" name="stock" min="0" max="<?= InventoryEditor::MAX_STOCK ?>" step="1" inputmode="numeric" value="<?= $h($stockValue) ?>">
            <input type="hidden" name="stock_seen" value="<?= (int) $unit->stock ?>">
          </div>
          <?= product_stock_badge(new StockUnit($stock->productId, null, true, $unit->stock)) ?>
        </div>
      <?php endif; ?>
      <p data-stock-untracked-only<?= $tracked ? ' hidden' : '' ?>><?= product_stock_badge(new StockUnit($stock->productId, null, false, 0)) ?></p>
    </div>
    <?php
}

/**
 * The status of one unit as the CMS words it: "Voorraad niet bijgehouden",
 * "12 op voorraad" or "Uitverkocht". Always the word, so the colour only
 * confirms it.
 */
function product_stock_badge(StockUnit $unit): string
{
    if (!$unit->tracked) {
        return '<span class="admin-badge admin-badge--muted">' . admin_te('shop.stock.not_tracked') . '</span>';
    }

    if ($unit->isSoldOut()) {
        return '<span class="admin-badge admin-badge--sold-out">' . admin_te('shop.stock.sold_out') . '</span>';
    }

    return '<span class="admin-badge admin-badge--in-stock">' . admin_te('shop.stock.in_stock', ['count' => (string) $unit->available()]) . '</span>';
}

/** "3 varianten, 1 uitverkocht" for a tracked product with variants. */
function product_stock_variant_summary(ProductStock $stock): string
{
    $units = (new ProductStock($stock->productId, true, 0, array_map(
        static fn (StockUnit $unit): array => ['stock' => $unit->stock, 'active' => true],
        $stock->variantUnits()
    )))->variantUnits();
    $soldOut = count(array_filter($units, static fn (StockUnit $unit): bool => $unit->isSoldOut()));

    return admin_te('shop.stock.variant_summary', ['count' => (string) count($units), 'sold_out' => (string) $soldOut]);
}
