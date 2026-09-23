<?php

declare(strict_types=1);


require_once __DIR__ . '/_translate.php';
use App\Service\AssetVersion;
use App\Service\Csrf;
use App\Service\Media\MediaItem;
use App\Service\Media\MediaType;
use App\Service\Media\MediaUploader;

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
 * ONE KIND PER FIELD. A field takes images (the default) or video
 * (MediaType), and the modal then lists and uploads only that kind: an image
 * field never offers an MP4, and a video field is not buried in photos. The
 * endpoint behind the field checks the kind again (MediaService::findImage(),
 * findVideo(), BlockImage::fromRequest()).
 *
 * THE ALT TEXT OF THIS USE. A field that has its own alt text next to it links
 * that input to the picker (media_alt_field()). The input then shows the alt
 * text that is really used — the block's own, or else the library's — and
 * choosing another image fills in that image's library alt text on the spot.
 * What is stored stays "inherited" as long as the text is the library's
 * (BlockImage::ownAlt()), so a later change in the library still reaches it.
 *
 * WHAT IT IS NOT. Not a digital-asset manager. No folders, no tags, no bulk
 * actions, no cropping. It shows thumbnails, a name, an alt text and a search
 * box; it selects one item or uploads a new one. Everything else belongs on
 * admin/media.php, and most of it belongs nowhere yet (MEDIA.md).
 */

/**
 * One image (or video) field.
 *
 * @param string         $name      the form field name that carries the media id
 * @param MediaItem|null $selected  what is chosen now
 * @param string         $label     Dutch field label
 * @param string         $help      one line under the field, or ''
 * @param bool           $clearable whether "geen afbeelding" is a valid answer
 * @param string         $kind      MediaType::IMAGE, ::VIDEO, or a filter: ::SOCIAL_IMAGE (a share
 *                                  image: raster formats only, no SVG) or ::ICON (an SVG from
 *                                  the library's Iconen): what the field takes
 */
function media_picker_field(
    string $name,
    ?MediaItem $selected = null,
    string $label = '',
    string $help = '',
    bool $clearable = true,
    string $kind = MediaType::IMAGE
): void {
    $kind = in_array($kind, [MediaType::VIDEO, MediaType::SOCIAL_IMAGE, MediaType::ICON], true) ? $kind : MediaType::IMAGE;
    // Resolved here rather than in the signature: a PHP default value
    // cannot call a function, and this one has to be read per request.
    $label = $label !== '' ? $label : admin_t(match ($kind) {
        MediaType::VIDEO => 'media.picker.video_label',
        MediaType::ICON => 'media.picker.icon_label',
        default => 'common.image_label',
    });
    $empty = admin_t(match ($kind) {
        MediaType::VIDEO => 'media.picker.no_video',
        MediaType::ICON => 'media.picker.no_icon',
        default => 'media.no_image_chosen',
    });
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $fieldId = 'media-picker-' . preg_replace('/[^a-z0-9_-]/i', '-', $name) . '-' . bin2hex(random_bytes(4));
    ?>
    <div class="admin-media-picker" data-media-picker data-media-picker-kind="<?= $h($kind) ?>" data-media-picker-empty="<?= $h($empty) ?>">
      <span class="admin-media-picker__label" id="<?= $h($fieldId) ?>-label"><?= $h($label) ?></span>

      <input type="hidden" name="<?= $h($name) ?>" value="<?= $selected !== null ? (int) $selected->id : '' ?>" data-media-picker-input>

      <div class="admin-media-picker__preview" data-media-picker-preview>
        <?php if ($selected !== null): ?>
          <?php if (!$selected->fileExists()): ?>
            <span class="admin-media-picker__missing" title="<?= admin_te('media.picker.missing_title') ?>"><?= admin_te('common.file_missing') ?></span>
          <?php elseif ($selected->isVideo()): ?>
            <?= media_video_icon() ?>
          <?php else: ?>
            <img src="<?= $h($selected->displayPath()) ?>" alt="" loading="lazy">
          <?php endif; ?>
          <span class="admin-media-picker__name"><?= $h($selected->displayName()) ?></span>
        <?php else: ?>
          <span class="admin-media-picker__empty"><?= $h($empty) ?></span>
        <?php endif; ?>
      </div>

      <div class="admin-media-picker__actions">
        <button type="button" class="admin-btn-text" data-media-picker-open aria-labelledby="<?= $h($fieldId) ?>-label"><?= admin_te('media.kies_uit_mediabibliotheek') ?></button>
        <?php if ($clearable): ?>
          <button type="button" class="admin-btn-text admin-btn-text--danger" data-media-picker-clear<?= $selected === null ? ' hidden' : '' ?>><?= admin_te('media.wissen') ?></button>
        <?php endif; ?>
      </div>

      <?php if ($help !== ''): ?>
        <p class="admin-text-muted"><?= $h($help) ?></p>
      <?php endif; ?>
    </div>
    <?php
}

/**
 * The value and attributes of the alt-text input that belongs to one image
 * field: the alt text this use really gets, visible in the field.
 *
 *   - its own alt text when it has one;
 *   - else the library's alt text of the chosen image, filled in, so an
 *     editor reads exactly what a visitor's screen reader will hear;
 *   - else empty, with a placeholder that says the image has no alt text yet.
 *
 * The input is linked to its picker (data-media-alt-for), and media-picker.js
 * refills it when another image is chosen. The endpoint stores a text that is
 * only the library's as empty again (BlockImage::ownAlt()), so filling it in
 * here never turns into a copy that stops following the library.
 *
 * In a translation the field keeps its own rule: empty falls back to the
 * default language, and $translationPlaceholder (admin_localized_placeholder_attr())
 * says so. Nothing is filled in and nothing is linked there.
 *
 * @return array{value: string, attributes: string} the value, and the attributes to print after it
 */
function media_alt_field(string $pickerName, string $own, ?MediaItem $media, string $translationPlaceholder = ''): array
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    if ($translationPlaceholder !== '') {
        return ['value' => $own, 'attributes' => $translationPlaceholder];
    }

    $library = $media !== null ? trim($media->altText) : '';
    $value = trim($own) !== '' ? $own : $library;
    $placeholder = $media !== null && $library === '' ? admin_t('media.alt.none_yet') : '';

    return [
        'value' => $value,
        'attributes' => ($placeholder !== '' ? ' placeholder="' . $h($placeholder) . '"' : '')
            . ' data-media-alt-for="' . $h($pickerName) . '"',
    ];
}

/**
 * The picture a video gets wherever the admin shows media: a video has no
 * thumbnail, because nothing on a shared host can cut a frame out of it.
 * Decorative; the name and the type next to it say what it is.
 */
function media_video_icon(): string
{
    return '<span class="admin-media-video-icon" aria-hidden="true">'
        . '<svg viewBox="0 0 24 24" width="32" height="32" focusable="false"><rect x="2.5" y="5" width="19" height="14" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M10 9.2v5.6l4.8-2.8z" fill="currentColor"/></svg>'
        . '</span>';
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
          <h2><?= admin_te('media.mediabibliotheek') ?></h2>
          <button type="button" class="admin-media-modal__close" data-media-modal-close aria-label="Sluiten">&times;</button>
        </header>

        <div class="admin-media-modal__tools">
          <label class="admin-media-modal__search">
            <span class="admin-visually-hidden"><?= admin_te('media.zoeken_bestandsnaam_alt_tekst') ?></span>
            <input type="search" placeholder="<?= admin_te('media.zoeken_bestandsnaam_alt_tekst') ?>" data-media-modal-search autocomplete="off">
          </label>

          <?php /* The accept list is the image one; media-picker.js swaps it
                   for the video one when a video field opens the modal. */ ?>
          <label class="admin-media-modal__upload">
            <span data-media-modal-upload-label><?= admin_te('media.nieuwe_afbeelding') ?></span>
            <input type="file" accept="<?= $h(MediaUploader::acceptAttribute(MediaType::IMAGE)) ?>" data-media-modal-upload>
          </label>
        </div>

        <p class="admin-media-modal__status" data-media-modal-status role="status" aria-live="polite"></p>

        <div class="admin-media-modal__grid" data-media-modal-grid></div>

        <footer class="admin-media-modal__foot">
          <button type="button" class="admin-btn-text" data-media-modal-more hidden><?= admin_te('media.meer_laden') ?></button>
        </footer>
      </div>
    </div>

    <script type="application/json" data-media-picker-config>
      <?= json_encode([
          'listUrl' => '/api/admin/media-list.php',
          'uploadUrl' => '/api/admin/media-upload.php',
          'csrfToken' => Csrf::token(),
          // Per kind: what the upload offers, and the words that change with it.
          'kinds' => [
              MediaType::IMAGE => [
                  'accept' => MediaUploader::acceptAttribute(MediaType::IMAGE),
                  'upload' => admin_t('media.nieuwe_afbeelding'),
                  'empty' => admin_t('media.picker.empty_image'),
              ],
              MediaType::VIDEO => [
                  'accept' => MediaUploader::acceptAttribute(MediaType::VIDEO),
                  'upload' => admin_t('media.picker.new_video'),
                  'empty' => admin_t('media.picker.empty_video'),
              ],
              MediaType::SOCIAL_IMAGE => [
                  'accept' => MediaUploader::acceptAttribute(MediaType::SOCIAL_IMAGE),
                  'upload' => admin_t('media.nieuwe_afbeelding'),
                  'empty' => admin_t('media.picker.empty_social'),
              ],
              MediaType::ICON => [
                  'accept' => MediaUploader::acceptAttribute(MediaType::ICON),
                  'upload' => admin_t('media.picker.new_icon'),
                  'empty' => admin_t('media.picker.empty_icon'),
              ],
          ],
          'messages' => [
              'noAlt' => admin_t('media.alt.none_yet'),
              'missing' => admin_t('common.file_missing'),
              'reused' => admin_t('media.picker.reused'),
          ],
      ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
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
