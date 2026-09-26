<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\MediaBannerRepository;
use App\Service\Media\ImageFocus;
use App\Service\Media\MediaItem;
use App\Service\Media\MediaService;
use App\Service\Media\MediaType;

/**
 * Read model of the Mediabanner block (App\Service\Blocks\MediaBannerBlock):
 * one picture or one video from the Media Library as a section of its own,
 * in a chosen width and height, without words (CONTENT-BLOCKS.md,
 * "Mediabanner").
 *
 * THE LIBRARY ITEM DECIDES WHAT IT IS. A banner stores a media id, never a
 * type: an item whose MIME type is an image is a picture, one that is a video
 * is a video (App\Service\Media\MediaType), and anything else — an item that
 * is gone, or a file no kind claims — is nothing to show. There is no image or
 * video switch to disagree with the item.
 *
 * EVERY CHOICE IS A WORD FROM A CLOSED LIST (CONTENT-BLOCKS.md, "Een
 * weergavekeuze is een woord uit een gesloten lijst"): the width and the
 * height below, and the focus point of ImageFocus. A stored value this class
 * does not know reads as the default. The heights themselves are steps in
 * assets/css/blocks/media-banner.css, smaller on a phone.
 *
 * THE VIDEO CONTRACT, decided here and nowhere else:
 *   - a video that plays by itself is always muted: there is no autoplay with
 *     sound, and no stored "muted" that could say otherwise;
 *   - a video that does not play by itself always has controls, since a
 *     visitor could otherwise never start it (the endpoint refuses that
 *     combination too; this is the second line);
 *   - the focus point is a picture's: a video is always centred;
 *   - a poster is only a picture, and only for a video.
 *
 * The three states of every block (CONTENT-BLOCKS.md): no row or a failed
 * lookup is STATE_FALLBACK, a row switched off is STATE_HIDDEN. Both render
 * nothing, and so does an active row without a usable item ('kind' '').
 */
final class MediaBannerContent
{
    public const STATE_FALLBACK = 'fallback';

    public const STATE_ACTIVE = 'active';

    public const STATE_HIDDEN = 'hidden';

    /** How wide the banner runs: inside the container, or the whole page. */
    public const WIDTHS = ['content', 'full'];

    public const DEFAULT_WIDTH = 'content';

    /** How high the banner is, smallest first. The lengths are in media-banner.css. */
    public const HEIGHTS = ['small', 'medium', 'large', 'xlarge'];

    public const DEFAULT_HEIGHT = 'medium';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array{state: string, kind: string, src: string, mime: string, alt: string, intrinsic_width: int|null, intrinsic_height: int|null, poster: string, width: string, height: string, focus: string, autoplay: bool, loop: bool, controls: bool}
     *         kind is MediaType::IMAGE, MediaType::VIDEO or '' for nothing to
     *         show; templates must check 'state' !== STATE_HIDDEN first
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $row = (new MediaBannerRepository())->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[MediaBannerContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());
            $row = null;
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_FALLBACK] + self::fromRow([]);
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = ['state' => self::STATE_HIDDEN] + self::fromRow([]);
        }

        return self::$cache[$cacheKey] = ['state' => self::STATE_ACTIVE] + self::fromRow($row);
    }

    /**
     * What a stored row shows, every value checked: the item and its kind,
     * the layout, and the video options under the contract above. For the
     * page and for the editor alike; an empty row is an empty banner with
     * every default.
     *
     * @param array<string, mixed> $row
     *
     * @return array{kind: string, src: string, mime: string, alt: string, intrinsic_width: int|null, intrinsic_height: int|null, poster: string, width: string, height: string, focus: string, autoplay: bool, loop: bool, controls: bool}
     */
    public static function fromRow(array $row): array
    {
        $item = self::usableItem(isset($row['media_id']) ? (int) $row['media_id'] : null);
        $kind = $item?->kind() ?? '';
        $isImage = $kind === MediaType::IMAGE;
        $isVideo = $kind === MediaType::VIDEO;

        $autoplay = $isVideo && (bool) ($row['video_autoplay'] ?? false);
        $poster = $isVideo ? self::poster(isset($row['poster_media_id']) ? (int) $row['poster_media_id'] : null) : null;

        return [
            'kind' => $kind,
            'src' => $item?->publicPath() ?? '',
            'mime' => $isVideo ? strtolower($item->mimeType) : '',
            'alt' => $isImage ? trim($item->altText) : '',
            'intrinsic_width' => $isImage && $item->hasDimensions() ? $item->width : null,
            'intrinsic_height' => $isImage && $item->hasDimensions() ? $item->height : null,
            'poster' => $poster?->publicPath() ?? '',
            'width' => self::width($row['width'] ?? null),
            'height' => self::height($row['height'] ?? null),
            'focus' => $isImage ? ImageFocus::normalise($row['image_focus'] ?? null) : ImageFocus::DEFAULT,
            'autoplay' => $autoplay,
            'loop' => $isVideo && (bool) ($row['video_loop'] ?? false),
            // Without autoplay, the controls are the only way to start it.
            'controls' => $isVideo && (!$autoplay || (bool) ($row['video_controls'] ?? true)),
        ];
    }

    /**
     * The library item a banner may show: a picture or a video, or null for
     * an id that names nothing, or names a file no kind claims. The endpoint
     * reads a posted id through this too, so what can be stored is exactly
     * what can be shown.
     */
    public static function usableItem(?int $id): ?MediaItem
    {
        $item = MediaService::find($id);

        return $item !== null && ($item->isPicture() || $item->isVideo()) ? $item : null;
    }

    /** A video's poster: a picture of the library, never a video. */
    public static function poster(?int $id): ?MediaItem
    {
        $item = MediaService::find($id);

        return $item !== null && $item->isPicture() ? $item : null;
    }

    /** A stored width, or the default for anything this class does not know. */
    public static function width(mixed $stored): string
    {
        return is_string($stored) && in_array($stored, self::WIDTHS, true) ? $stored : self::DEFAULT_WIDTH;
    }

    /** A stored height, or the default for anything this class does not know. */
    public static function height(mixed $stored): string
    {
        return is_string($stored) && in_array($stored, self::HEIGHTS, true) ? $stored : self::DEFAULT_HEIGHT;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
