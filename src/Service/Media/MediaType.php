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
 * Derived from `mime_type`, which the library already stores from the file's
 * own header, so a kind needs no column and no backfill. A row whose type is
 * unknown (an adopted file with an extension the adoption did not recognise)
 * belongs to no kind: it shows under "everything" and under nothing else.
 */
final class MediaType
{
    public const IMAGE = 'image';
    public const VIDEO = 'video';

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
