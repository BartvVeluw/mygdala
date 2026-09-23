<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * The kinds of file the Media Library holds, as a closed list: what the type
 * filter above the grid offers, and the one place that decides which
 * `mime_type` belongs to which kind. MEDIA.md, "Zoeken en filteren".
 *
 * TWO KINDS, AND ONLY THE ONES THE UPLOADER TAKES. Images (raster and SVG)
 * and web video (MP4, WebM), because those are what
 * App\Service\Media\MediaUploader accepts (MEDIA.md, "Wat de bibliotheek
 * aanneemt"). A filter for audio or documents would be a promise the upload
 * cannot keep. The day the uploader learns a third kind, that kind gets a
 * line here, and the screen and the picker offer it without a change of
 * their own — which is the reason this is a list at all.
 *
 * The same list decides what a picker offers: a field that takes an image
 * never lists a video, and the other way round (admin/_media_picker.php).
 *
 * PICKER FILTERS narrow a kind further for one kind of field, without making
 * the kind itself narrower. There is one today: SOCIAL_IMAGE, the share image
 * (og:image) of a page, a post or the site. Social networks do not render an
 * SVG preview, so that field offers the raster formats only — the formats a
 * share image has always had in this project (SectionImageUploader for the
 * Shop's own share images). Every other image field keeps offering SVG.
 * A filter is not a kind: the library's own type filter does not list it.
 *
 * Derived from `mime_type`, which the library already stores from the file's
 * own header, so a kind needs no column and no backfill. A row whose type is
 * unknown (an adopted file with an extension the adoption did not recognise)
 * belongs to no kind: it shows under "everything" and under nothing else.
 */
final class MediaType
{
    public const IMAGE = 'image';
    public const VIDEO = 'video';

    /** A picker filter (see above): an image, raster formats only. */
    public const SOCIAL_IMAGE = 'social_image';

    /** Each kind, and how every MIME type that belongs to it begins. */
    private const MIME_PREFIXES = [
        self::IMAGE => 'image/',
        self::VIDEO => 'video/',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::MIME_PREFIXES);
    }

    public static function isKnown(string $type): bool
    {
        return isset(self::MIME_PREFIXES[$type]);
    }

    /**
     * How every MIME type of a kind begins ("image/"), or null for a kind this
     * list does not have — which a caller treats as "no filter", never as
     * "nothing matches".
     */
    public static function mimePrefix(string $type): ?string
    {
        return self::MIME_PREFIXES[$type] ?? null;
    }

    /** Whether a picker may ask for this: a kind, or a filter on one. */
    public static function isPickerFilter(string $filter): bool
    {
        return self::isKnown($filter) || $filter === self::SOCIAL_IMAGE;
    }

    /** The kind a picker filter narrows, or null for an unknown one. */
    public static function kindOfFilter(string $filter): ?string
    {
        return match (true) {
            self::isKnown($filter) => $filter,
            $filter === self::SOCIAL_IMAGE => self::IMAGE,
            default => null,
        };
    }

    /**
     * The exact MIME types a filter allows, or null when every MIME type of
     * its kind is allowed.
     *
     * @return list<string>|null
     */
    public static function mimesOfFilter(string $filter): ?array
    {
        return $filter === self::SOCIAL_IMAGE ? array_values(MediaUploader::MIME_FOR_TYPE) : null;
    }

    /** Whether a stored item with this MIME type is an answer to a picker filter. */
    public static function filterAccepts(string $filter, string $mimeType): bool
    {
        $kind = self::kindOfFilter($filter);

        if ($kind === null || self::ofMime($mimeType) !== $kind) {
            return false;
        }

        $mimes = self::mimesOfFilter($filter);

        return $mimes === null || in_array(strtolower($mimeType), $mimes, true);
    }

    /** The kind a stored MIME type belongs to, or null for one no kind claims. */
    public static function ofMime(string $mimeType): ?string
    {
        foreach (self::MIME_PREFIXES as $type => $prefix) {
            if (str_starts_with(strtolower($mimeType), $prefix)) {
                return $type;
            }
        }

        return null;
    }
}
