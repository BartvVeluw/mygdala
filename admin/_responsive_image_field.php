<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_media_picker.php';

use App\Service\Media\ImageFocus;
use App\Service\Media\MediaItem;
use App\Service\Media\ResponsiveImage;
use App\Service\Media\ResponsiveImageSlot;

/**
 * THE EDITOR OF A PICTURE'S PRESENTATION (Responsive Media 2.0,
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
 *   Weergave          cover or contain, where the picture has a frame of its own
 *   Op een telefoon   closed until it holds something: the phone's own
 *                     picture, its own point (always with a picture of its
 *                     own, else by the switch), its own fit and its own height
 *
 * WITHOUT THE SCRIPT every control is an ordinary form control, the frames
 * show what is stored, and the form posts the same fields; only the nine
 * one-click points and dragging need admin/assets/responsive-image.js
 * (responsive_image_field_script()).
 *
 * THE FORM'S NAMES ARE THE COLUMNS' (image_focus_x, background_mobile_media_id,
 * …) through $field['name'], plus <prefix>presentation, <prefix>mobile_source
 * and <prefix>mobile_focus_own: exactly what ResponsiveImage::fromRequest()
 * reads, for a single picture and for one row of a row list alike.
 *
 * THE FRAMES' SHAPES are the place's own: $field['frame'] gives the desktop
 * and the phone ratio as CSS aspect-ratio values; a choice elsewhere in the
 * same form or row that changes the shape carries data-rm-desktop-ratio or
 * data-rm-mobile-ratio on its input, and the script follows it.
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
 *     frame?: array{desktop?: string, mobile?: string},
 *     errors?: array<string, string>,
 *     legend?: string,
 *     help?: string,
 *     note?: string,
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
 *        (the Paginakop's data-page-hero-part)
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
    foreach (['desktop', 'mobile'] as $frame) {
        $ratio = (string) ($field['frame'][$frame] ?? '');
        if (preg_match('#^\d+(\.\d+)? / \d+(\.\d+)?$#', $ratio) === 1) {
            $ratios[] = '--admin-rm-' . $frame . '-ratio: ' . $ratio;
        }
    }
    $partAttributes = $field['part_attributes'] ?? [];
    $legend = $field['legend'] ?? admin_t('media.responsive.legend');
    $help = $field['help'] ?? admin_t('help.media.responsive');

    $ownSource = $value->mobileMediaId !== null;
    $ownFocus = $value->mobileFocusX !== null;
    $mobilePoint = $value->mobileFocus($ownSource) ?? [$value->focusX, $value->focusY];
    $mobileHasSettings = $ownSource || $ownFocus || $value->mobileFit !== null || $value->mobileHeight !== null;
    $mobileHasErrors = array_intersect_key($errors, array_flip(['mobile_media', 'mobile_focus', 'mobile_fit', 'mobile_height'])) !== [];

    $error = static function (string $part) use ($errors, $h, $id): void {
        if (isset($errors[$part])) {
            echo '<p class="admin-field-error" id="' . $h($id . '-' . $part . '-error') . '">' . $h((string) $errors[$part]) . '</p>';
        }
    };
    $invalid = static fn (string $part): string => isset($errors[$part]) ? ' aria-invalid="true" aria-describedby="' . $h($id . '-' . $part . '-error') . '"' : '';
    ?>
    <fieldset class="admin-rm" data-rm data-rm-picker="<?= $h((string) ($field['picker'] ?? '')) ?>"<?= $ratios !== [] ? ' style="' . $h(implode('; ', $ratios) . ';') . '"' : '' ?>
              data-rm-value-template="<?= admin_te('media.responsive.value') ?>">
      <legend><?= $h($legend) ?> <?= admin_help($legend, $help) ?></legend>
      <input type="hidden" name="<?= $h($name($slot->column('presentation'))) ?>" value="1">

      <?php responsive_image_focus_editor([
          'id' => $id . '-desktop',
          'part' => 'desktop',
          'label' => admin_t('media.responsive.focus'),
          'point' => [$value->focusX, $value->focusY],
          'names' => [$name($slot->column('focus_x')), $name($slot->column('focus_y'))],
          'preview' => $preview,
          'fit' => $value->fit,
          'invalid' => $invalid('focus'),
      ]); ?>
      <?php $error('focus'); ?>

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
            <?php media_picker_field($name($slot->column('mobile_media_id')), $mobileMedia, admin_t('media.responsive.mobile_picker'), admin_t('media.responsive.mobile_picker_help'), false); ?>
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
                'names' => [$name($slot->column('mobile_focus_x')), $name($slot->column('mobile_focus_y'))],
                'preview' => $mobilePreview,
                'fit' => $value->effectiveMobileFit(),
                'invalid' => $invalid('mobile_focus'),
            ]); ?>
            <?php $error('mobile_focus'); ?>
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
 * One crop frame with its nine points and its two sliders: the desktop focus
 * or the phone's. The sliders are the value; the frame and the points set them.
 *
 * @param array{id: string, part: string, label: string, point: array{0: int, 1: int}, names: array{0: string, 1: string},
 *              preview: string, fit: string, invalid: string} $editor
 */
function responsive_image_focus_editor(array $editor): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    [$x, $y] = $editor['point'];
    $position = ResponsiveImage::objectPosition($x, $y);
    $contained = $editor['fit'] === ResponsiveImage::FIT_CONTAIN;
    ?>
      <div class="admin-rm__focus<?= $contained ? ' is-contained' : '' ?>" data-rm-focus="<?= $h($editor['part']) ?>">
        <p class="admin-rm__label" id="<?= $h($editor['id']) ?>-label"><?= $h($editor['label']) ?></p>
        <div class="admin-rm__editor">
          <div class="admin-rm__frame" data-rm-frame aria-hidden="true"<?= $editor['preview'] === '' ? ' hidden' : '' ?>>
            <img src="<?= $h($editor['preview']) ?>" alt="" draggable="false" data-rm-preview style="object-position: <?= $h($position) ?>;<?= $contained ? ' object-fit: contain;' : '' ?>">
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
            </div>
            <p class="admin-text-muted admin-rm__value" data-rm-value aria-live="polite"><?= $h(str_replace([':x', ':y'], [(string) $x, (string) $y], admin_t('media.responsive.value'))) ?></p>
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
