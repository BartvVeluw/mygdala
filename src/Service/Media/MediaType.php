<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * The kinds of file the Media Library holds, as a closed list: what the type
 * filter above the grid offers, and the one place that decides which
 * `mime_type` belongs to which kind. MEDIA.md, "Zoeken en filteren".
 *
 * ONE KIND TODAY, ON PURPOSE. The library accepts images and nothing else
 * (App\Service\Media\MediaUploader; MEDIA.md, "Bewust niet gebouwd"), so this
 * list says exactly that. A filter for video, audio or documents would be a
 * promise the upload cannot keep. The day the uploader learns a second kind,
 * that kind gets a line here, and the screen offers it without a change of
 * its own — which is the reason this is a list at all.
 *
 * Derived from `mime_type`, which the library already stores from the file's
 * own header, so a kind needs no column and no backfill. A row whose type is
 * unknown (an adopted file with an extension the adoption did not recognise)
 * belongs to no kind: it shows under "everything" and under nothing else.
 */
final class MediaType
{
    public const IMAGE = 'image';

    /** Each kind, and how every MIME type that belongs to it begins. */
    private const MIME_PREFIXES = [
        self::IMAGE => 'image/',
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
}
