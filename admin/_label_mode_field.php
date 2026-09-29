<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_media_picker.php';

use App\Service\Blocks\LabelMode;
use App\Service\Media\MediaItem;
use App\Service\Media\MediaType;

/**
 * THE CHOICE OF WHAT STANDS ABOVE A TITLE (App\Service\Blocks\LabelMode,
 * ADMIN-UI.md "Nummer of label"): a card of the Kaarten-carrousel
 * (admin/carousel-card.php) and a Detailsectie (admin/detail-section.php).
 *
 *   one select, `label_mode`, the same in every language: nothing, a number
 *   from the place (01 or 1), an icon (a card only), or own words;
 *   the own words: the block's own translated text field, printed by the
 *   caller ($field['custom']) and shown only for "Eigen tekst";
 *   the icon: a Media Library picker for an SVG (MediaType::ICON), shown only
 *   for "Icoon".
 *
 * WITHOUT THE SCRIPT every part is visible and posts as usual; the server
 * prints the same `hidden` for the stored choice, and
 * admin/assets/label-mode.js only follows the select. Hidden parts are still
 * posted: the endpoint decides what a mode keeps (the words stay stored, an
 * icon is let go when the mode is not "Icoon").
 *
 * @param array{
 *     id: string,
 *     label: string,
 *     help: string,
 *     modes: list<string>,
 *     mode: string,
 *     custom: callable(): void,
 *     icon?: array{name: string, media: MediaItem|null}|null,
 *     errors?: array<string, string>,
 * } $field
 *        errors: by part, `mode` and `icon`
 */
function label_mode_field(array $field): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $id = $field['id'];
    $mode = $field['mode'];
    $errors = $field['errors'] ?? [];
    $icon = $field['icon'] ?? null;
    ?>
      <div class="admin-label-mode" data-label-mode-field>
        <div class="admin-field">
          <?= admin_field_label($id, $field['label'], $field['help']) ?>
          <select class="admin-select" id="<?= $h($id) ?>" name="label_mode" data-label-mode<?= isset($errors['mode']) ? ' aria-invalid="true" aria-describedby="' . $h($id) . '-error"' : '' ?>>
            <?php foreach ($field['modes'] as $option): ?>
              <option value="<?= $h($option) ?>"<?= $option === $mode ? ' selected' : '' ?>><?= admin_te('label_mode.' . $option) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['mode'])): ?>
            <p class="admin-field-error" id="<?= $h($id) ?>-error"><?= $h($errors['mode']) ?></p>
          <?php endif; ?>
        </div>

        <div data-label-mode-when="<?= $h(LabelMode::CUSTOM) ?>"<?= $mode === LabelMode::CUSTOM ? '' : ' hidden' ?>>
          <?php ($field['custom'])(); ?>
        </div>

        <?php if ($icon !== null): ?>
          <div class="admin-form-row" data-label-mode-when="<?= $h(LabelMode::ICON) ?>"<?= $mode === LabelMode::ICON ? '' : ' hidden' ?>>
            <?php media_picker_field($icon['name'], $icon['media'], admin_t('label_mode.icon_picker'), admin_t('help.label_mode.icon_picker'), true, MediaType::ICON); ?>
            <?php if (isset($errors['icon'])): ?>
              <p class="admin-field-error"><?= $h($errors['icon']) ?></p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php
}

/** The script of every label field on the screen; print it once, near the end of <body>. */
function label_mode_field_script(): void
{
    ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/label-mode.js') ?>" defer></script>
    <?php
}
