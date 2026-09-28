<?php

declare(strict_types=1);

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';
require_once __DIR__ . '/_media_picker.php';

use App\Service\Media\MediaItem;
use App\Service\Media\MediaSequence;
use App\Service\Media\MediaType;

/**
 * The editor half of a media sequence (App\Service\Media\MediaSequence): the
 * list of items that come AFTER a block's own picture or video, and the
 * choices of how they follow each other. The Paginakop (admin/page-hero.php)
 * and the Mediabanner (admin/media-banner.php) print it; neither builds a
 * list of its own.
 *
 * THE LIST is the shared ordered picture list of the product and Portfolio
 * editors ([data-picture-gallery], admin/assets/product-gallery.js): a card
 * per item with ← → × and drag, "+ Toevoegen" through the Media picker in
 * collecting mode, and one hidden `sequence[]` input per card carrying the
 * token `media:<id>`. The block's own item (its `media_id` field) cannot be
 * added again. A video item shows the video icon, since there is no frame to
 * show of it. Nothing is saved here: the form's one Opslaan sends the list,
 * and the endpoint checks every id again (MediaSequence::idsFromTokens()).
 * `sequence_submitted` tells the endpoint the list was on the form, so a
 * form from before it never empties a stored sequence.
 *
 * THE CHOICES are ordinary selects of MediaSequence's closed lists, marked
 * [data-media-sequence-needs] so the screen's own script shows them only once
 * the list has an item; the server prints the same `hidden`.
 */

/**
 * The words product-gallery.js needs for this list, from the catalog.
 */
function media_sequence_words(): string
{
    return (string) json_encode([
        'left' => admin_t('media_sequence.move_left'),
        'right' => admin_t('media_sequence.move_right'),
        'remove' => admin_t('media_sequence.remove'),
        'moved' => admin_t('media_sequence.moved'),
        'removed' => admin_t('media_sequence.removed'),
        'added' => admin_t('media_sequence.added'),
        'duplicate' => admin_t('media_sequence.duplicate'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * The list of further items.
 *
 * @param list<MediaItem> $items what is in the list now, in its order
 * @param array{kind: string, label: string, help: string, empty: string, add: string} $options
 *        kind: MediaType::IMAGE, or MediaType::VISUAL for pictures and videos
 */
function media_sequence_field(array $items, array $options): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $kind = $options['kind'] === MediaType::VISUAL ? MediaType::VISUAL : MediaType::IMAGE;
    $total = count($items);
    ?>
    <div class="admin-field admin-gallery admin-media-sequence" data-picture-gallery data-media-sequence-list data-gallery-input="sequence[]" data-gallery-first-badge="" data-gallery-exclude-input="media_id" data-gallery-words="<?= $h(media_sequence_words()) ?>">
      <span class="admin-media-picker__label" id="media-sequence-label"><?= $h($options['label']) ?> <?= admin_help($options['label'], $options['help']) ?></span>
      <input type="hidden" name="sequence_submitted" value="1" data-gallery-marker>
      <ol class="admin-gallery__grid admin-gallery__grid--small" data-gallery-list aria-labelledby="media-sequence-label">
        <?php foreach (array_values($items) as $index => $item):
            $name = $item->displayName();
            $isVideo = $item->isVideo();
            ?>
        <li class="admin-gallery__item" data-gallery-item draggable="true"
            data-token="media:<?= (int) $item->id ?>"
            data-src="<?= $isVideo ? '' : $h($item->displayPath()) ?>"
            data-name="<?= $h($name) ?>"
            data-kind="<?= $isVideo ? 'video' : 'image' ?>"
            data-media-id="<?= (int) $item->id ?>">
          <input type="hidden" name="sequence[]" value="media:<?= (int) $item->id ?>">
          <span class="admin-gallery__media"><?php if ($isVideo): ?><?= media_video_icon() ?><?php else: ?><img src="<?= $h($item->displayPath()) ?>" alt="" loading="lazy" draggable="false"><?php endif; ?></span>
          <span class="admin-gallery__position" aria-hidden="true"><?= $index + 1 ?></span>
          <span class="admin-gallery__name"><?= $h($name) ?></span>
          <span class="admin-gallery__actions">
            <button type="button" class="admin-gallery__btn" data-gallery-move="-1" aria-label="<?= admin_te('media_sequence.move_left', ['name' => $name]) ?>"<?= $index === 0 ? ' disabled' : '' ?>>&larr;</button>
            <button type="button" class="admin-gallery__btn" data-gallery-move="1" aria-label="<?= admin_te('media_sequence.move_right', ['name' => $name]) ?>"<?= $index === $total - 1 ? ' disabled' : '' ?>>&rarr;</button>
            <button type="button" class="admin-gallery__btn admin-gallery__btn--remove" data-gallery-remove aria-label="<?= admin_te('media_sequence.remove', ['name' => $name]) ?>">&times;</button>
          </span>
        </li>
        <?php endforeach; ?>
      </ol>
      <p class="admin-text-muted" data-gallery-empty<?= $items !== [] ? ' hidden' : '' ?>><?= $h($options['empty']) ?></p>
      <div class="admin-gallery__add" data-media-picker data-media-picker-kind="<?= $h($kind) ?>" data-media-picker-collect>
        <button type="button" class="admin-btn-secondary" data-media-picker-open>+ <?= $h($options['add']) ?></button>
      </div>
      <p class="admin-visually-hidden" role="status" aria-live="polite" data-gallery-status></p>
    </div>
    <?php
}

/**
 * The choices of a sequence: the transition, a picture's time, and — for a
 * block that offers them — which buttons a visitor gets. Each a select of
 * MediaSequence's closed list with the current value chosen; shown only while
 * the list has an item ($hasItems).
 *
 * @param array{transition: string, duration: int, controls?: string} $values
 */
function media_sequence_choices(array $values, bool $hasItems, bool $withControls = false): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $transition = MediaSequence::transition($values['transition'] ?? null);
    $duration = MediaSequence::duration($values['duration'] ?? null);
    $controls = MediaSequence::controls($values['controls'] ?? null);
    ?>
    <div class="admin-form-row admin-form-row--split" data-media-sequence-needs<?= $hasItems ? '' : ' hidden' ?>>
      <div class="admin-field">
        <?= admin_field_label('media-sequence-transition', admin_t('media_sequence.transition'), admin_t('help.media_sequence.transition')) ?>
        <select class="admin-select" id="media-sequence-transition" name="slide_transition">
          <?php foreach (MediaSequence::TRANSITIONS as $option): ?>
            <option value="<?= $h($option) ?>"<?= $option === $transition ? ' selected' : '' ?>><?= admin_te('media_sequence.transition_' . $option) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <?= admin_field_label('media-sequence-duration', admin_t('media_sequence.duration'), admin_t('help.media_sequence.duration')) ?>
        <select class="admin-select" id="media-sequence-duration" name="slide_duration">
          <?php foreach (MediaSequence::DURATIONS as $seconds): ?>
            <option value="<?= $seconds ?>"<?= $seconds === $duration ? ' selected' : '' ?>><?= admin_te($seconds === 1 ? 'media_sequence.second' : 'media_sequence.seconds', ['n' => (string) $seconds]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($withControls): ?>
      <div class="admin-field">
        <?= admin_field_label('media-sequence-controls', admin_t('media_sequence.controls'), admin_t('help.media_sequence.controls')) ?>
        <select class="admin-select" id="media-sequence-controls" name="slide_controls">
          <?php foreach (MediaSequence::CONTROLS as $option): ?>
            <option value="<?= $h($option) ?>"<?= $option === $controls ? ' selected' : '' ?>><?= admin_te('media_sequence.controls_' . $option) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
    </div>
    <?php
}

/** The shared list's behaviour (admin/assets/product-gallery.js), after the Media picker's. */
function media_sequence_script(): void
{
    echo '<script src="' . htmlspecialchars(\App\Service\AssetVersion::url('/admin/assets/product-gallery.js'), ENT_QUOTES, 'UTF-8') . '" defer></script>';
}
