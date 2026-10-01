<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_media_picker.php';

use App\Service\Media\ImageFocus;
use App\Service\Media\ImagePresentation;
use App\Service\Media\MediaItem;
use App\Service\Media\ResponsiveImage;
use App\Service\Media\ResponsiveImageSlot;

/**
 * THE EDITOR OF A PICTURE'S PRESENTATION (Responsive Media 3.0,
 * App\Service\Media\ResponsiveImage, ADMIN-UI.md "Afbeeldingsweergave"): one
 * field for every block that crops a picture, so they cannot grow apart.
 *
 *   Focuspunt         a frame in the place's own shape with the picture in
 *                     it; drag the picture to choose what stays in view, or
 *                     click one of the nine points (App\Service\Media\ImageFocus).
 *                     Two sliders, horizontal and vertical, hold the value:
 *                     they are what the form posts, and the keyboard's and a
 *                     screen reader's way to set it. The frame is for a mouse,
 *                     a finger or a pen, and repeats what the sliders say.
 *                     A third slider, Zoom (100-200%), enlarges the picture
 *                     around that point, and "Afbeelding resetten" puts the
 *                     point back in the middle and the zoom at 100%. With
 *                     "Hele afbeelding" the zoom row is hidden (it does
 *                     nothing there) but still posted, so nothing is lost.
 *   Weergave          cover or contain, where the picture has a frame of its own
 *   Op een telefoon   closed until it holds something: the phone's own
 *                     picture, its own point (always with a picture of its
 *                     own, else by the switch), its own fit and its own height
 *
 * WITHOUT THE SCRIPT every control is an ordinary form control, the frames
 * show what is stored, and the form posts the same fields; only the nine
 * one-click points, the reset button and dragging need
 * admin/assets/responsive-image.js (responsive_image_field_script()).
 *
 * LIGHT ON A LONG SCREEN. A preview is the Media Library's thumbnail (the
 * block passes MediaItem::displayPath(), a linked item LinkedImages'
 * preview_path), loaded lazily: a frame far below the fold, in a folded row
 * or in the closed phone part downloads nothing until it is about to be
 * seen. The script wakes a field only when it comes near the screen
 * (responsive-image.js), and all fields share one set of delegated
 * listeners.
 *
 * THE FORM'S NAMES ARE THE COLUMNS' (image_focus_x, image_zoom, background_mobile_media_id,
 * …) through $field['name'], plus <prefix>presentation, <prefix>mobile_source
 * and <prefix>mobile_focus_own: exactly what ResponsiveImage::fromRequest()
 * reads, for a single picture and for one row of a row list alike.
 *
 * THE FRAMES' SHAPES are the place's own: $field['frame'] gives the desktop,
 * the tablet and the phone ratio as CSS aspect-ratio values; a choice
 * elsewhere in the same form or row that changes the shape carries
 * data-rm-desktop-ratio or data-rm-mobile-ratio on its input, and the script
 * follows it. A block whose picture has size steps (Compact, Normaal, Groot:
 * App\Service\Media\ImagePresentation) passes $field['shapes'] instead: every
 * frame its choices can give, worked out by the block's own Content class
 * from the lengths its stylesheet uses, keyed by the values of the controls
 * that decide it — so the preview never keeps a size table of its own.
 *
 * DESKTOP, TABLET, MOBIEL (Responsive Media 3.1). One switch above the focus
 * frame shows the picture as it stands on each reference screen
 * (ImagePresentation::VIEWPORTS): the same frame and picture, only its shape,
 * its size and — on a phone with settings of its own — the phone's picture,
 * point, zoom and fit change. No reload, no second copy of the picture. A
 * tablet follows the large screen's settings, as the page does; the Tablet
 * button is only there when the block knows its tablet shape.
 *
 * NOT HERE: choosing the desktop picture and its alt text (the block's own
 * picker and alt field, which stay where they are), and any block's size
 * choices (those are the block's).
 *
 * @param array{
 *     slot: ResponsiveImageSlot,
 *     value: ResponsiveImage,
 *     id: string,
 *     preview: string,
 *     name?: callable(string): string,
 *     picker?: string,
 *     mobile?: bool,
 *     mobile_media?: MediaItem|null,
 *     frame?: array{desktop?: string, tablet?: string, mobile?: string},
 *     shapes?: array{controls: list<string>, shapes: array<string, array<string, string>>, current: string},
 *     views?: list<string>,
 *     errors?: array<string, string>,
 *     legend?: string,
 *     help?: string,
 *     note?: string,
 *     mobile_picker_help?: string,
 *     part_attributes?: array<string, string>,
 * } $field
 *        id: a DOM id prefix, unique on the screen; preview: the desktop
 *        picture's preview (MediaItem::displayPath()), '' for none yet;
 *        name: the form name of one column (default: the column itself);
 *        picker: the form name of the block's own picture picker, whose
 *        choice the frames follow; mobile: offer the phone part (default
 *        true); errors: fromRequest()'s messages, by part; note: one line
 *        of the block's own under the phone part; part_attributes: extra
 *        attributes for the fit, mobile_fit and mobile_height choices, by
 *        name, for a block that shows one only in some of its layouts
 *        (the Paginakop's data-page-hero-part); shapes: the block's frames
 *        by its controls (a CSS selector each, within the row or form; the
 *        key is their values joined with "|", '' for none checked) and the
 *        key of what is stored; views: the preview views when the block sets
 *        their shapes in its own stylesheet rule (default: desktop, mobile,
 *        and tablet when frame or shapes give one)
 */
function responsive_image_field(array $field): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    /** @var ResponsiveImageSlot $slot */
    $slot = $field['slot'];
    /** @var ResponsiveImage $value */
    $value = $field['value'];
    $name = $field['name'] ?? static fn (string $column): string => $column;
    $id = $field['id'];
    $errors = $field['errors'] ?? [];
    $preview = (string) ($field['preview'] ?? '');
    $withMobile = $field['mobile'] ?? true;
    $mobileMedia = $field['mobile_media'] ?? null;
    $mobilePreview = $mobileMedia !== null && !$mobileMedia->isVideo() ? $mobileMedia->displayPath() : $preview;
    $ratios = [];
    foreach (ImagePresentation::VIEWS as $frame) {
        $ratio = (string) ($field['frame'][$frame] ?? '');
        if (preg_match('#^\d+(\.\d+)? / \d+(\.\d+)?$#', $ratio) === 1) {
            $ratios[] = '--admin-rm-' . $frame . '-ratio: ' . $ratio;
        }
    }
    // The block's size steps: the frames of what is stored now, and every
    // other one for the script.
    $shapes = $field['shapes'] ?? null;
    $shapeStyle = '';
    if (is_array($shapes)) {
        $shapeStyle = ImagePresentation::style($shapes['shapes'][$shapes['current']] ?? []);
    }
    $style = ($ratios !== [] ? implode('; ', $ratios) . ';' : '') . ($shapeStyle !== '' ? ($ratios !== [] ? ' ' : '') . $shapeStyle : '');
    $views = $field['views'] ?? null;
    if (!is_array($views)) {
        $hasTablet = isset($field['frame']['tablet'])
            || (is_array($shapes) && isset(($shapes['shapes'][$shapes['current']] ?? [])['--admin-rm-tablet-ratio']));
        $views = $hasTablet ? ImagePresentation::VIEWS : [ImagePresentation::DESKTOP, ImagePresentation::MOBILE];
    }
    $views = array_values(array_intersect(ImagePresentation::VIEWS, $views));
    $partAttributes = $field['part_attributes'] ?? [];
    $legend = $field['legend'] ?? admin_t('media.responsive.legend');
    $help = $field['help'] ?? admin_t('help.media.responsive');

    $ownSource = $value->mobileMediaId !== null;
    $ownFocus = $value->mobileFocusX !== null;
    $mobilePoint = $value->mobileFocus($ownSource) ?? [$value->focusX, $value->focusY];
    // The phone frame starts from what a phone shows now: its own zoom, the
    // middle's 100 for a picture of its own never given a point, else the
    // desktop zoom it follows.
    $mobileZoom = $ownFocus ? $value->ownMobileZoom() : ($ownSource ? ResponsiveImage::DEFAULT_ZOOM : $value->zoom);
    $mobileHasSettings = $ownSource || $ownFocus || $value->mobileFit !== null || $value->mobileHeight !== null;
    $mobileHasErrors = array_intersect_key($errors, array_flip(['mobile_media', 'mobile_focus', 'mobile_zoom', 'mobile_fit', 'mobile_height'])) !== [];

    $error = static function (string $part) use ($errors, $h, $id): void {
        if (isset($errors[$part])) {
            echo '<p class="admin-field-error" id="' . $h($id . '-' . $part . '-error') . '">' . $h((string) $errors[$part]) . '</p>';
        }
    };
    $invalid = static fn (string $part): string => isset($errors[$part]) ? ' aria-invalid="true" aria-describedby="' . $h($id . '-' . $part . '-error') . '"' : '';
    ?>
    <fieldset class="admin-rm" data-rm data-rm-picker="<?= $h((string) ($field['picker'] ?? '')) ?>"<?= $style !== '' ? ' style="' . $h($style) . '"' : '' ?>
              data-rm-view="desktop"
              <?php if (is_array($shapes)): ?>data-rm-shapes="<?= $h((string) json_encode($shapes['shapes'], JSON_UNESCAPED_SLASHES)) ?>" data-rm-shape-controls="<?= $h((string) json_encode($shapes['controls'], JSON_UNESCAPED_SLASHES)) ?>"<?php endif; ?>
              data-rm-value-template="<?= admin_te('media.responsive.value') ?>" data-rm-zoom-template="<?= admin_te('media.responsive.zoom_value') ?>">
      <legend><?= $h($legend) ?> <?= admin_help($legend, $help) ?></legend>
      <input type="hidden" name="<?= $h($name($slot->column('presentation'))) ?>" value="1">

      <?php /* The preview's screen: only with the script, which draws it. */ ?>
      <div class="admin-rm__views" data-rm-views hidden>
        <span class="admin-rm__views-label" id="<?= $h($id) ?>-views-label"><?= admin_te('media.responsive.view') ?></span>
        <div class="admin-segmented" role="group" aria-labelledby="<?= $h($id) ?>-views-label">
          <?php foreach ($views as $view): ?>
            <button type="button" class="admin-segmented__option admin-rm__view" data-rm-view-button="<?= $h($view) ?>" aria-pressed="<?= $view === ImagePresentation::DESKTOP ? 'true' : 'false' ?>"><?= admin_te('media.responsive.view_' . $view) ?></button>
          <?php endforeach; ?>
        </div>
        <p class="admin-text-muted admin-rm__view-note" data-rm-view-note aria-live="polite"
           data-desktop="<?= admin_te('media.responsive.view_note_desktop') ?>"
           data-tablet="<?= admin_te('media.responsive.view_note_tablet') ?>"
           data-mobile="<?= admin_te('media.responsive.view_note_mobile') ?>"
           data-mobile-own="<?= admin_te('media.responsive.view_note_mobile_own') ?>"><?= admin_te('media.responsive.view_note_desktop') ?></p>
      </div>

      <?php responsive_image_focus_editor([
          'id' => $id . '-desktop',
          'part' => 'desktop',
          'label' => admin_t('media.responsive.focus'),
          'point' => [$value->focusX, $value->focusY],
          'zoom' => $value->zoom,
          'names' => [$name($slot->column('focus_x')), $name($slot->column('focus_y')), $name($slot->column('zoom'))],
          'preview' => $preview,
          'fit' => $value->fit,
          'invalid' => $invalid('focus'),
          'invalid_zoom' => $invalid('zoom'),
      ]); ?>
      <?php $error('focus'); ?>
      <?php $error('zoom'); ?>

      <?php if ($slot->fit): ?>
        <?php responsive_image_choice(
            $id . '-fit',
            $name($slot->column('fit')),
            admin_t('media.responsive.fit'),
            admin_t('help.media.responsive.fit'),
            [ResponsiveImage::FIT_COVER => admin_t('media.responsive.fit_cover'), ResponsiveImage::FIT_CONTAIN => admin_t('media.responsive.fit_contain')],
            $value->fit,
            ' data-rm-fit="desktop"',
            $invalid('fit') . ($partAttributes['fit'] ?? '')
        ); ?>
        <?php $error('fit'); ?>
      <?php endif; ?>

      <?php if ($withMobile): ?>
      <details class="admin-rm__mobile" data-rm-mobile<?= $mobileHasSettings || $mobileHasErrors ? ' open' : '' ?>>
        <summary>
          <span class="admin-rm__mobile-title"><?= admin_te('media.responsive.mobile') ?></span>
          <span class="admin-rm__mobile-state admin-text-muted" data-rm-mobile-state
                data-same="<?= admin_te('media.responsive.mobile_state_same') ?>"
                data-own="<?= admin_te('media.responsive.mobile_state_own') ?>"><?= admin_te($mobileHasSettings ? 'media.responsive.mobile_state_own' : 'media.responsive.mobile_state_same') ?></span>
        </summary>
        <div class="admin-rm__mobile-body">
          <?php responsive_image_choice(
              $id . '-source',
              $name($slot->column('mobile_source')),
              admin_t('media.responsive.mobile_source'),
              admin_t('help.media.responsive.mobile_source'),
              [ResponsiveImage::SOURCE_DESKTOP => admin_t('media.responsive.mobile_source_desktop'), ResponsiveImage::SOURCE_OWN => admin_t('media.responsive.mobile_source_own')],
              $ownSource ? ResponsiveImage::SOURCE_OWN : ResponsiveImage::SOURCE_DESKTOP,
              ' data-rm-source',
              $invalid('mobile_media')
          ); ?>

          <div class="admin-rm__mobile-picker" data-rm-when="own"<?= $ownSource ? '' : ' hidden' ?>>
            <?php media_picker_field($name($slot->column('mobile_media_id')), $mobileMedia, admin_t('media.responsive.mobile_picker'), (string) ($field['mobile_picker_help'] ?? admin_t('media.responsive.mobile_picker_help')), false); ?>
          </div>
          <?php $error('mobile_media'); ?>

          <div class="admin-field admin-field--inline" data-rm-when="desktop"<?= $ownSource ? ' hidden' : '' ?>>
            <label class="admin-checkbox-label">
              <input type="checkbox" class="admin-switch" role="switch" name="<?= $h($name($slot->column('mobile_focus_own'))) ?>" value="1" data-rm-own-focus<?= $ownFocus ? ' checked' : '' ?>>
              <?= admin_te('media.responsive.mobile_focus_own') ?>
            </label>
            <?= admin_help(admin_t('media.responsive.mobile_focus_own'), admin_t('help.media.responsive.mobile_focus_own')) ?>
          </div>

          <div data-rm-when="mobile-focus"<?= $ownSource || $ownFocus ? '' : ' hidden' ?>>
            <?php responsive_image_focus_editor([
                'id' => $id . '-mobile',
                'part' => 'mobile',
                'label' => admin_t('media.responsive.mobile_focus'),
                'point' => $mobilePoint,
                'zoom' => $mobileZoom,
                'names' => [$name($slot->column('mobile_focus_x')), $name($slot->column('mobile_focus_y')), $name($slot->column('mobile_zoom'))],
                'preview' => $mobilePreview,
                'fit' => $value->effectiveMobileFit(),
                'invalid' => $invalid('mobile_focus'),
                'invalid_zoom' => $invalid('mobile_zoom'),
            ]); ?>
            <?php $error('mobile_focus'); ?>
            <?php $error('mobile_zoom'); ?>
          </div>

          <?php if ($slot->fit): ?>
            <?php responsive_image_choice(
                $id . '-mobile-fit',
                $name($slot->column('mobile_fit')),
                admin_t('media.responsive.mobile_fit'),
                admin_t('help.media.responsive.mobile_fit'),
                ['' => admin_t('media.responsive.mobile_fit_same'), ResponsiveImage::FIT_COVER => admin_t('media.responsive.fit_cover'), ResponsiveImage::FIT_CONTAIN => admin_t('media.responsive.fit_contain')],
                $value->mobileFit ?? '',
                ' data-rm-fit="mobile"',
                $invalid('mobile_fit') . ($partAttributes['mobile_fit'] ?? '')
            ); ?>
            <?php $error('mobile_fit'); ?>
          <?php endif; ?>

          <?php if ($slot->mobileHeight): ?>
            <?php
              $heights = ['' => admin_t('media.responsive.mobile_height_auto')];
              foreach (ResponsiveImage::MOBILE_HEIGHTS as $height) {
                  $heights[$height] = admin_t('media.responsive.mobile_height_' . $height);
              }
              responsive_image_choice(
                  $id . '-mobile-height',
                  $name($slot->column('mobile_height')),
                  admin_t('media.responsive.mobile_height'),
                  admin_t('help.media.responsive.mobile_height'),
                  $heights,
                  $value->mobileHeight ?? '',
                  ' data-rm-mobile-height',
                  $invalid('mobile_height') . ($partAttributes['mobile_height'] ?? '')
              );
            ?>
            <?php $error('mobile_height'); ?>
          <?php endif; ?>

          <?php if (($field['note'] ?? '') !== ''): ?>
            <p class="admin-text-muted"><?= $h((string) $field['note']) ?></p>
          <?php endif; ?>
        </div>
      </details>
      <?php endif; ?>
    </fieldset>
    <?php
}

/**
 * One crop frame with its nine points, its two point sliders and its zoom
 * slider: the desktop focus or the phone's. The sliders are the value; the
 * frame, the points and the reset button set them.
 *
 * The preview is lazy and async: it costs nothing until its frame is about
 * to be seen, and a hidden frame (the phone's, while it follows the desktop)
 * never downloads at all.
 *
 * @param array{id: string, part: string, label: string, point: array{0: int, 1: int}, zoom: int,
 *              names: array{0: string, 1: string, 2: string}, preview: string, fit: string,
 *              invalid: string, invalid_zoom: string} $editor
 */
function responsive_image_focus_editor(array $editor): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    [$x, $y] = $editor['point'];
    $zoom = max(ResponsiveImage::ZOOM_MIN, min(ResponsiveImage::ZOOM_MAX, (int) $editor['zoom']));
    $position = ResponsiveImage::objectPosition($x, $y);
    $contained = $editor['fit'] === ResponsiveImage::FIT_CONTAIN;
    $previewStyle = 'object-position: ' . $position . ';' . ($contained ? ' object-fit: contain;' : '');
    if (!$contained && $zoom !== ResponsiveImage::DEFAULT_ZOOM) {
        $previewStyle .= ' scale: ' . ResponsiveImage::scale($zoom) . '; transform-origin: ' . $position . ';';
    }
    ?>
      <div class="admin-rm__focus<?= $contained ? ' is-contained' : '' ?>" data-rm-focus="<?= $h($editor['part']) ?>">
        <p class="admin-rm__label" id="<?= $h($editor['id']) ?>-label"><?= $h($editor['label']) ?></p>
        <div class="admin-rm__editor">
          <div class="admin-rm__frame" data-rm-frame aria-hidden="true"<?= $editor['preview'] === '' ? ' hidden' : '' ?>>
            <img src="<?= $h($editor['preview']) ?>" alt="" draggable="false" loading="lazy" decoding="async" data-rm-preview style="<?= $h($previewStyle) ?>">
          </div>
          <div class="admin-rm__controls">
            <div class="admin-rm__presets" role="group" aria-labelledby="<?= $h($editor['id']) ?>-label" data-rm-presets hidden>
              <?php foreach (ImageFocus::keys() as $key):
                  [$presetX, $presetY] = ImageFocus::point($key);
                  ?>
                <button type="button" class="admin-rm__preset" data-rm-preset data-x="<?= $presetX ?>" data-y="<?= $presetY ?>" aria-pressed="<?= $presetX === $x && $presetY === $y ? 'true' : 'false' ?>" title="<?= admin_te('media.focus.' . $key) ?>"><span class="admin-visually-hidden"><?= admin_te('media.focus.' . $key) ?></span></button>
              <?php endforeach; ?>
            </div>
            <div class="admin-rm__axes">
              <label class="admin-rm__axis" for="<?= $h($editor['id']) ?>-x">
                <span><?= admin_te('media.responsive.axis_x') ?></span>
                <input type="range" id="<?= $h($editor['id']) ?>-x" name="<?= $h($editor['names'][0]) ?>" min="0" max="100" step="1" value="<?= $x ?>" aria-valuetext="<?= $x ?>%" data-rm-axis="x"<?= $editor['invalid'] ?>>
              </label>
              <label class="admin-rm__axis" for="<?= $h($editor['id']) ?>-y">
                <span><?= admin_te('media.responsive.axis_y') ?></span>
                <input type="range" id="<?= $h($editor['id']) ?>-y" name="<?= $h($editor['names'][1]) ?>" min="0" max="100" step="1" value="<?= $y ?>" aria-valuetext="<?= $y ?>%" data-rm-axis="y"<?= $editor['invalid'] ?>>
              </label>
              <label class="admin-rm__axis admin-rm__zoom" for="<?= $h($editor['id']) ?>-zoom">
                <span><?= admin_te('media.responsive.zoom') ?></span>
                <input type="range" id="<?= $h($editor['id']) ?>-zoom" name="<?= $h($editor['names'][2]) ?>" min="<?= ResponsiveImage::ZOOM_MIN ?>" max="<?= ResponsiveImage::ZOOM_MAX ?>" step="1" value="<?= $zoom ?>" aria-valuetext="<?= $zoom ?>%" data-rm-axis="zoom"<?= $editor['invalid_zoom'] ?>>
                <output class="admin-rm__zoom-value" for="<?= $h($editor['id']) ?>-zoom" data-rm-zoom-value><?= $h(str_replace(':zoom', (string) $zoom, admin_t('media.responsive.zoom_value'))) ?></output>
              </label>
            </div>
            <p class="admin-text-muted admin-rm__value" data-rm-value aria-live="polite"><?= $h(str_replace([':x', ':y'], [(string) $x, (string) $y], admin_t('media.responsive.value'))) ?></p>
            <p class="admin-rm__reset" data-rm-reset-row hidden><button type="button" class="admin-btn-text" data-rm-reset><?= admin_te('media.responsive.reset') ?></button></p>
            <p class="admin-text-muted admin-rm__hint"><?= admin_te('media.responsive.focus_hint') ?></p>
            <p class="admin-text-muted admin-rm__contained"><?= admin_te('media.responsive.contain_note') ?></p>
          </div>
        </div>
      </div>
    <?php
}

/**
 * A closed-list choice as a segmented control in a fieldset with a legend
 * (the markup of admin/media-banner.php's choices).
 *
 * @param array<string, string> $options value => words
 */
function responsive_image_choice(string $id, string $name, string $legend, string $help, array $options, string $chosen, string $inputAttributes, string $fieldsetAttributes): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
      <fieldset class="admin-segmented-field" id="<?= $h($id) ?>"<?= $fieldsetAttributes ?>>
        <legend><?= $h($legend) ?> <?= admin_help($legend, $help) ?></legend>
        <div class="admin-segmented">
          <?php foreach ($options as $optionValue => $words): ?>
            <label class="admin-segmented__option">
              <input type="radio" name="<?= $h($name) ?>" value="<?= $h((string) $optionValue) ?>"<?= $chosen === (string) $optionValue ? ' checked' : '' ?><?= $inputAttributes ?>>
              <span><?= $h($words) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
    <?php
}

/** The script of every field on the screen; print it once, near the end of <body>. */
function responsive_image_field_script(): void
{
    ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/responsive-image.js') ?>" defer></script>
    <?php
}
