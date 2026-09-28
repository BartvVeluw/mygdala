<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\MediaBannerRepository;
use App\Service\Media\ResponsiveImage;
use App\Service\Media\ResponsiveImageSlot;
use App\Service\Media\MediaItem;
use App\Service\Media\MediaSequence;
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
 * height below. A stored value this class does not know reads as the default.
 * How a picture sits in the frame on a large screen and on a phone is its
 * Responsive Media presentation (imageSlot()): `presentation`, and for one
 * picture `picture`, what partials/responsive-image.php prints. The heights themselves are steps in
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
 * MORE THAN ONE ITEM makes the banner a media sequence
 * (App\Service\Media\MediaSequence, db/migrations/20260928180000): its first
 * item stays `media_id`, the ones after it are media_banner_items, and
 * `items` is the whole list, first included, each a MediaSequence::slide()
 * with the library's alt text. The video contract grows with it:
 *   - "plays by itself" (video_autoplay) is the sequence's: a picture stays
 *     `duration` seconds, a video plays to its end, muted, then the next
 *     comes; "repeat" (video_loop) starts it again after its last item, and a
 *     single video inside a sequence never loops;
 *   - a sequence that does not play by itself has a way to move: arrows or
 *     dots, never none (`nav`); the endpoint refuses that combination too;
 *   - a video in a sequence that does not play by itself has its controls;
 *   - the poster is the first item's, when that is a video; the focus point
 *     applies to every picture.
 * A further item that is gone, or is neither a picture nor a video, is left
 * out; without a usable first item there is no banner at all.
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
     * @return array{state: string, kind: string, src: string, mime: string, alt: string, intrinsic_width: int|null, intrinsic_height: int|null, poster: string, width: string, height: string, presentation: ResponsiveImage, picture: array<string, mixed>|null, mobile_height: string|null, autoplay: bool, loop: bool, controls: bool, items: list<array<string, mixed>>, transition: string, duration: int, nav: string}
     *         kind is MediaType::IMAGE, MediaType::VIDEO or '' for nothing to
     *         show; templates must check 'state' !== STATE_HIDDEN first
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $further = [];
        try {
            $repository = new MediaBannerRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
            if ($row !== null && (bool) $row['is_active']) {
                $further = $repository->findItemIds((int) $row['id']);
            }
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

        return self::$cache[$cacheKey] = ['state' => self::STATE_ACTIVE] + self::fromRow($row, $further);
    }

    /**
     * What a stored row shows, every value checked: the item and its kind,
     * the layout, the video options under the contract above, and — when
     * $furtherIds names more items — the whole sequence. For the page and
     * for the editor alike; an empty row is an empty banner with every
     * default.
     *
     * @param array<string, mixed> $row
     * @param list<int>            $furtherIds the items after the first (media_banner_items), in order
     *
     * @return array{kind: string, src: string, mime: string, alt: string, intrinsic_width: int|null, intrinsic_height: int|null, poster: string, width: string, height: string, presentation: ResponsiveImage, picture: array<string, mixed>|null, mobile_height: string|null, autoplay: bool, loop: bool, controls: bool, items: list<array<string, mixed>>, transition: string, duration: int, nav: string}
     */
    public static function fromRow(array $row, array $furtherIds = []): array
    {
        $item = self::usableItem(isset($row['media_id']) ? (int) $row['media_id'] : null);
        $kind = $item?->kind() ?? '';
        $isImage = $kind === MediaType::IMAGE;
        $isVideo = $kind === MediaType::VIDEO;

        // The whole sequence, first item included: only with a usable first.
        $items = [];
        if ($item !== null) {
            $items[] = MediaSequence::slide($item, $item->altText);
            MediaService::preload($furtherIds);
            foreach ($furtherIds as $id) {
                $further = self::usableItem((int) $id);
                if ($further !== null) {
                    $items[] = MediaSequence::slide($further, $further->altText);
                }
            }
        }
        $isSequence = count($items) > 1;
        $hasImage = in_array(MediaType::IMAGE, array_column($items, 'kind'), true);
        $hasVideo = in_array(MediaType::VIDEO, array_column($items, 'kind'), true);

        // Plays by itself: one video, or a whole sequence.
        $autoplay = ($isVideo || $isSequence) && (bool) ($row['video_autoplay'] ?? false);
        $poster = $isVideo ? self::poster(isset($row['poster_media_id']) ? (int) $row['poster_media_id'] : null) : null;

        // How a picture sits in the frame: only a banner with a picture has a
        // presentation to apply; a video fills its frame from the middle.
        $presentation = $hasImage ? ResponsiveImage::fromRow($row, self::imageSlot()) : new ResponsiveImage();

        // A sequence that does not move by itself has a way to move.
        $nav = MediaSequence::controls($row['slide_controls'] ?? null);
        if ($isSequence && !$autoplay && $nav === 'none') {
            $nav = 'dots';
        }

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
            'presentation' => $presentation,
            // One picture, with a phone's own when it has one; a sequence
            // prints its pictures with the presentation alone.
            'picture' => $isImage && !$isSequence ? $presentation->forRender([
                'image_path' => $item->publicPath(),
                'alt' => trim($item->altText),
                'width' => $item->hasDimensions() ? $item->width : null,
                'height' => $item->hasDimensions() ? $item->height : null,
            ]) : null,
            'mobile_height' => $presentation->mobileHeight,
            'autoplay' => $autoplay,
            'loop' => ($isVideo || $isSequence) && (bool) ($row['video_loop'] ?? false),
            // Without autoplay, the controls are the only way to start it.
            'controls' => $hasVideo && (!$autoplay || (bool) ($row['video_controls'] ?? true)),
            'items' => $isSequence ? $items : [],
            'transition' => MediaSequence::transition($row['slide_transition'] ?? null),
            'duration' => MediaSequence::duration($row['slide_duration'] ?? null),
            'nav' => $nav,
        ];
    }

    /**
     * Where a banner keeps its picture's presentation (Responsive Media 2.0):
     * the image_ columns of media_banners, with a fit — the picture fills a
     * frame of the chosen height — and a phone height of its own
     * (media-banner.css). A sequence's pictures share it; a phone's own
     * picture is only for a banner with one picture.
     */
    public static function imageSlot(): ResponsiveImageSlot
    {
        return new ResponsiveImageSlot('image_', 'media_id', fit: true, mobileHeight: true);
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
