<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * A MEDIA SEQUENCE ("mediareeks"): more than one picture or video in one
 * frame, one after the other. The rules a block needs for it, in one place,
 * so no block writes its own slider:
 *
 *   - the closed lists: how one item gives way to the next (TRANSITIONS), how
 *     long a picture stays (DURATIONS, whole seconds), and which buttons a
 *     visitor gets (CONTROLS); each stored as a word or a number and read
 *     back through its own method, where anything unknown is the default;
 *   - reading the list an editor posts (idsFromTokens()): the `media:<id>`
 *     tokens of the shared picture list (admin/_media_sequence_field.php), in
 *     their order, at most MAX_ITEMS in all;
 *   - slide(): the one shape a slide has, whatever block it comes from.
 *
 * What a sequence looks like and how it moves is not here: one partial prints
 * it (partials/media-sequence.php), one stylesheet shapes it
 * (assets/css/media-sequence.css) and one script runs it
 * (assets/js/media-sequence.js). The first users are the Paginakop's pictures
 * and the Mediabanner's pictures and videos (CONTENT-BLOCKS.md, "Mediareeks");
 * each keeps its FIRST item where it always had it, so a block with one
 * picture is exactly what it was.
 *
 * Core and media only: it names no block, no page and no table.
 */
final class MediaSequence
{
    /** How one item gives way to the next. The first is the default. */
    public const TRANSITIONS = ['fade', 'slide', 'none'];

    public const DEFAULT_TRANSITION = 'fade';

    /** How many whole seconds a picture stays, 1 to 10. A video plays to its end. */
    public const DURATIONS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

    public const DEFAULT_DURATION = 5;

    /** Which buttons a visitor gets to move through the sequence. The first is the default. */
    public const CONTROLS = ['both', 'arrows', 'dots', 'none'];

    public const DEFAULT_CONTROLS = 'both';

    /** The most items one sequence holds, its first included. */
    public const MAX_ITEMS = 12;

    /** A stored transition, or the default for anything this class does not know. */
    public static function transition(mixed $stored): string
    {
        return is_string($stored) && in_array($stored, self::TRANSITIONS, true) ? $stored : self::DEFAULT_TRANSITION;
    }

    /** A stored duration in seconds, or the default for anything that is not one of DURATIONS. */
    public static function duration(mixed $stored): int
    {
        $seconds = is_int($stored) || (is_string($stored) && ctype_digit($stored)) ? (int) $stored : 0;

        return in_array($seconds, self::DURATIONS, true) ? $seconds : self::DEFAULT_DURATION;
    }

    /** Stored controls, or the default for anything this class does not know. */
    public static function controls(mixed $stored): string
    {
        return is_string($stored) && in_array($stored, self::CONTROLS, true) ? $stored : self::DEFAULT_CONTROLS;
    }

    /** Whether controls show the arrows at the sides. */
    public static function hasArrows(string $controls): bool
    {
        return in_array($controls, ['both', 'arrows'], true);
    }

    /** Whether controls show the dots at the bottom. */
    public static function hasDots(string $controls): bool
    {
        return in_array($controls, ['both', 'dots'], true);
    }

    /**
     * The media ids of a posted list, in their order: every entry a
     * `media:<id>` token, the same id once. Null when the request holds
     * anything else — a token of another shape, an array inside, more than
     * MAX_ITEMS entries — so a crafted list is refused, never half read.
     * Whether an id names an item of the right kind is the caller's check.
     *
     * @return list<int>|null
     */
    public static function idsFromTokens(mixed $posted): ?array
    {
        if ($posted === null || $posted === '') {
            return [];
        }

        if (!is_array($posted) || count($posted) > self::MAX_ITEMS) {
            return null;
        }

        $ids = [];
        foreach ($posted as $token) {
            if (!is_string($token) || preg_match('/^media:([1-9][0-9]{0,9})$/', $token, $match) !== 1) {
                return null;
            }

            $id = (int) $match[1];
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * One slide as the partial prints it: a picture or a video of the
     * library, its public path and what a picture needs for its <img>. The
     * alt text is the caller's decision (the library's, a block's own, or ''
     * for a picture that is only decoration).
     *
     * @return array{kind: string, src: string, mime: string, alt: string, width: int|null, height: int|null}
     */
    public static function slide(MediaItem $item, string $alt = ''): array
    {
        $isVideo = $item->isVideo();

        return [
            'kind' => $isVideo ? MediaType::VIDEO : MediaType::IMAGE,
            'src' => $item->publicPath(),
            'mime' => $isVideo ? strtolower($item->mimeType) : '',
            'alt' => $isVideo ? '' : trim($alt),
            'width' => !$isVideo && $item->hasDimensions() ? $item->width : null,
            'height' => !$isVideo && $item->hasDimensions() ? $item->height : null,
        ];
    }
}
