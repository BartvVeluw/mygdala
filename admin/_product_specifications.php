<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_product_variants.php';

use App\Service\ProductSpecificationEditor;

/**
 * The product editor's Specificaties section (admin/product-form.php, Shop
 * Product & Ordering 2.0): which properties from the library (Shop →
 * Specificaties) this product has, in its own order, and its value for each
 * in the language being edited. A property is chosen once; the value is
 * plain text ("3", "Berken multiplex"), and the unit comes from the library.
 *
 * PART OF THE ONE SAVE (App\Service\ProductSpecificationEditor). A row is
 * one property on this product, by key: the stored row's id, or "new<n>"
 * for one added here, which picks its property. Adding, moving and removing
 * rows changes only the screen (admin/assets/row-list.js). A region of the
 * editor, drawn again after a save with the rows' ids.
 *
 * Without a library there is nothing to pick: the section says where to make
 * one.
 */

/**
 * @param list<array{id: int, specification_id: int, name: string, unit: string, value: string, placeholder: string}> $rows
 * @param list<array{id: int, unit: string, sort_order: int, usage: int}> $library
 */
function product_specifications_section(array $rows, array $library): void
{
    $choices = [];
    foreach ($library as $specification) {
        $choices[] = [
            'id' => $specification['id'],
            'name' => \App\Service\ShopLocalization::specificationAdminName($specification['id']),
            'unit' => $specification['unit'],
        ];
    }
    ?>
    <div class="admin-product-specifications" data-product-specifications>
      <div class="admin-alert admin-alert--error" data-admin-editor-errors="specifications" hidden></div>
      <input type="hidden" name="specifications_present" value="1">
      <p class="admin-text-muted"><?= admin_t('shop.specifications.intro') ?></p>

      <?php if ($choices === []): ?>
        <p class="admin-alert admin-alert--info"><?= admin_t('shop.specifications.library_empty') ?></p>
      <?php else: ?>
        <div class="admin-specification-list" data-row-list="product-specifications">
          <?php foreach ($rows as $row): ?>
            <?php product_specification_row((string) $row['id'], $row, $choices); ?>
          <?php endforeach; ?>
        </div>
        <p class="admin-text-muted" data-product-specifications-empty<?= $rows !== [] ? ' hidden' : '' ?>><?= admin_te('shop.specifications.empty') ?></p>
        <template data-row-list-template="product-specifications"><?php product_specification_row('__KEY__', ['specification_id' => 0, 'name' => '', 'unit' => '', 'value' => '', 'placeholder' => ''], $choices); ?></template>
        <p class="admin-visually-hidden" role="status" aria-live="polite" data-row-list-status="product-specifications" data-row-list-moved="<?= admin_te('editor_rows.verplaatst') ?>"></p>
        <div class="admin-option-rows__tools">
          <button type="button" class="admin-btn-secondary" data-row-list-add="product-specifications" hidden>+ <?= admin_te('shop.specifications.add') ?></button>
        </div>
      <?php endif; ?>
    </div>
    <?php
}

/**
 * One property on this product. A stored row names its property; a new one
 * picks it from the library.
 *
 * @param array{specification_id: int, name: string, unit: string, value: string, placeholder: string} $row
 * @param list<array{id: int, name: string, unit: string}> $choices
 */
function product_specification_row(string $key, array $row, array $choices): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $id = 'product-specification-' . $key;
    $isNew = !ctype_digit($key);
    ?>
    <div class="admin-row-card admin-specification-row" data-row-list-row>
      <input type="hidden" name="<?= $h(ProductSpecificationEditor::field($key, 'present')) ?>" value="1">
      <div class="admin-specification-row__fields">
        <div class="admin-field admin-specification-row__name">
          <?php if ($isNew): ?>
            <label for="<?= $h($id) ?>-spec"><?= admin_te('shop.specifications.property') ?> <span class="admin-badge admin-badge--info"><?= admin_te('editor_rows.nieuw') ?></span></label>
            <select class="admin-select" id="<?= $h($id) ?>-spec" name="<?= $h(ProductSpecificationEditor::field($key, 'specification_id')) ?>">
              <option value=""><?= admin_te('shop.specifications.choose') ?></option>
              <?php foreach ($choices as $choice): ?>
                <option value="<?= (int) $choice['id'] ?>"><?= $h($choice['name']) ?><?= $choice['unit'] !== '' ? ' (' . $h($choice['unit']) . ')' : '' ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <span class="admin-field__label"><?= admin_te('shop.specifications.property') ?></span>
            <strong><?= $h($row['name']) ?></strong>
          <?php endif; ?>
        </div>
        <div class="admin-field admin-specification-row__value">
          <label for="<?= $h($id) ?>-value"><?= admin_te('shop.specifications.value') ?><?= $row['unit'] !== '' ? ' (' . $h($row['unit']) . ')' : '' ?></label>
          <input type="text" id="<?= $h($id) ?>-value" name="<?= $h(ProductSpecificationEditor::field($key, 'value')) ?>" maxlength="<?= ProductSpecificationEditor::VALUE_MAX_LENGTH ?>" value="<?= $h($row['value']) ?>"<?= $row['placeholder'] !== '' && $row['value'] === '' ? ' placeholder="' . $h($row['placeholder']) . '"' : '' ?>>
        </div>
        <?php product_variants_row_tools(admin_t('shop.specifications.remove'), 'data-product-specification-remove', true); ?>
      </div>
    </div>
    <?php
}
