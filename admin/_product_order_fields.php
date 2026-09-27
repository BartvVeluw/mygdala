<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_product_variants.php';

use App\Service\OrderFields\OrderFieldType;
use App\Service\OrderFields\ProductOrderFieldEditor;

/**
 * The product editor's Bestelvelden section (admin/product-form.php, Shop
 * Product & Ordering 2.0): "Bestelgegevens vragen", and the questions a
 * customer answers before the product goes in the cart — "Naam op het bord".
 * Separate from Personalisatie, which places text and pictures on a preview.
 *
 * PART OF THE ONE SAVE (App\Service\OrderFields\ProductOrderFieldEditor).
 * A question is a row, a choice of a radio or dropdown question a row inside
 * it (admin/assets/row-list.js): adding, moving and removing them changes
 * only the screen. Rows go by key — an id, or "new<n>" for one typed here.
 * The words are in the language the editor is in; every other language stays.
 * admin/assets/product-order-fields.js shows the length for a text question
 * and the choices for a radio or dropdown one, and hides the list while the
 * switch is off (it is still sent, and kept).
 *
 * A region of the editor: after a save the server draws it again, and every
 * new row carries its id from then on.
 */

/**
 * @param list<array{id: int, type: string, required: bool, max_length: ?int, label: string, help: string, options: list<array{id: int, label: string}>}> $fields the questions with their words in the editing language
 */
function product_order_fields_section(bool $enabled, array $fields): void
{
    ?>
    <div class="admin-product-order-fields" data-product-order-fields>
      <div class="admin-alert admin-alert--error" data-admin-editor-errors="order_fields" hidden></div>
      <input type="hidden" name="order_fields_present" value="1">

      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-switch" role="switch" name="order_fields_enabled" value="1" data-order-fields-toggle<?= $enabled ? ' checked' : '' ?>>
        <strong><?= admin_te('shop.order_fields.enabled') ?></strong>
      </label>
      <p class="admin-text-muted"><?= admin_te('shop.order_fields.intro') ?></p>

      <div data-order-fields-body<?= $enabled ? '' : ' hidden' ?>>
        <div class="admin-order-field-list" data-row-list="order-fields">
          <?php foreach ($fields as $field): ?>
            <?php product_order_field_row((string) $field['id'], $field); ?>
          <?php endforeach; ?>
        </div>
        <p class="admin-text-muted" data-order-fields-empty<?= $fields !== [] ? ' hidden' : '' ?>><?= admin_te('shop.order_fields.empty') ?></p>
        <template data-row-list-template="order-fields"><?php product_order_field_row('__KEY__', ['type' => OrderFieldType::TEXT, 'required' => false, 'max_length' => null, 'label' => '', 'help' => '', 'options' => []]); ?></template>
        <p class="admin-visually-hidden" role="status" aria-live="polite" data-row-list-status="order-fields" data-row-list-moved="<?= admin_te('editor_rows.verplaatst') ?>"></p>
        <div class="admin-option-rows__tools">
          <button type="button" class="admin-btn-secondary" data-row-list-add="order-fields" hidden>+ <?= admin_te('shop.order_fields.add') ?></button>
        </div>
      </div>
    </div>
    <?php
}

/**
 * One question.
 *
 * @param array{type: string, required: bool, max_length: ?int, label: string, help: string, options: list<array{id: int, label: string}>} $field
 */
function product_order_field_row(string $key, array $field): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $id = 'order-field-' . $key;
    $name = static fn (string $part): string => ProductOrderFieldEditor::field($key, $part);
    $isNew = !ctype_digit($key);
    $type = OrderFieldType::isValid($field['type']) ? $field['type'] : OrderFieldType::TEXT;
    ?>
    <fieldset class="admin-row-card admin-order-field" data-row-list-row data-order-field-key="<?= $h($key) ?>">
      <legend class="admin-visually-hidden"><?= admin_te('shop.order_fields.question') ?> <span data-row-list-number></span></legend>
      <input type="hidden" name="<?= $h($name('present')) ?>" value="1">

      <div class="admin-order-field__head">
        <div class="admin-field admin-order-field__label">
          <label for="<?= $h($id) ?>-label"><?= admin_te('shop.order_fields.label') ?><?= $isNew ? ' <span class="admin-badge admin-badge--info">' . admin_te('editor_rows.nieuw') . '</span>' : '' ?></label>
          <input type="text" id="<?= $h($id) ?>-label" name="<?= $h($name('label')) ?>" maxlength="<?= ProductOrderFieldEditor::LABEL_MAX_LENGTH ?>" value="<?= $h($field['label']) ?>" placeholder="<?= admin_te('shop.order_fields.label_placeholder') ?>">
        </div>
        <div class="admin-field admin-order-field__type">
          <label for="<?= $h($id) ?>-type"><?= admin_te('shop.order_fields.type') ?></label>
          <select class="admin-select" id="<?= $h($id) ?>-type" name="<?= $h($name('type')) ?>" data-order-field-type>
            <?php foreach (OrderFieldType::ALL as $option): ?>
              <option value="<?= $h($option) ?>"<?= $type === $option ? ' selected' : '' ?>><?= admin_te('shop.order_fields.type_' . $option) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php product_variants_row_tools(admin_t('shop.order_fields.remove'), 'data-order-field-remove', true); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <div class="admin-field">
          <label for="<?= $h($id) ?>-help"><?= admin_te('shop.order_fields.help') ?></label>
          <input type="text" id="<?= $h($id) ?>-help" name="<?= $h($name('help')) ?>" maxlength="<?= ProductOrderFieldEditor::HELP_MAX_LENGTH ?>" value="<?= $h($field['help']) ?>" placeholder="<?= admin_te('shop.order_fields.help_placeholder') ?>">
        </div>
        <div class="admin-field" data-order-field-when="text"<?= OrderFieldType::isText($type) ? '' : ' hidden' ?>>
          <label for="<?= $h($id) ?>-max"><?= admin_te('shop.order_fields.max_length') ?></label>
          <input type="number" id="<?= $h($id) ?>-max" name="<?= $h($name('max_length')) ?>" min="1" max="<?= OrderFieldType::CAP[OrderFieldType::TEXTAREA] ?>" step="1" inputmode="numeric" value="<?= $field['max_length'] !== null ? (int) $field['max_length'] : '' ?>" placeholder="<?= admin_te('shop.order_fields.max_length_placeholder') ?>">
        </div>
      </div>

      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-checkbox" name="<?= $h($name('required')) ?>" value="1"<?= $field['required'] ? ' checked' : '' ?>>
        <?= admin_te('shop.order_fields.required') ?>
      </label>

      <div class="admin-order-field__options" data-order-field-when="options"<?= OrderFieldType::hasOptions($type) ? '' : ' hidden' ?> data-admin-editor-error-for="<?= $h($name('options')) ?>">
        <p class="admin-field__label"><?= admin_te('shop.order_fields.options') ?></p>
        <ul class="admin-option-values" data-row-list="order-field-options-<?= $h($key) ?>">
          <?php foreach ($field['options'] as $option): ?>
            <?php product_order_field_option_row($key, (string) $option['id'], $option['label']); ?>
          <?php endforeach; ?>
        </ul>
        <template data-row-list-template="order-field-options-<?= $h($key) ?>" data-row-list-key="__OKEY__"><?php product_order_field_option_row($key, '__OKEY__', ''); ?></template>
        <p class="admin-visually-hidden" role="status" aria-live="polite" data-row-list-status="order-field-options-<?= $h($key) ?>" data-row-list-moved="<?= admin_te('editor_rows.verplaatst') ?>"></p>
        <div class="admin-option-rows__tools">
          <button type="button" class="admin-btn-secondary" data-row-list-add="order-field-options-<?= $h($key) ?>" hidden>+ <?= admin_te('shop.order_fields.add_option') ?></button>
        </div>
      </div>
    </fieldset>
    <?php
}

/** One choice of a radio or dropdown question. */
function product_order_field_option_row(string $fieldKey, string $key, string $label): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $id = 'order-field-' . $fieldKey . '-option-' . $key;
    $name = 'order_field_options[' . $fieldKey . '][' . $key . ']';
    ?>
    <li class="admin-option-value" data-row-list-row>
      <input type="hidden" name="<?= $h($name) ?>[present]" value="1">
      <div class="admin-field admin-option-value__text">
        <label class="admin-visually-hidden" for="<?= $h($id) ?>"><?= admin_te('shop.order_fields.option') ?></label>
        <input type="text" id="<?= $h($id) ?>" name="<?= $h($name) ?>[label]" maxlength="<?= ProductOrderFieldEditor::LABEL_MAX_LENGTH ?>" value="<?= $h($label) ?>" placeholder="<?= admin_te('shop.order_fields.option_placeholder') ?>">
      </div>
      <?php product_variants_row_tools(admin_t('shop.order_fields.remove_option'), 'data-order-field-option-remove', true); ?>
    </li>
    <?php
}
