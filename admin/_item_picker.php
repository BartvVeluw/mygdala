<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';

/**
 * A HAND-PICKED, ORDERED LIST of items (admin/assets/item-picker.js): the
 * projects of a Projecten block or a gallery ("Handmatige selectie",
 * admin/_gallery_selection.php) and the related projects of a project
 * (admin/portfolio-item.php). It names no source: the caller hands over the
 * choices, and what they are is the caller's business.
 *
 * THE SAME PATTERN AS THE COLLECTION'S PRODUCT PICKER (admin/collection.php,
 * collections-admin.js), and its row styles: every choice is a row with a
 * checkbox `<name>[]`, a small picture, its title, its categories and — for a
 * hidden one — a "Verborgen" badge. The picked rows come first, in their
 * picked order, and the browser posts the ticked boxes in the order they
 * stand, so the order on screen IS the stored order: nothing to serialise,
 * and it works without the script. A choice is one checkbox, so nothing can
 * be picked twice.
 *
 * With the script: a search over the titles, a ticked row joining the end of
 * the picked ones and an unticked one leaving them, ↑ and ↓ on a picked row
 * (the keyboard's way to reorder) and a drag on its handle, a live count, and
 * a status line a screen reader hears. Every move sends a `change` through the
 * form, so the save bar sees it (PAGE-EDITOR.md, "Wat gewijzigd betekent").
 *
 * `<submitted>` = 1 says the list was on the form: an endpoint replaces the
 * stored list only then, so a form without it never empties one.
 */

/**
 * @param string $name       the checkbox name without [] (item_ids, related_items)
 * @param string $submitted  the marker's name (items_submitted, related_items_submitted)
 * @param list<array{id: int, title: string, thumbnail: string, categories: string, visible: bool}> $choices
 * @param list<int> $selectedIds the picked ones, in their order
 * @param array{label: string, empty: string, id: string} $options label of the list, what to say without choices, a unique id prefix
 */
function item_picker_field(string $name, string $submitted, array $choices, array $selectedIds, array $options): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $id = preg_replace('/[^a-z0-9-]/', '-', strtolower($options['id']));

    if ($choices === []) {
        echo '<p class="admin-text-muted">' . $h($options['empty']) . '</p>';

        return;
    }

    $byId = [];
    foreach ($choices as $choice) {
        $byId[(int) $choice['id']] = $choice;
    }

    // The picked ones first, in their order (a stale id that no choice has
    // any more is simply gone), then every other choice in its own order.
    $rows = [];
    foreach ($selectedIds as $selectedId) {
        if (isset($byId[(int) $selectedId]) && !isset($rows[(int) $selectedId])) {
            $rows[(int) $selectedId] = true;
        }
    }
    $pickedCount = count($rows);
    foreach (array_keys($byId) as $choiceId) {
        $rows[$choiceId] ??= false;
    }

    $words = json_encode([
        'count' => admin_t('item_picker.count'),
        'moved' => admin_t('item_picker.moved'),
        'picked' => admin_t('item_picker.picked'),
        'unpicked' => admin_t('item_picker.unpicked'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>
    <div class="admin-item-picker" data-item-picker data-item-picker-words="<?= $h((string) $words) ?>">
      <input type="hidden" name="<?= $h($submitted) ?>" value="1">
      <div class="admin-collection-picker-toolbar">
        <label class="admin-search">
          <span class="admin-visually-hidden"><?= admin_te('item_picker.search_label') ?></span>
          <input type="search" placeholder="<?= admin_te('item_picker.search_placeholder') ?>" data-item-picker-search>
        </label>
        <span class="admin-text-muted" data-item-picker-count><?= admin_te('item_picker.count', ['n' => (string) $pickedCount]) ?></span>
      </div>
      <ul class="admin-item-picker__list" data-item-picker-list id="<?= $h($id) ?>-list" aria-label="<?= $h($options['label']) ?>">
        <?php foreach ($rows as $choiceId => $picked): ?>
          <?php
            $choice = $byId[$choiceId];
            $title = trim((string) $choice['title']) !== '' ? (string) $choice['title'] : admin_t('item_picker.untitled');
            $checkboxId = $id . '-' . $choiceId;
          ?>
          <li class="admin-section-row admin-collection-product-row admin-item-picker__row<?= $picked ? ' is-selected' : '' ?>"
              data-item-picker-row data-id="<?= (int) $choiceId ?>" data-name="<?= $h(mb_strtolower($title . ' ' . (string) $choice['categories'])) ?>">
            <span class="admin-drag-handle" data-item-picker-handle title="<?= admin_te('item_picker.drag') ?>" aria-hidden="true">&#10021;</span>
            <label class="admin-collection-product-row__pick" for="<?= $h($checkboxId) ?>">
              <input type="checkbox" class="admin-checkbox" id="<?= $h($checkboxId) ?>" name="<?= $h($name) ?>[]" value="<?= (int) $choiceId ?>"<?= $picked ? ' checked' : '' ?> data-item-picker-checkbox>
              <span class="admin-collection-product-row__thumb">
                <?php if ((string) $choice['thumbnail'] !== ''): ?>
                  <img src="<?= $h((string) $choice['thumbnail']) ?>" alt="" loading="lazy">
                <?php endif; ?>
              </span>
              <span class="admin-section-row__body">
                <span class="admin-section-row__name" data-item-picker-title><?= $h($title) ?></span>
                <?php if ((string) $choice['categories'] !== ''): ?>
                  <span class="admin-text-muted"><?= $h((string) $choice['categories']) ?></span>
                <?php endif; ?>
              </span>
            </label>
            <?php if (!$choice['visible']): ?>
              <span class="admin-badge admin-badge--canceled" title="<?= admin_te('item_picker.hidden_title') ?>"><?= admin_te('item_picker.hidden') ?></span>
            <?php endif; ?>
            <span class="admin-item-picker__moves" data-item-picker-moves hidden>
              <button type="button" class="admin-btn-text" data-item-picker-move="-1" aria-label="<?= admin_te('item_picker.up', ['name' => $title]) ?>">&uarr;</button>
              <button type="button" class="admin-btn-text" data-item-picker-move="1" aria-label="<?= admin_te('item_picker.down', ['name' => $title]) ?>">&darr;</button>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="admin-text-muted" data-item-picker-none hidden><?= admin_te('item_picker.none_found') ?></p>
      <p class="admin-visually-hidden" role="status" aria-live="polite" data-item-picker-status></p>
    </div>
    <?php
}

/** The picker's script, once per screen. */
function item_picker_script(): void
{
    echo '<script src="' . \App\Service\AssetVersion::url('/admin/assets/item-picker.js') . '" defer></script>' . "\n";
}
