<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_link_target_field.php';

use App\Service\Media\LinkedImages;
use App\Service\Routing\LinkTargets;

/**
 * WHERE A GALLERY ITEM'S PICTURE COMES FROM (Detailsectie 2.0): the Media
 * Library, or an item of the site — a product, a Portfolio project, a blog
 * post, whatever App\Service\Media\LinkedImages offers — shown with that
 * item's own picture and linked to its page. First the source, then only the
 * picker of that source, the Destination Picker's way
 * (admin/_link_target_field.php): the same data-nav-link-* switch
 * (admin/assets/navigation-item.js, one [data-nav-link-group] per row) and,
 * for an item, the same searchable list with picture and status
 * (admin/assets/destination-picker.js, built from the row's own <select>,
 * which stays what is posted). There is no second address field: an item
 * links to itself.
 *
 * ONE CHOICE, "Afbeeldingsbron" (Detailsectie 2.1): the Media Library, or a
 * kind. An item needs nothing else: its MAIN PICTURE (the module's
 * linkedImages() provider) and its PAGE (LinkTargets::href()) come with it,
 * live, so there is no second, required picture to pick. Only the panel of
 * the chosen source shows; that switch is admin/assets/navigation-item.js,
 * so the form must carry data-nav-item-form. An item without a main picture
 * yet says so (data-linked-image-no-picture) and is a name tile on the site.
 *
 * What it posts, per row: `source` ('media' or a kind) and, per kind,
 * `source_<kind>` = the chosen id. The endpoint keeps a stored choice the
 * picker can no longer offer (gone, not public, a module that is off) and
 * says so here, as the Destination Picker does; the website leaves such an
 * item out.
 *
 * $mediaPart prints the Media Library half (the picker and the alt text), so
 * the row's own existing fields stay exactly as they were.
 *
 * @param array<string, string> $fields the row's values
 * @param callable(): void      $mediaPart
 */
function gallery_source_field(string $list, string $key, array $fields, callable $mediaPart): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $kinds = LinkedImages::kinds();
    $source = (string) ($fields['source'] ?? '');
    $source = $source === '' ? 'media' : $source;
    // A stored kind whose module is off, or that no module offers any more:
    // kept, and named, so opening and saving the form loses nothing.
    $keepsUnavailable = $source !== 'media' && !isset($kinds[$source]);
    $disabledModule = $keepsUnavailable ? LinkedImages::disabledModuleOf($source) : null;
    $id = 'gallery-' . $list . '-' . $key;
    // The stored item is public but has no main picture yet: say so now.
    $storedItem = $source !== 'media' && !$keepsUnavailable ? LinkedImages::item($source, (int) ($fields['source_' . $source] ?? 0)) : null;
    $withoutPicture = $storedItem !== null && $storedItem['image_path'] === '';
    $name = static fn (string $field): string => editor_row_name($list, $key, $field);
    ?>
      <div class="admin-gallery-source" data-nav-link-group>
        <div class="admin-field">
          <?= admin_field_label($id . '-source', admin_t('gallery_source.source'), admin_t('help.gallery_source.source')) ?>
          <select class="admin-select" id="<?= $h($id) ?>-source" name="<?= $h($name('source')) ?>" data-nav-link-type>
            <option value="media"<?= $source === 'media' ? ' selected' : '' ?>><?= admin_te('gallery_source.media') ?></option>
            <?php foreach ($kinds as $kind => $label): ?>
              <option value="<?= $h($kind) ?>"<?= $source === $kind ? ' selected' : '' ?>><?= $h($label) ?></option>
            <?php endforeach; ?>
            <?php if ($keepsUnavailable): ?>
              <option value="<?= $h($source) ?>" selected><?= $disabledModule !== null ? admin_te('link_choice.unavailable_module', ['module' => $disabledModule]) : admin_te('link_choice.unavailable') ?></option>
            <?php endif; ?>
          </select>
        </div>

        <div data-nav-link-field="media">
          <?php $mediaPart(); ?>
        </div>

        <?php foreach (array_keys($kinds) as $kind): ?>
          <?php
            $selected = (int) ($fields['source_' . $kind] ?? 0);
            $choices = link_target_choices($kind);
            $chosen = null;
            foreach ($choices as $choice) {
                if ((int) $choice['id'] === $selected && empty($choice['context'])) {
                    $chosen = $choice;
                }
            }
            $kindLabel = LinkTargets::label($kind);
          ?>
          <div class="admin-field admin-destination__panel" data-nav-link-field="<?= $h($kind) ?>"<?= link_target_search_attributes($kindLabel) ?>>
            <?= admin_field_label($id . '-' . $kind, $kindLabel) ?>
            <select class="admin-select" id="<?= $h($id . '-' . $kind) ?>" name="<?= $h($name('source_' . $kind)) ?>" data-destination-select="<?= $h($kind) ?>">
              <option value=""><?= admin_te('link_choice.choose') ?></option>
              <?php if ($selected > 0 && $chosen === null): ?>
                <option value="<?= $selected ?>" selected data-note="gone" data-name="#<?= $selected ?>"><?= admin_te('link_choice.gone_option', ['id' => (string) $selected]) ?></option>
              <?php endif; ?>
              <?php foreach ($choices as $choice): ?>
                <?php $note = (string) ($choice['note'] ?? ''); ?>
                <option value="<?= (int) $choice['id'] ?>"<?= !empty($choice['context']) ? ' disabled' : ($selected === (int) $choice['id'] ? ' selected' : '') ?><?= $note !== '' ? ' data-note="' . $h($note) . '"' : '' ?><?= isset($choice['thumbnail']) ? ' data-thumbnail="' . $h((string) $choice['thumbnail']) . '"' : '' ?> data-name="<?= $h((string) $choice['label']) ?>"><?= $h($choice['label']) ?><?= $note !== '' ? ' ' . admin_te('link_choice.note_' . $note) : '' ?></option>
              <?php endforeach; ?>
            </select>
            <p class="admin-text-muted admin-gallery-source__follows"><?= admin_te('gallery_source.follows') ?></p>
            <?php if ($selected > 0 && $chosen === null): ?>
              <p class="admin-alert admin-alert--warning admin-destination__warning" role="status" data-destination-warning="<?= $selected ?>"><?= admin_te('gallery_source.gone_warning') ?></p>
            <?php elseif ($chosen !== null && isset($chosen['note'])): ?>
              <p class="admin-destination__note" data-destination-warning="<?= $selected ?>"><?= admin_te('gallery_source.not_public') ?></p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <?php /* An item chosen now that a visitor cannot see has no picture for
                 the focus frame: admin/assets/gallery-source.js shows this
                 line then. A stored choice has its own warning above. */ ?>
        <p class="admin-text-muted" data-linked-image-missing hidden><?= admin_te('gallery_source.no_preview') ?></p>
        <?php /* A public item without a main picture yet (Detailsectie 2.1):
                 nothing to choose here, the website shows its name as a
                 tile. Shown for the stored choice, and by gallery-source.js
                 for one made on screen. */ ?>
        <p class="admin-alert admin-alert--warning" role="status" data-linked-image-no-picture<?= $withoutPicture ? '' : ' hidden' ?>><?= admin_te('gallery_source.no_picture') ?></p>

        <?php if ($keepsUnavailable): ?>
          <?php /* The stored item, as the endpoint needs it to keep it. */ ?>
          <input type="hidden" name="<?= $h($name('source_' . $source)) ?>" value="<?= (int) ($fields['source_' . $source] ?? 0) ?>">
          <p class="admin-alert admin-alert--warning" data-nav-link-field="<?= $h($source) ?>"><?= $disabledModule !== null ? admin_te('gallery_source.module_off', ['module' => $disabledModule]) : admin_te('gallery_source.gone_warning') ?></p>
        <?php endif; ?>
      </div>
    <?php
}

/**
 * The script that keeps a row's focus frame on the picture its source shows
 * now (admin/assets/gallery-source.js); print it once, near the end of
 * <body>, on a screen whose row list carries data-linked-image-preview.
 */
function gallery_source_field_script(): void
{
    ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/gallery-source.js') ?>" defer></script>
    <?php
}
