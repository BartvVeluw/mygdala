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
 *     the block's own alt text   ->  the media item's default alt text
 *
 * The local field wins when it has something to say, because the same photo
 * can genuinely mean different things in two places. It is not removed and
 * it is not migrated away: an editor who has written a caption keeps it, and
 * one who has not now gets the central one for free instead of an empty
 * alt="". See MEDIA.md.
 *
 * INHERITED VERSUS OWN, as stored. An empty local field means "the library's
 * alt text, whatever it is at the time", so a later change in the library
 * reaches every place that did not write its own. The editor SHOWS the
 * library's text in that field (admin/_media_picker.php, media_alt_attrs()),
 * which is why ownAlt() turns a submitted text that is exactly the library's
 * back into empty before it is stored: seeing an alt text is not choosing it.
 *
 * DIMENSIONS come along when the library knows them, so a block can render
 * width/height and the browser can reserve the space before the image
 * arrives. Unknown is a perfectly good answer — an adopted SVG has no pixel
 * size — and a caller must treat it as "omit the attributes", never as zero.
 */
final class BlockImage
{
    /**
     * One image of a block row. The block's own alt text is stored per
     * website language (App\Service\Blocks\BlockLocalization) and arrives as
     * one string in the language of the request, its fallback to the default
     * language already applied:
     *
     *     the block's alt in this language  ->  in the default language
     *                                        ->  the media item's alt text
     *
     * The last layer is this class's; a partial prints `alt` escaped and
     * decides nothing.
     *
     * A row without an alt text of its own (the Paginakop, a blog post's
     * featured image) passes null and gets the media item's.
     *
     * @param array<string, mixed> $row      the block's own row
     * @param string|null          $alt      the block's own alt text (BlockLocalization::text())
     * @param string               $mediaKey column holding the media id
     * @param string               $pathKey  column holding the legacy path
     *
     * @return array{image_path: string, alt: string, width: int|null, height: int|null, media_id: int|null}
     */
    public static function fromOwner(array $row, ?string $alt, string $mediaKey = 'media_id', string $pathKey = 'image_path'): array
    {
        $media = MediaService::find(isset($row[$mediaKey]) ? (int) $row[$mediaKey] : null);
        $own = trim((string) $alt);

        $layered = $own !== '' ? $own : trim((string) ($media?->altText ?? ''));

        if ($media !== null) {
            return [
                'image_path' => $media->publicPath(),
                'alt' => $layered,
                'width' => $media->hasDimensions() ? $media->width : null,
                'height' => $media->hasDimensions() ? $media->height : null,
                'media_id' => $media->id,
            ];
        }

        return [
            'image_path' => self::normalisePath((string) ($row[$pathKey] ?? '')),
            'alt' => $layered,
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

        // An image field takes a picture. A video id sent to it (a crafted
        // request; the picker never lists one) is no image, like a number
        // that matches nothing.
        if ($media === null || $media->isVideo()) {
            return ['media_id' => null, 'image_path' => ''];
        }

        return ['media_id' => $media->id, 'image_path' => $media->path];
    }

    /**
     * The block's own alt text as it is stored: the submitted text, or '' when
     * it is exactly the alt text the library has for this media item — that
     * is the text the editor was shown, not one they wrote, and storing it
     * would cut this place off from a later change in the library.
     *
     * Only in the default language: a translation's empty field falls back to
     * the default language's alt text, not to the library's, so there the
     * same text can be a real choice.
     */
    public static function ownAlt(string $submitted, mixed $mediaId, bool $inDefaultLanguage = true): string
    {
        $submitted = trim($submitted);

        if (!$inDefaultLanguage || $submitted === '') {
            return $submitted;
        }

        $media = MediaService::find(is_numeric($mediaId) ? (int) $mediaId : null);

        return $media !== null && $submitted === trim($media->altText) ? '' : $submitted;
    }

    /**
     * ownAlt() for every row of a posted row list (`<list>[<key>][<field>]`,
     * App\Service\Blocks\EditorRows), before the list is read. A new row is
     * always written in the default language (EditorChildList); a stored row
     * in the language on screen.
     *
     * @param mixed $rows the posted list, $_POST[<list>]
     *
     * @return mixed the same list, alt texts that only repeat the library's emptied
     */
    public static function ownAltInRows(mixed $rows, bool $inDefaultLanguage, string $mediaField = 'media_id', string $altField = 'alt'): mixed
    {
        if (!is_array($rows)) {
            return $rows;
        }

        foreach ($rows as $key => $fields) {
            if (!is_array($fields) || !isset($fields[$altField]) || !is_string($fields[$altField])) {
                continue;
            }

            $isNew = !ctype_digit((string) $key);
            $rows[$key][$altField] = self::ownAlt($fields[$altField], $fields[$mediaField] ?? null, $isNew || $inDefaultLanguage);
        }

        return $rows;
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
