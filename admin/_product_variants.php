<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_product_gallery.php';
require_once __DIR__ . '/_product_inventory.php';

/**
 * The product editor's Varianten section (admin/product-form.php): the
 * options with their values, and the variants made of them — each variant
 * with its price, its switch, its pictures from the product's pool and its
 * own description. ALL OF IT IS PART OF THE PRODUCT FORM: adding an option,
 * a value or a variant is a row on the screen (admin/assets/row-list.js), and
 * the editor's one Opslaan stores everything at once
 * (api/admin/update-product.php, App\Service\ProductVariantEditor). Nothing
 * here posts on its own, and nothing reloads the page.
 *
 * ROWS BY KEY. A stored row is named by its id, a row typed on the screen by
 * "new<n>" (the templates' __KEY__, and __VKEY__ for a value inside an
 * option that is itself new). A new variant chooses its values by those keys
 * (admin/assets/product-variants.js builds its choices from the options on
 * screen), so an option, a value and a variant made of them can be added in
 * one go. After a save the editor draws this section again from the server,
 * and every row carries its id from then on.
 *
 * THE BUTTONS ONLY DO WHAT THE SERVER WOULD ALLOW. An option or value a
 * variant uses cannot be removed, and neither can a variant an order points
 * at; their × is disabled and a short note says why. The server checks all
 * of it again.
 *
 * Without JavaScript the rows the server drew are still posted and saved
 * (names, display types, values, prices, switches); adding, moving and
 * removing rows needs the script, as choosing a picture from the library
 * already does.
 *
 * A VARIANT'S STOCK is a field of its row (Shop Product & Ordering 2.0),
 * shown while "Voorraad bijhouden" is on (admin/_product_inventory.php) with
 * its status beside it. It carries the value it showed (`stock_seen`), for
 * the same reason the product's own stock does: a sale in the meantime is
 * never undone by a save (App\Service\ProductVariantEditor).
 */

/**
 * The whole section: what goes inside its region.
 *
 * @param list<array<string, mixed>> $options ProductOptionRepository::findByProductId() rows
 * @param list<array<string, mixed>> $variants ProductVariantRepository::findByProductId() rows
 * @param list<array{token: string, src: string, name: string, media_id: ?int, variant_only: bool}> $pictures the general pictures as shown
 * @param array<int, array{tokens: list<string>, own: bool, html: string}> $variantGallery per variant id
 * @param list<int> $lockedVariantIds variants an order points at
 * @param bool $stockTracked whether the product's "Voorraad bijhouden" is on
 * @param list<array{token: string, src: string, name: string, media_id: ?int, variant_only: bool}> $variantOnlyPictures the pictures meant for variants only
 */
function product_variants_section(array $options, array $variants, array $pictures, array $variantGallery, array $lockedVariantIds, bool $stockTracked = false, array $variantOnlyPictures = []): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    // A variant ticks from the whole pool: the general pictures, then the
    // ones meant for variants only.
    $pool = [...$pictures, ...$variantOnlyPictures];
    $linkedTokens = [];
    foreach ($variantGallery as $selection) {
        array_push($linkedTokens, ...$selection['tokens']);
    }

    // Which stored values a variant uses: "option:value" keys, the shape the
    // script compares the screen against.
    $used = [];
    foreach ($variants as $variant) {
        foreach ($variant['values'] as $value) {
            $used[(int) $value['option_id'] . ':' . (int) $value['value_id']] = true;
        }
    }

    $words = json_encode([
        'choose' => admin_t('shop.editor.choose_value'),
        'option' => admin_t('shop.editor.option'),
        'unnamed' => admin_t('shop.editor.unnamed_value'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>
    <div class="admin-product-variants" data-product-variants data-product-variants-words="<?= $h((string) $words) ?>">
      <div class="admin-alert admin-alert--error" data-admin-editor-errors="variants" hidden></div>
      <p class="admin-text-muted"><?= admin_te('shop.editor.variants_intro') ?></p>

      <h3 class="admin-product-variants__heading"><?= admin_te('shop.opties') ?></h3>
      <input type="hidden" name="options_present" value="1">
      <div class="admin-product-options" data-row-list="product-options" data-product-options>
        <?php foreach ($options as $option): ?>
          <?php
            $optionKey = (string) (int) $option['id'];
            $values = [];
            foreach ($option['values'] as $value) {
                $values[] = [
                    'key' => (string) (int) $value['id'],
                    'value' => (string) $value['value'],
                    'hex_color' => (string) ($value['hex_color'] ?? ''),
                    'used' => isset($used[$optionKey . ':' . (int) $value['id']]),
                ];
            }
            product_variants_option_row($optionKey, (string) $option['name'], (string) ($option['display_type'] ?? 'standard'), $values);
          ?>
        <?php endforeach; ?>
      </div>
      <p class="admin-text-muted" data-product-options-empty<?= $options !== [] ? ' hidden' : '' ?>><?= admin_te('shop.opties_2') ?></p>
      <template data-row-list-template="product-options"><?php product_variants_option_row('__KEY__', '', 'standard', []); ?></template>
      <p class="admin-visually-hidden" role="status" aria-live="polite" data-row-list-status="product-options" data-row-list-moved="<?= admin_te('editor_rows.verplaatst') ?>"></p>
      <div class="admin-option-rows__tools">
        <button type="button" class="admin-btn-secondary" data-row-list-add="product-options" hidden>+ <?= admin_te('shop.optie_toevoegen') ?></button>
      </div>

      <h3 class="admin-product-variants__heading"><?= admin_te('shop.varianten') ?></h3>
      <input type="hidden" name="variants_present" value="1">
      <div class="admin-variant-list" data-row-list="product-variants" data-product-variant-list>
        <?php foreach ($variants as $variant): ?>
          <?php
            $variantId = (int) $variant['id'];
            $label = implode(', ', array_map(
                static fn (array $v): string => $v['option_name'] . ': ' . $v['value'],
                $variant['values']
            ));
            product_variants_variant_row(
                (string) $variantId,
                [
                    'label' => $label,
                    'pairs' => implode(' ', array_map(
                        static fn (array $v): string => (int) $v['option_id'] . ':' . (int) $v['value_id'],
                        $variant['values']
                    )),
                    'price' => $variant['price'] !== null ? number_format((float) $variant['price'], 2, '.', '') : '',
                    'active' => (int) $variant['active'] === 1,
                    'locked' => in_array($variantId, $lockedVariantIds, true),
                    'stock' => (int) ($variant['stock'] ?? 0),
                    'stock_seen' => (int) ($variant['stock'] ?? 0),
                    'tracked' => $stockTracked,
                ],
                $pool,
                $variantGallery[$variantId] ??['tokens' => [], 'own' => false, 'html' => '']
            );
          ?>
        <?php endforeach; ?>
      </div>
      <p class="admin-text-muted" data-product-variants-empty<?= $variants !== [] ? ' hidden' : '' ?>><?= admin_te('shop.varianten_2') ?></p>
      <template data-row-list-template="product-variants"><?php product_variants_variant_row('__KEY__', ['label' => admin_t('shop.nieuwe_variant'), 'pairs' => '', 'price' => '', 'active' => true, 'locked' => false, 'stock' => 0, 'stock_seen' => null, 'tracked' => $stockTracked], $pool, ['tokens' => [], 'own' => false, 'html' => '']); ?></template>
      <p class="admin-visually-hidden" role="status" aria-live="polite" data-row-list-status="product-variants" data-row-list-moved="<?= admin_te('editor_rows.verplaatst') ?>"></p>
      <div class="admin-option-rows__tools">
        <button type="button" class="admin-btn-secondary" data-row-list-add="product-variants" hidden>+ <?= admin_te('shop.editor.add_variant') ?></button>
      </div>

      <?php product_gallery_variant_only_pool($variantOnlyPictures, $linkedTokens); ?>
    </div>
    <?php
}

/**
 * ↑, ↓ and × of one row, as buttons that do nothing without the script: a
 * move or a removal is a change on the screen, stored with the rest.
 */
function product_variants_row_tools(string $removeLabel, string $removeAttribute, bool $removable): void
{
    ?>
    <span class="admin-row-card__tools">
      <span class="admin-option-row__move">
        <button type="button" class="admin-btn-ghost admin-option-row__move-button" data-row-list-move="up" aria-label="<?= admin_te('editor_rows.omhoog') ?>"><span aria-hidden="true">&uarr;</span></button>
        <button type="button" class="admin-btn-ghost admin-option-row__move-button" data-row-list-move="down" aria-label="<?= admin_te('editor_rows.omlaag') ?>"><span aria-hidden="true">&darr;</span></button>
      </span>
      <button type="button" class="admin-btn-text admin-btn-text--danger" data-row-list-remove <?= $removeAttribute ?><?= $removable ? '' : ' disabled' ?>><?= htmlspecialchars($removeLabel, ENT_QUOTES, 'UTF-8') ?></button>
    </span>
    <?php
}

/**
 * One option with its values.
 *
 * @param list<array{key: string, value: string, hex_color: string, used: bool}> $values
 */
function product_variants_option_row(string $key, string $name, string $displayType, array $values): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $id = 'product-option-' . $key;
    $inUse = in_array(true, array_column($values, 'used'), true);
    $isNew = !ctype_digit($key);
    ?>
    <fieldset class="admin-row-card admin-product-option" data-row-list-row data-option-key="<?= $h($key) ?>" data-option-display-type="<?= $h($displayType) ?>">
      <legend class="admin-visually-hidden"><?= admin_te('shop.editor.option') ?> <span data-row-list-number></span></legend>
      <input type="hidden" name="options[<?= $h($key) ?>][present]" value="1">
      <div class="admin-product-option__head">
        <div class="admin-field admin-product-option__name">
          <label for="<?= $h($id) ?>-name"><?= admin_te('shop.editor.option_name') ?><?= $isNew ? ' <span class="admin-badge admin-badge--info">' . admin_te('editor_rows.nieuw') . '</span>' : '' ?></label>
          <input type="text" id="<?= $h($id) ?>-name" name="options[<?= $h($key) ?>][name]" maxlength="<?= \App\Service\ProductVariantEditor::TEXT_MAX_LENGTH ?>" value="<?= $h($name) ?>" placeholder="<?= admin_te('shop.editor.option_placeholder') ?>" data-option-name>
        </div>
        <div class="admin-field admin-product-option__display">
          <label for="<?= $h($id) ?>-display"><?= admin_te('shop.weergave') ?></label>
          <select id="<?= $h($id) ?>-display" name="options[<?= $h($key) ?>][display_type]" data-option-display>
            <option value="standard"<?= $displayType !== 'color' ? ' selected' : '' ?>><?= admin_te('shop.standaard') ?></option>
            <option value="color"<?= $displayType === 'color' ? ' selected' : '' ?>><?= admin_te('shop.kleur') ?></option>
          </select>
        </div>
        <?php product_variants_row_tools(admin_t('shop.optie_verwijderen'), 'data-option-remove', !$inUse); ?>
      </div>
      <p class="admin-row-card__note" data-option-in-use<?= $inUse ? '' : ' hidden' ?>><?= admin_te('shop.editor.option_in_use') ?></p>

      <ul class="admin-option-values" data-row-list="product-option-values-<?= $h($key) ?>" data-option-values>
        <?php foreach ($values as $value): ?>
          <?php product_variants_value_row($key, $value['key'], $value['value'], $value['hex_color'], $value['used']); ?>
        <?php endforeach; ?>
      </ul>
      <template data-row-list-template="product-option-values-<?= $h($key) ?>" data-row-list-key="__VKEY__"><?php product_variants_value_row($key, '__VKEY__', '', '', false); ?></template>
      <p class="admin-visually-hidden" role="status" aria-live="polite" data-row-list-status="product-option-values-<?= $h($key) ?>" data-row-list-moved="<?= admin_te('editor_rows.verplaatst') ?>"></p>
      <div class="admin-option-rows__tools">
        <button type="button" class="admin-btn-secondary admin-product-option__add-value" data-row-list-add="product-option-values-<?= $h($key) ?>" hidden>+ <?= admin_te('shop.waarde_toevoegen') ?></button>
      </div>
    </fieldset>
    <?php
}

/** One value of an option. Its colour is sent always, and shown for a "Kleur" option. */
function product_variants_value_row(string $optionKey, string $key, string $value, string $hexColor, bool $used): void
{
    $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $id = 'product-option-' . $optionKey . '-value-' . $key;
    $picker = preg_match('/^#[0-9A-Fa-f]{6}$/', $hexColor) === 1 ? $hexColor : '#A77A49';
    $name = 'option_values[' . $optionKey . '][' . $key . ']';
    ?>
    <li class="admin-option-value" data-row-list-row data-value-key="<?= $h($key) ?>">
      <input type="hidden" name="<?= $h($name) ?>[present]" value="1">
      <div class="admin-field admin-option-value__text">
        <label class="admin-visually-hidden" for="<?= $h($id) ?>"><?= admin_te('shop.editor.value') ?></label>
        <input type="text" id="<?= $h($id) ?>" name="<?= $h($name) ?>[value]" maxlength="<?= \App\Service\ProductVariantEditor::TEXT_MAX_LENGTH ?>" value="<?= $h($value) ?>" placeholder="<?= admin_te('shop.editor.value_placeholder') ?>" data-value-text>
      </div>
      <span class="admin-option-value__color" data-color-sync>
        <input type="color" value="<?= $h($picker) ?>" data-color-picker aria-label="<?= admin_te('shop.kleur') ?>">
        <input type="text" name="<?= $h($name) ?>[hex_color]" maxlength="7" placeholder="#A77A49" value="<?= $h($hexColor) ?>" data-color-hex class="admin-hex-input" aria-label="<?= admin_te('shop.editor.hex') ?>">
      </span>
      <?php product_variants_row_tools(admin_t('shop.editor.remove_value'), 'data-value-remove', !$used); ?>
      <span class="admin-row-card__note" data-value-in-use<?= $used ? '' : ' hidden' ?>><?= admin_te('shop.editor.value_in_use') ?></span>
    </li>
    <?php
}

/**
 * One variant. A stored one shows the combination it is; a new one chooses
 * it ([data-variant-choices], filled by admin/assets/product-variants.js).
 *
 * @param array{label: string, pairs: string, price: string, active: bool, locked: bool, stock?: int, stock_seen?: ?int, tracked?: bool} $variant
 * @param list<array{token: string, src: string, name: string, media_id: ?int}> $pictures the pool
 * @param array{tokens: list<string>, own: bool, html: string} $gallery
 */
function product_variants_variant_row(string $key, array $variant, array $pictures, array $gallery): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $isNew = !ctype_digit($key);
    $id = 'product-variant-' . $key;
    ?>
    <article class="admin-row-card admin-variant-panel" data-row-list-row data-variant-key="<?= $h($key) ?>"<?= $isNew ? ' data-variant-new' : ' data-variant-values="' . $h($variant['pairs']) . '"' ?>>
      <input type="hidden" name="variants[<?= $h($key) ?>][present]" value="1">
      <div class="admin-variant-panel__head">
        <strong class="admin-variant-panel__label"><?= $h($variant['label']) ?><?= $isNew ? ' <span class="admin-badge admin-badge--info">' . admin_te('editor_rows.nieuw') . '</span>' : '' ?></strong>
        <?php product_variants_row_tools(admin_t('shop.variant_verwijderen'), 'data-variant-remove', !$variant['locked']); ?>
      </div>
      <?php if ($variant['locked']): ?>
        <p class="admin-row-card__note"><?= admin_te('shop.editor.variant_in_orders') ?></p>
      <?php endif; ?>

      <?php if ($isNew): ?>
        <div class="admin-variant-panel__choices" data-admin-editor-error-for="variants[<?= $h($key) ?>][values]">
          <p class="admin-text-muted" data-variant-choices-empty hidden><?= admin_te('shop.voeg_eerst_optie_waardes') ?></p>
          <div class="admin-variant-panel__choice-fields" data-variant-choices></div>
        </div>
      <?php endif; ?>

      <div class="admin-variant-panel__fields">
        <div class="admin-field">
          <label for="<?= $h($id) ?>-price"><?= admin_t('shop.prijs_override_leeg_productprijs') ?></label>
          <input type="text" inputmode="decimal" id="<?= $h($id) ?>-price" name="variants[<?= $h($key) ?>][price]" value="<?= $h($variant['price']) ?>" placeholder="0.00">
        </div>
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="variants[<?= $h($key) ?>][active]" value="1"<?= $variant['active'] ? ' checked' : '' ?>>
          <?= admin_te('common.active') ?>
        </label>
        <?php $tracked = (bool) ($variant['tracked'] ?? false); ?>
        <div class="admin-field admin-variant-panel__stock" data-stock-tracked-only<?= $tracked ? '' : ' hidden' ?>>
          <label for="<?= $h($id) ?>-stock"><?= admin_te('shop.stock.quantity') ?></label>
          <input type="number" id="<?= $h($id) ?>-stock" name="variants[<?= $h($key) ?>][stock]" min="0" max="<?= \App\Service\Inventory\InventoryEditor::MAX_STOCK ?>" step="1" inputmode="numeric" value="<?= max(0, (int) ($variant['stock'] ?? 0)) ?>">
          <?php if (($variant['stock_seen'] ?? null) !== null): ?>
            <input type="hidden" name="variants[<?= $h($key) ?>][stock_seen]" value="<?= (int) $variant['stock_seen'] ?>">
            <?= product_stock_badge(new \App\Service\Inventory\StockUnit(0, 1, true, (int) $variant['stock_seen'])) ?>
          <?php endif; ?>
        </div>
      </div>

      <?php product_gallery_variant_block($pictures, [
          'key' => $key,
          'label' => $variant['label'],
          'tokens' => $gallery['tokens'],
          'own' => $gallery['own'],
          'html' => $gallery['html'],
      ]); ?>
    </article>
    <?php
}
