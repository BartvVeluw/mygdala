<?php

declare(strict_types=1);

use App\Service\Media\MediaService;

require_once __DIR__ . '/_translate.php';

/**
 * The product editor's pictures (admin/product-form.php): the product's own
 * pool of pictures, chosen from the Media Library, and per variant the
 * subset of that pool it shows, with a description of its own if it needs
 * one (MODULES.md, "Shop").
 *
 * EVERYTHING HERE IS PART OF THE PRODUCT FORM. No field posts on its own and
 * no picture is uploaded to a Shop folder: a picture is chosen (or uploaded
 * into the library) through the shared picker, moved with ← → or a drag, and
 * the form's one Opslaan stores it all (api/admin/update-product.php,
 * App\Service\ProductGallery). admin/assets/product-gallery.js keeps the
 * hidden inputs in step with what is on screen.
 *
 * A picture is named by a token: `image:<id>` for one the product already
 * has, `media:<id>` for a library image chosen in this visit — the same
 * tokens the server resolves again. Without JavaScript the rendered inputs
 * post the stored state unchanged.
 */

/**
 * One picture of the pool as the screen shows it.
 *
 * @return array{token: string, src: string, name: string, media_id: ?int}
 */
function product_gallery_picture_from_row(array $row): array
{
    $path = (string) ($row['thumbnail_path'] ?? $row['image_path'] ?? '');
    $name = trim((string) ($row['display_name'] ?? ''));

    return [
        'token' => 'image:' . (int) $row['id'],
        'src' => '/' . ltrim($path, '/'),
        'name' => $name !== '' ? $name : basename((string) ($row['image_path'] ?? '')),
        'media_id' => !empty($row['media_id']) ? (int) $row['media_id'] : null,
    ];
}

/**
 * The pool to show: what a refused save sent (tokens, resolved again), else
 * what the product has stored.
 *
 * @param list<array<string, mixed>> $storedRows ProductImageRepository rows
 * @param list<string>|null          $oldTokens  the refused request's tokens, or null
 * @return list<array{token: string, src: string, name: string, media_id: ?int}>
 */
function product_gallery_pictures(array $storedRows, ?array $oldTokens): array
{
    $stored = [];
    foreach ($storedRows as $row) {
        $stored['image:' . (int) $row['id']] = product_gallery_picture_from_row($row);
    }

    if ($oldTokens === null) {
        return array_values($stored);
    }

    $pictures = [];
    foreach ($oldTokens as $token) {
        if (isset($stored[$token])) {
            $pictures[] = $stored[$token];
            continue;
        }

        if (preg_match('/^media:([1-9][0-9]{0,9})$/', (string) $token, $match) === 1) {
            $media = MediaService::findImage((int) $match[1]);
            if ($media !== null) {
                $pictures[] = [
                    'token' => 'media:' . $media->id,
                    'src' => $media->displayPath(),
                    'name' => $media->displayName(),
                    'media_id' => $media->id,
                ];
            }
        }
    }

    return $pictures;
}

/** The words product-gallery.js needs, from the catalog, as one JSON attribute. */
function product_gallery_words(): string
{
    return (string) json_encode([
        'left' => admin_t('shop.gallery.move_left'),
        'right' => admin_t('shop.gallery.move_right'),
        'remove' => admin_t('shop.gallery.remove'),
        'moved' => admin_t('shop.gallery.moved'),
        'removed' => admin_t('shop.gallery.removed'),
        'added' => admin_t('shop.gallery.added'),
        'duplicate' => admin_t('shop.gallery.duplicate'),
        'primary' => admin_t('shop.gallery.primary'),
        'variantFirst' => admin_t('shop.gallery.variant_first'),
        'tile' => admin_t('shop.gallery.tile'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * One card of a strip, as the script also builds it: the picture, its
 * position, the ← → × buttons and the hidden input that carries its token.
 *
 * @param array{token: string, src: string, name: string, media_id: ?int} $picture
 */
function product_gallery_card(array $picture, int $index, int $total, string $inputName, string $firstBadge): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <li class="admin-gallery__item" data-gallery-item draggable="true"
        data-token="<?= $h($picture['token']) ?>"
        data-src="<?= $h($picture['src']) ?>"
        data-name="<?= $h($picture['name']) ?>"<?= $picture['media_id'] !== null ? ' data-media-id="' . (int) $picture['media_id'] . '"' : '' ?>>
      <input type="hidden" name="<?= $h($inputName) ?>" value="<?= $h($picture['token']) ?>">
      <span class="admin-gallery__media"><img src="<?= $h($picture['src']) ?>" alt="" loading="lazy" draggable="false"></span>
      <span class="admin-gallery__position" aria-hidden="true"><?= $index + 1 ?></span>
      <?php if ($index === 0 && $firstBadge !== ''): ?>
        <span class="admin-gallery__badge"><?= $h($firstBadge) ?></span>
      <?php endif; ?>
      <span class="admin-gallery__name"><?= $h($picture['name']) ?></span>
      <span class="admin-gallery__actions">
        <button type="button" class="admin-gallery__btn" data-gallery-move="-1" aria-label="<?= admin_te('shop.gallery.move_left', ['name' => $picture['name']]) ?>"<?= $index === 0 ? ' disabled' : '' ?>>&larr;</button>
        <button type="button" class="admin-gallery__btn" data-gallery-move="1" aria-label="<?= admin_te('shop.gallery.move_right', ['name' => $picture['name']]) ?>"<?= $index === $total - 1 ? ' disabled' : '' ?>>&rarr;</button>
        <button type="button" class="admin-gallery__btn admin-gallery__btn--remove" data-gallery-remove aria-label="<?= admin_te('shop.gallery.remove', ['name' => $picture['name']]) ?>">&times;</button>
      </span>
    </li>
    <?php
}

/**
 * The pool: the grid, "Afbeelding toevoegen", and the marker that tells the
 * endpoint the section was on the form.
 *
 * @param list<array{token: string, src: string, name: string, media_id: ?int}> $pictures
 */
function product_gallery_pool(array $pictures): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $total = count($pictures);
    ?>
    <div class="admin-gallery" data-product-gallery data-gallery-input="gallery[]" data-gallery-words="<?= $h(product_gallery_words()) ?>">
      <input type="hidden" name="gallery_submitted" value="1" data-gallery-marker>
      <p class="admin-text-muted"><?= admin_te('shop.gallery.intro') ?></p>
      <ol class="admin-gallery__grid" data-gallery-list aria-label="<?= admin_te('shop.gallery.list_label') ?>">
        <?php foreach ($pictures as $index => $picture): ?>
          <?php product_gallery_card($picture, $index, $total, 'gallery[]', admin_t('shop.gallery.primary')); ?>
        <?php endforeach; ?>
      </ol>
      <p class="admin-text-muted" data-gallery-empty<?= $pictures !== [] ? ' hidden' : '' ?>><?= admin_te('shop.gallery.empty') ?></p>
      <div class="admin-gallery__add" data-media-picker data-media-picker-kind="image" data-media-picker-collect>
        <button type="button" class="admin-btn-secondary" data-media-picker-open>+ <?= admin_te('shop.gallery.add') ?></button>
      </div>
      <p class="admin-visually-hidden" role="status" aria-live="polite" data-gallery-status></p>
    </div>
    <?php
}

/**
 * Per variant: the pictures it shows (its own order, ← → × and drag), the
 * pool as tiles to tick, and "Eigen beschrijving voor deze variant".
 *
 * @param list<array{token: string, src: string, name: string, media_id: ?int}> $pictures the pool
 * @param list<array{id: int, label: string, tokens: list<string>, own: bool, html: string}> $variants
 */
function product_gallery_variants(array $pictures, array $variants): void
{
    if ($variants === []) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $byToken = [];
    foreach ($pictures as $picture) {
        $byToken[$picture['token']] = $picture;
    }
    ?>
    <h3><?= admin_te('shop.gallery.variants_heading') ?></h3>
    <p class="admin-text-muted"><?= admin_te('shop.gallery.variants_intro') ?></p>
    <?php foreach ($variants as $variant): ?>
      <?php
        $variantId = (int) $variant['id'];
        $chosen = array_values(array_filter($variant['tokens'], static fn (string $t): bool => isset($byToken[$t])));
        $ownId = 'variant-description-own-' . $variantId;
      ?>
      <fieldset class="admin-variant-gallery" data-variant-gallery data-variant-id="<?= $variantId ?>" data-variant-label="<?= $h($variant['label']) ?>">
        <legend><?= $h($variant['label']) ?></legend>
        <input type="hidden" name="variants_submitted[]" value="<?= $variantId ?>">

        <p class="admin-variant-gallery__label"><?= admin_te('shop.gallery.variant_pictures') ?></p>
        <ol class="admin-gallery__grid admin-gallery__grid--small" data-variant-gallery-list aria-label="<?= admin_te('shop.gallery.variant_list_label', ['variant' => $variant['label']]) ?>">
          <?php foreach ($chosen as $index => $token): ?>
            <?php product_gallery_card($byToken[$token], $index, count($chosen), 'variant_images[' . $variantId . '][]', admin_t('shop.gallery.variant_first')); ?>
          <?php endforeach; ?>
        </ol>
        <p class="admin-text-muted" data-variant-gallery-empty<?= $chosen !== [] ? ' hidden' : '' ?>><?= admin_te('shop.gallery.variant_all') ?></p>

        <div class="admin-gallery-tiles" data-variant-gallery-tiles role="group" aria-label="<?= admin_te('shop.gallery.tiles_label', ['variant' => $variant['label']]) ?>">
          <?php foreach ($pictures as $picture): ?>
            <?php $isChosen = in_array($picture['token'], $chosen, true); ?>
            <button type="button" class="admin-gallery-tile<?= $isChosen ? ' is-chosen' : '' ?>" data-token="<?= $h($picture['token']) ?>" aria-pressed="<?= $isChosen ? 'true' : 'false' ?>" aria-label="<?= admin_te('shop.gallery.tile', ['name' => $picture['name'], 'variant' => $variant['label']]) ?>">
              <img src="<?= $h($picture['src']) ?>" alt="" loading="lazy">
              <span class="admin-gallery-tile__mark" aria-hidden="true"><?= $isChosen ? '&#10003;' : '' ?></span>
            </button>
          <?php endforeach; ?>
        </div>

        <div class="admin-variant-description" data-variant-description>
          <label class="admin-checkbox-label" for="<?= $h($ownId) ?>">
            <input type="checkbox" class="admin-switch" role="switch" id="<?= $h($ownId) ?>" name="variant_description_own[<?= $variantId ?>]" value="1"<?= $variant['own'] ? ' checked' : '' ?> data-variant-description-toggle>
            <?= admin_te('shop.variant_description.own') ?>
          </label>
          <p class="admin-text-muted" data-variant-description-inherited<?= $variant['own'] ? ' hidden' : '' ?>><?= admin_te('shop.variant_description.inherited') ?></p>
          <div data-variant-description-editor<?= $variant['own'] ? '' : ' hidden' ?>>
            <?php renderRichTextField('variant_description[' . $variantId . ']', admin_t('shop.variant_description.label'), $variant['html']); ?>
          </div>
        </div>
      </fieldset>
    <?php endforeach; ?>
    <?php
}
