<?php

declare(strict_types=1);

use App\Service\AssetVersion;
use App\Service\Csrf;
use App\Service\Media\MediaItem;

/**
 * The reusable Media picker: the field a CMS screen renders when it wants an
 * image, and the one modal that serves every field on the page.
 *
 * THE POINT OF IT. A block that needs a public site image should not have to
 * build an upload UI. Before this, every image-bearing editor carried its own
 * <input type="file">, its own alt-text fields, its own replace/remove
 * buttons and its own endpoint that knew about uploading. A screen now
 * renders one call to media_picker_field() and gets back a media id in an
 * ordinary hidden input, which its existing form posts like any other value.
 *
 * HOW A SCREEN USES IT
 *
 *     require_once __DIR__ . '/_media_picker.php';
 *     ...
 *     media_picker_field('media_id', MediaService::find($row['media_id']));
 *     ...
 *     media_picker_modal();   // once, just before </body>
 *     <script src=".../media-picker.js" defer></script>
 *
 * WHAT COMES BACK. A media id, and nothing else. The endpoint on the
 * receiving end still checks that the id names a real media item
 * (App\Service\Media\MediaService::exists()) — the picker is a convenience,
 * never the validation. A crafted POST can put any integer in that field;
 * it can only ever hit an existing row or miss.
 *
 * WHAT IT IS NOT. Not a digital-asset manager. No folders, no tags, no bulk
 * actions, no cropping. It shows thumbnails, a name, an alt text and a search
 * box; it selects one item or uploads a new one. Everything else belongs on
 * admin/media.php, and most of it belongs nowhere yet (MEDIA.md).
 */

/**
 * One image field.
 *
 * @param string         $name      the form field name that carries the media id
 * @param MediaItem|null $selected  what is chosen now
 * @param string         $label     Dutch field label
 * @param string         $help      one line under the field, or ''
 * @param bool           $clearable whether "geen afbeelding" is a valid answer
 */
function media_picker_field(
    string $name,
    ?MediaItem $selected = null,
    string $label = 'Afbeelding',
    string $help = '',
    bool $clearable = true
): void {
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $fieldId = 'media-picker-' . preg_replace('/[^a-z0-9_-]/i', '-', $name) . '-' . bin2hex(random_bytes(4));
    ?>
    <div class="admin-media-picker" data-media-picker>
      <span class="admin-media-picker__label" id="<?= $h($fieldId) ?>-label"><?= $h($label) ?></span>

      <input type="hidden" name="<?= $h($name) ?>" value="<?= $selected !== null ? (int) $selected->id : '' ?>" data-media-picker-input>

      <div class="admin-media-picker__preview" data-media-picker-preview>
        <?php if ($selected !== null): ?>
          <?php if ($selected->fileExists()): ?>
            <img src="<?= $h($selected->displayPath()) ?>" alt="" loading="lazy">
          <?php else: ?>
            <span class="admin-media-picker__missing" title="Het bestand ontbreekt op de server">Bestand ontbreekt</span>
          <?php endif; ?>
          <span class="admin-media-picker__name"><?= $h($selected->displayName()) ?></span>
        <?php else: ?>
          <span class="admin-media-picker__empty">Nog geen afbeelding gekozen.</span>
        <?php endif; ?>
      </div>

      <div class="admin-media-picker__actions">
        <button type="button" class="admin-btn-text" data-media-picker-open aria-labelledby="<?= $h($fieldId) ?>-label">Kies uit mediabibliotheek</button>
        <?php if ($clearable): ?>
          <button type="button" class="admin-btn-text admin-btn-text--danger" data-media-picker-clear<?= $selected === null ? ' hidden' : '' ?>>Wissen</button>
        <?php endif; ?>
      </div>

      <?php if ($help !== ''): ?>
        <p class="admin-text-muted"><?= $h($help) ?></p>
      <?php endif; ?>
    </div>
    <?php
}

/**
 * The single modal every picker on the page shares, plus the CSRF token its
 * upload needs. Call it once, near the end of the document.
 *
 * One modal rather than one per field for the obvious reason (a screen can
 * carry a dozen fields) and for a less obvious one: the browse request and
 * its results are then never duplicated, so opening a second picker on the
 * same screen costs nothing.
 */
function media_picker_modal(): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="admin-media-modal" data-media-modal hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Mediabibliotheek">
      <div class="admin-media-modal__backdrop" data-media-modal-close></div>
      <div class="admin-media-modal__panel">
        <header class="admin-media-modal__head">
          <h2>Mediabibliotheek</h2>
          <button type="button" class="admin-media-modal__close" data-media-modal-close aria-label="Sluiten">&times;</button>
        </header>

        <div class="admin-media-modal__tools">
          <label class="admin-media-modal__search">
            <span class="admin-visually-hidden">Zoeken op bestandsnaam of alt-tekst</span>
            <input type="search" placeholder="Zoek op bestandsnaam of alt-tekst" data-media-modal-search autocomplete="off">
          </label>

          <label class="admin-media-modal__upload">
            <span>Nieuwe afbeelding</span>
            <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-media-modal-upload>
          </label>
        </div>

        <p class="admin-media-modal__status" data-media-modal-status role="status" aria-live="polite"></p>

        <div class="admin-media-modal__grid" data-media-modal-grid></div>

        <footer class="admin-media-modal__foot">
          <button type="button" class="admin-btn-text" data-media-modal-more hidden>Meer laden</button>
        </footer>
      </div>
    </div>

    <script type="application/json" data-media-picker-config>
      <?= json_encode([
          'listUrl' => '/api/admin/media-list.php',
          'uploadUrl' => '/api/admin/media-upload.php',
          'csrfToken' => Csrf::token(),
      ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    </script>
    <?php
}

/**
 * The <script> tag for the picker's behaviour. Separate from the markup so a
 * screen can place it with its other scripts, next to admin.js.
 */
function media_picker_script(): void
{
    echo '<script src="' . htmlspecialchars(AssetVersion::url('/admin/assets/media-picker.js'), ENT_QUOTES, 'UTF-8') . '" defer></script>';
}
