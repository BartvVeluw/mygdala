<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * One image on a content block, resolved once for every block that uses the
 * Media Library.
 *
 * THE TRANSITIONAL RULE, in one place. Each integrated block row carries both
 * a `media_id` and the `image_path` it had before the library existed:
 *
 *     media id set and the item still exists  ->  the media item
 *     otherwise                               ->  the stored path
 *
 * Written here rather than three times over, so "which of the two wins" can
 * be retired later by changing one method instead of hunting for copies.
 * App\Service\Branding applies exactly the same rule to the site's branding
 * assets; that one is separate because a setting is not a table row, not
 * because the rule differs.
 *
 * ALT TEXT IS LAYERED, and the layering is the point of centralising media
 * at all:
 *
 *     the block's own alt_nl/alt_en   ->  the media item's default alt text
 *
 * The local field wins when it has something to say, because the same photo
 * can genuinely mean different things in two places. It is not removed and
 * it is not migrated away: an editor who has written a caption keeps it, and
 * one who has not now gets the central one for free instead of an empty
 * alt="". See MEDIA.md.
 *
 * DIMENSIONS come along when the library knows them, so a block can render
 * width/height and the browser can reserve the space before the image
 * arrives. Unknown is a perfectly good answer — an adopted SVG has no pixel
 * size — and a caller must treat it as "omit the attributes", never as zero.
 */
final class BlockImage
{
    /**
     * @param array<string, mixed> $row       the block's own row
     * @param string               $mediaKey  column holding the media id
     * @param string               $pathKey   column holding the legacy path
     * @param string               $altNlKey  column holding the local Dutch alt
     * @param string               $altEnKey  column holding the local English alt
     *
     * @return array{image_path: string, alt_nl: string, alt_en: string, width: int|null, height: int|null, media_id: int|null}
     */
    public static function fromRow(
        array $row,
        string $mediaKey = 'media_id',
        string $pathKey = 'image_path',
        string $altNlKey = 'alt_nl',
        string $altEnKey = 'alt_en'
    ): array {
        $media = MediaService::find(isset($row[$mediaKey]) ? (int) $row[$mediaKey] : null);

        $localNl = trim((string) ($row[$altNlKey] ?? ''));
        $localEn = trim((string) ($row[$altEnKey] ?? ''));

        $altNl = $localNl !== '' ? $localNl : ($media?->altText ?? '');
        // Empty English has always meant "same as Dutch" in this project.
        $altEn = $localEn !== '' ? $localEn : $altNl;

        if ($media !== null) {
            return [
                'image_path' => $media->publicPath(),
                'alt_nl' => $altNl,
                'alt_en' => $altEn,
                'width' => $media->hasDimensions() ? $media->width : null,
                'height' => $media->hasDimensions() ? $media->height : null,
                'media_id' => $media->id,
            ];
        }

        return [
            'image_path' => self::normalisePath((string) ($row[$pathKey] ?? '')),
            'alt_nl' => $altNl,
            'alt_en' => $altEn,
            'width' => null,
            'height' => null,
            'media_id' => null,
        ];
    }

    /**
     * Turns the media id a picker submitted into the two columns a block row
     * stores, after checking that the id names a real media item.
     *
     * This is where an untrusted request value stops being untrusted: a
     * number that matches nothing comes back as "no image", never as a
     * stored id. Nothing else about the submitted value is used — no path,
     * no filename, no URL — so there is nothing to traverse or spoof.
     *
     * The legacy path column is written with the media item's OWN path
     * rather than anything from the request, which is what keeps it truthful
     * while it still exists (MEDIA.md).
     *
     * @return array{media_id: int|null, image_path: string}
     */
    public static function fromRequest(mixed $submitted): array
    {
        $media = MediaService::find(is_numeric($submitted) ? (int) $submitted : null);

        if ($media === null) {
            return ['media_id' => null, 'image_path' => ''];
        }

        return ['media_id' => $media->id, 'image_path' => $media->path];
    }

    /**
     * The two attributes an <img> should carry when the size is known, as a
     * ready-to-print string — including the leading space, and empty when it
     * is not known. Keeps three partials from each writing the same
     * conditional.
     *
     * @param array{width: int|null, height: int|null} $image
     */
    public static function dimensionAttributes(array $image): string
    {
        $width = $image['width'] ?? null;
        $height = $image['height'] ?? null;

        if ($width === null || $height === null || $width < 1 || $height < 1) {
            return '';
        }

        return ' width="' . (int) $width . '" height="' . (int) $height . '"';
    }

    /**
     * One root-relative form, the convention CONTENT-BLOCKS.md states for
     * every URL a partial prints. An absolute URL passes through untouched;
     * empty stays empty, because "" is how a block says it has no image.
     */
    public static function normalisePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return '/' . ltrim($path, '/');
    }
}
