<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_item_picker.php';

use App\Service\ItemGalleryContent;
use App\Service\ItemGallerySelection;
use App\Service\ItemGallerySources;

/**
 * THE CHOICE OF ITEMS of a gallery block on screen (Projecten 2.0,
 * CONTENT-BLOCKS.md): which items a block shows and in what order. The
 * Projecten block (admin/project-cards.php) and the gallery block
 * (admin/item-gallery.php) print it; App\Service\ItemGallerySelection reads it
 * back, for both. It names no source: its categories and its items come from
 * App\Service\ItemGallerySources.
 *
 *   Bron       all visible items, the items of one category, or a
 *              hand-picked list (portfolio_scope)
 *   Categorie  the source's categories, each with how many visible items it
 *              has; a stored category that no longer exists is said, not
 *              silently replaced
 *   Volgorde   for all and one category: the source's own order, newest,
 *              oldest, A–Z, Z–A or random
 *   Selectie   for a hand-picked list: the shared picker (admin/_item_picker.php)
 *              and "in willekeurige volgorde", its only other order
 *
 * Every part is always on the form, whichever source is chosen — the parts a
 * source does not use are hidden by admin/assets/gallery-selection.js (the
 * server prints the same `hidden`) but still sent, so switching to "all" and
 * back finds the category and the list as they were.
 */

/**
 * The fields.
 *
 * @param string    $source   the source whose items are chosen
 * @param array<string, mixed> $values portfolio_scope, portfolio_category_id, item_sort, max_items (stored or handed back)
 * @param list<int> $selected the picked items, in order (stored or handed back)
 * @param string    $idPrefix unique on the screen
 */
function gallery_selection_fields(string $source, array $values, array $selected, string $idPrefix): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $scope = (string) ($values['portfolio_scope'] ?? ItemGalleryContent::SCOPE_ALL);
    if (!ItemGalleryContent::isPortfolioScope($scope)) {
        $scope = ItemGalleryContent::SCOPE_ALL;
    }
    $sort = (string) ($values['item_sort'] ?? 'source');
    $categoryId = (int) ($values['portfolio_category_id'] ?? 0);

    $categories = ItemGallerySources::categoryChoices($source);
    $categoryIds = array_map(static fn (array $category): int => (int) $category['id'], $categories);
    $categoryGone = $scope === ItemGalleryContent::SCOPE_CATEGORY && !in_array($categoryId, $categoryIds, true);

    $scopes = [
        ItemGalleryContent::SCOPE_ALL => admin_t('gallery_selection.scope_all'),
        ItemGalleryContent::SCOPE_CATEGORY => admin_t('gallery_selection.scope_category'),
        ItemGalleryContent::SCOPE_MANUAL => admin_t('gallery_selection.scope_manual'),
    ];
    $sorts = [
        'source' => admin_t('gallery_selection.sort_source'),
        'newest' => admin_t('gallery_selection.sort_newest'),
        'oldest' => admin_t('gallery_selection.sort_oldest'),
        'title_asc' => admin_t('gallery_selection.sort_title_asc'),
        'title_desc' => admin_t('gallery_selection.sort_title_desc'),
        'random' => admin_t('gallery_selection.sort_random'),
    ];
    $hidden = static fn (string ...$scopes): string => in_array($scope, $scopes, true) ? '' : ' hidden';
    $words = json_encode([
        'none' => admin_t('gallery_selection.note_none'),
        'all' => admin_t('gallery_selection.note_all'),
        'some' => admin_t('gallery_selection.note_some'),
        'random_some' => admin_t('gallery_selection.note_random_some'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>
    <div class="admin-gallery-selection" data-gallery-selection data-gallery-selection-words="<?= $h((string) $words) ?>">
      <div class="admin-form-row admin-form-row--split">
        <div class="admin-field">
          <?= admin_field_label($idPrefix . '-scope', admin_t('gallery_selection.scope'), admin_t('help.gallery_selection.scope')) ?>
          <select id="<?= $h($idPrefix) ?>-scope" name="portfolio_scope" data-gallery-scope>
            <?php foreach ($scopes as $key => $label): ?>
              <option value="<?= $h($key) ?>"<?= $scope === $key ? ' selected' : '' ?>><?= $h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="admin-field" data-gallery-needs="category"<?= $hidden(ItemGalleryContent::SCOPE_CATEGORY) ?>>
          <?= admin_field_label($idPrefix . '-category', admin_t('gallery_selection.category'), admin_t('help.gallery_selection.category')) ?>
          <select id="<?= $h($idPrefix) ?>-category" name="category_id">
            <option value=""><?= admin_te('gallery_selection.category_choose') ?></option>
            <?php foreach ($categories as $category): ?>
              <option value="<?= (int) $category['id'] ?>"<?= (int) $category['id'] === $categoryId ? ' selected' : '' ?>><?= admin_te('gallery_selection.category_option', ['name' => (string) $category['name'], 'count' => (string) $category['count']]) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="admin-field" data-gallery-needs="all category"<?= $hidden(ItemGalleryContent::SCOPE_ALL, ItemGalleryContent::SCOPE_CATEGORY) ?>>
          <?= admin_field_label($idPrefix . '-sort', admin_t('gallery_selection.sort'), admin_t('help.gallery_selection.sort')) ?>
          <select id="<?= $h($idPrefix) ?>-sort" name="item_sort">
            <?php foreach ($sorts as $key => $label): ?>
              <option value="<?= $h($key) ?>"<?= $sort === $key ? ' selected' : '' ?>><?= $h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <?php if ($categoryGone): ?>
        <p class="admin-alert admin-alert--warning" data-gallery-needs="category"><?= admin_te('gallery_selection.category_gone') ?></p>
      <?php elseif ($categories === []): ?>
        <p class="admin-text-muted" data-gallery-needs="category"<?= $hidden(ItemGalleryContent::SCOPE_CATEGORY) ?>><?= admin_te('gallery_selection.no_categories') ?></p>
      <?php endif; ?>

      <div class="admin-field" data-gallery-needs="manual"<?= $hidden(ItemGalleryContent::SCOPE_MANUAL) ?>>
        <div class="admin-field__label">
          <span id="<?= $h($idPrefix) ?>-picker-label"><?= admin_te('gallery_selection.picker') ?></span>
          <?= admin_help(admin_t('gallery_selection.picker'), admin_t('help.gallery_selection.picker')) ?>
        </div>
        <?php item_picker_field('item_ids', 'items_submitted', ItemGallerySources::itemChoices($source), $selected, [
            'label' => admin_t('gallery_selection.picker'),
            'empty' => admin_t('gallery_selection.picker_empty'),
            'id' => $idPrefix . '-picker',
        ]); ?>
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-checkbox" name="manual_random" value="1"<?= $scope === ItemGalleryContent::SCOPE_MANUAL && $sort === 'random' ? ' checked' : '' ?>>
          <?= admin_te('gallery_selection.manual_random') ?>
        </label>
        <p class="admin-text-muted" data-gallery-manual-note></p>
      </div>
    </div>
    <?php
}

/**
 * The maximum as a choice of fixed numbers and "all"
 * (ItemGallerySelection::MAXIMUMS), plus a number stored before there was a
 * list, so saving never changes it unasked.
 */
function gallery_max_select(string $id, ?int $current, string $label, string $help): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $options = ItemGallerySelection::MAXIMUMS;
    if ($current !== null && !in_array($current, $options, true)) {
        $options[] = $current;
        sort($options);
    }
    ?>
    <div class="admin-field">
      <?= admin_field_label($id, $label, $help) ?>
      <select id="<?= $h($id) ?>" name="max_items" data-gallery-max>
        <option value=""<?= $current === null ? ' selected' : '' ?>><?= admin_te('gallery_selection.max_all') ?></option>
        <?php foreach ($options as $option): ?>
          <option value="<?= (int) $option ?>"<?= $current === $option ? ' selected' : '' ?>><?= (int) $option ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php
}

/** The scripts of the choice, once per screen. */
function gallery_selection_script(): void
{
    item_picker_script();
    echo '<script src="' . \App\Service\AssetVersion::url('/admin/assets/gallery-selection.js') . '" defer></script>' . "\n";
}
