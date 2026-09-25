<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Service\Language\AdminTranslator;

/**
 * The name a media item shows: what an editor reads on a card, searches for
 * and may change. MEDIA.md, "Naam, bestand en adres".
 *
 * A LABEL, NEVER A PATH. The file on disk keeps the random name
 * App\Service\Media\MediaUploader gave it, and every feature points at the
 * item's id, never at its name (MEDIA.md, "Hoe een feature naar media
 * verwijst"). A name can therefore not break a page, move a file or overwrite
 * one. The rules below keep the label readable and unambiguous; they are not
 * what stands between a request and the filesystem, because nothing here
 * ever reaches it.
 *
 * TWO WAYS IN, on purpose:
 *
 *   - problemWith() is STRICT, for a name a person typed: a rename, or the
 *     name field of the upload queue. An unusable name is refused with a
 *     sentence that says what to change.
 *   - fromClientName() is LENIENT, for the name a file arrived with. Nobody
 *     typed it, so there is nobody to refuse: characters a filename may not
 *     carry are replaced and an empty name gets a neutral one, the same
 *     tolerance MediaUploader::safeOriginalName() already shows.
 *
 * THE EXTENSION IS NOT THE EDITOR'S. A caller decides it — an upload from the
 * type the file really turned out to be, a rename from the item's current
 * name — and compose() puts it back, so "foto" never becomes "foto.exe" by a
 * slip of the keyboard.
 *
 * What is NOT here: whether a name is already taken. That needs the database
 * and lives in App\Service\Media\MediaService.
 */
final class MediaFilename
{
    /**
     * Room for a dot and an extension inside the 255 characters of
     * `media.display_name`.
     */
    public const MAX_BASE_LENGTH = 200;

    /**
     * What no filename on a common desktop system may contain, the two path
     * separators among them. A name is a label here, but an editor who
     * downloads the file expects the name to survive the trip.
     */
    private const FORBIDDEN = ['/', '\\', ':', '*', '?', '"', '<', '>', '|'];

    /** The name a file gets when the one it arrived with has nothing usable left. */
    public const FALLBACK_BASE = 'afbeelding';

    /**
     * The lower-case extension of a name, or '' when it has none. A name that
     * only starts with a dot (".htaccess") has no extension: the dot is part
     * of the name.
     */
    public static function extension(string $name): string
    {
        $dot = strrpos($name, '.');

        if ($dot === false || $dot === 0) {
            return '';
        }

        return strtolower(substr($name, $dot + 1));
    }

    /** The name without its extension. */
    public static function base(string $name): string
    {
        $dot = strrpos($name, '.');

        return $dot === false || $dot === 0 ? $name : substr($name, 0, $dot);
    }

    /**
     * Why a typed name cannot be used, as a sentence for the editor, or null
     * when it can. Leading and trailing spaces do not count against a name;
     * the caller stores it trimmed.
     */
    public static function problemWith(string $base): ?string
    {
        $base = trim($base);

        if ($base === '') {
            return AdminTranslator::trans('media.name.empty');
        }

        if (!mb_check_encoding($base, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/u', $base) === 1) {
            return AdminTranslator::trans('media.name.characters');
        }

        if (strpbrk($base, implode('', self::FORBIDDEN)) !== false) {
            return AdminTranslator::trans('media.name.forbidden');
        }

        if (str_starts_with($base, '.') || str_ends_with($base, '.')) {
            return AdminTranslator::trans('media.name.dot');
        }

        if (mb_strlen($base) > self::MAX_BASE_LENGTH) {
            return AdminTranslator::trans('media.name.long', ['max' => self::MAX_BASE_LENGTH]);
        }

        return null;
    }

    /**
     * A usable base name from the name a browser sent with a file. Never
     * fails: whatever cannot be in a name is replaced or dropped, and a name
     * with nothing left becomes FALLBACK_BASE.
     */
    public static function fromClientName(string $clientName): string
    {
        // Windows browsers can send "C:\Users\...\foto.jpg"; basename() alone
        // would keep the backslashes.
        $name = basename(str_replace('\\', '/', $clientName));

        if (!mb_check_encoding($name, 'UTF-8')) {
            return self::FALLBACK_BASE;
        }

        $base = self::base($name);
        $base = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $base);
        $base = str_replace(self::FORBIDDEN, '-', $base);
        $base = trim((string) preg_replace('/\s+/u', ' ', $base), " .");
        $base = rtrim(mb_substr($base, 0, self::MAX_BASE_LENGTH), " .");

        return $base === '' ? self::FALLBACK_BASE : $base;
    }

    /**
     * A typed base name, with the item's own extension stripped when the
     * editor typed it anyway: "zomer.jpg" for a JPG is "zomer", not
     * "zomer.jpg.jpg". Any other dot stays part of the name.
     */
    public static function withoutExtension(string $base, string $extension): string
    {
        $base = trim($base);
        $suffix = '.' . $extension;

        if ($extension !== '' && strlen($base) > strlen($suffix) && strcasecmp(substr($base, -strlen($suffix)), $suffix) === 0) {
            return rtrim(substr($base, 0, -strlen($suffix)));
        }

        return $base;
    }

    public static function compose(string $base, string $extension): string
    {
        return $extension === '' ? $base : $base . '.' . $extension;
    }
}
