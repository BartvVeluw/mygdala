<?php

declare(strict_types=1);

use App\Service\Language\AdminTranslator;
use App\Service\Media\MediaItem;
use App\Service\Media\MediaService;

/**
 * The share image a product or collection form chose (Media Library 2.0):
 * the `og_media_id` of the shared picker in social-image mode
 * (MediaType::SOCIAL_IMAGE: raster only, no SVG), resolved against the
 * library before anything is written — the same rule update-page.php and
 * update-blog-post.php apply. Nothing is uploaded here; that happens once,
 * inside the picker.
 *
 * WHAT A FORM MEANS:
 *   - a library id                  -> that image (the one already stored stays acceptable);
 *   - an id naming nothing usable   -> refused with a message, nothing written;
 *   - empty, with a library image   -> no share image (the picker's "Wissen");
 *   - empty, with an OLD own file   -> kept, unless remove_og_image is ticked:
 *     an image from before the library is not in the picker, so an empty
 *     picker says nothing about it;
 *   - field not on the form         -> nothing changes.
 *
 * `old_path` names an old own file this save stops using: it was the Shop's
 * alone, so the endpoint deletes it after the commit. A library file is never
 * deleted here.
 *
 * @param array<string, mixed> $post     $_POST
 * @param array<string, mixed> $existing the stored row ([] for a new one)
 * @return array{change: bool, media: MediaItem|null, old_path: string|null, error: string|null}
 */
function shop_share_image_choice(array $post, array $existing): array
{
    $none = ['change' => false, 'media' => null, 'old_path' => null, 'error' => null];

    if (!array_key_exists('og_media_id', $post)) {
        return $none;
    }

    $storedMediaId = (int) ($existing['og_media_id'] ?? 0);
    $storedPath = trim((string) ($existing['og_image_path'] ?? ''));
    $legacyPath = $storedMediaId === 0 && $storedPath !== '' ? $storedPath : null;
    $posted = trim((string) $post['og_media_id']);

    if ($posted !== '') {
        $media = MediaService::findSocialImage(ctype_digit($posted) ? (int) $posted : null, $storedMediaId);

        if ($media === null) {
            return ['error' => AdminTranslator::trans('validation.gekozen_deel_afbeelding_bestaat_meer')] + $none;
        }

        if ($media->id === $storedMediaId) {
            return $none;
        }

        return ['change' => true, 'media' => $media, 'old_path' => $legacyPath, 'error' => null];
    }

    if ($storedMediaId > 0) {
        return ['change' => true, 'media' => null, 'old_path' => null, 'error' => null];
    }

    if ($legacyPath !== null && ($post['remove_og_image'] ?? null) === '1') {
        return ['change' => true, 'media' => null, 'old_path' => $legacyPath, 'error' => null];
    }

    return $none;
}
