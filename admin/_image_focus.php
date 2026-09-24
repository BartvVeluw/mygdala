<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

/**
 * The focus point of a cropped picture (App\Service\Media\ImageFocus): nine
 * points on a 3×3 grid as radio buttons, and a preview frame beside them with
 * the same object-fit: cover and the same object-position the website uses.
 * admin/assets/image-focus.js moves the preview with the chosen point and
 * puts a newly chosen picture in it; without the script the points are
 * ordinary radio buttons and the form posts the same value.
 *
 * The frame's shape is the place's own: a screen or a row sets
 * --admin-focus-frame-ratio (admin.css), so the preview crops the way the
 * website does. One helper for every place with a focus point: the carousel
 * card and the Tekst met afbeelding items. Its own file rather than a part of
 * admin/_media_picker.php, because it prints a help button, and the picker is
 * also used by screens without the shell that drives one (admin/setup.php).
 *
 * @param string $name       the radio buttons' form name
 * @param string $value      the chosen key (normalised by the caller)
 * @param string $previewSrc the picture to show in the frame, '' for none yet
 * @param string $legend     the group's name, Dutch
 * @param string $help       the help text behind the ? button, Dutch
 * @param string $caption    one line under the frame, Dutch
 */
function media_focus_field(string $name, string $value, string $previewSrc, string $legend, string $help, string $caption): void
{
    $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $value = \App\Service\Media\ImageFocus::normalise($value);
    ?>
    <fieldset class="admin-image-focus" data-image-focus>
      <legend><?= $h($legend) ?> <?= admin_help($legend, $help) ?></legend>
      <div class="admin-image-focus__body">
        <div class="admin-image-focus__grid">
          <?php foreach (\App\Service\Media\ImageFocus::keys() as $focusKey): ?>
            <label class="admin-image-focus__point" title="<?= admin_te('media.focus.' . $focusKey) ?>">
              <input type="radio" name="<?= $h($name) ?>" value="<?= $h($focusKey) ?>" data-object-position="<?= $h(\App\Service\Media\ImageFocus::objectPosition($focusKey)) ?>"<?= $value === $focusKey ? ' checked' : '' ?>>
              <span class="admin-visually-hidden"><?= admin_te('media.focus.' . $focusKey) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <figure class="admin-image-focus__preview">
          <div class="admin-image-focus__frame" data-image-focus-frame<?= $previewSrc === '' ? ' hidden' : '' ?>>
            <img src="<?= $h($previewSrc) ?>" alt="" style="object-position: <?= $h(\App\Service\Media\ImageFocus::objectPosition($value)) ?>" data-image-focus-preview>
          </div>
          <figcaption class="admin-text-muted"><?= $h($caption) ?></figcaption>
        </figure>
      </div>
    </fieldset>
    <?php
}
