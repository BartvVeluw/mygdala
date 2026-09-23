<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Which web video a file is, decided by its first bytes and never by its
 * name or the Content-Type a browser sent. Shared by the Media Library
 * (App\Service\Media\MediaUploader) and the Homepage Hero's own video field
 * (App\Service\SectionVideoUploader), so the two can never disagree about
 * what counts as a video.
 *
 * Only the two formats every current browser plays in a <video> without a
 * plugin: MP4 (an ISO-BMFF file, `ftyp` box at offset 4) and WebM (a
 * Matroska/EBML file, fixed four-byte magic). Nothing is transcoded, so a
 * format a browser cannot play is refused rather than stored.
 *
 * The `ftyp` box also starts a QuickTime .mov and every HEIC/AVIF photo. Its
 * major brand tells them apart, and those brands are refused: a .mov is not
 * reliably playable outside Safari, and a HEIC photo is not a video at all.
 */
final class VideoFormat
{
    public const MP4 = 'mp4';
    public const WEBM = 'webm';

    /** Each format, and the MIME type it is stored and served as. */
    public const MIME = [
        self::MP4 => 'video/mp4',
        self::WEBM => 'video/webm',
    ];

    /** How many leading bytes detect() needs. */
    public const HEADER_BYTES = 12;

    /** ISO-BMFF major brands that are not a browser-playable video. */
    private const REFUSED_BRANDS = ['qt  ', 'heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'mif1', 'msf1', 'avif', 'avis', 'crx '];

    /** The format of these leading bytes, or null when they are neither. */
    public static function detect(string $header): ?string
    {
        if (strncmp($header, "\x1A\x45\xDF\xA3", 4) === 0) {
            return self::WEBM;
        }

        // A box of some size, then the ASCII type 'ftyp', then the major brand.
        if (strlen($header) >= 12 && substr($header, 4, 4) === 'ftyp') {
            return in_array(substr($header, 8, 4), self::REFUSED_BRANDS, true) ? null : self::MP4;
        }

        return null;
    }

    /** detect() for a file on disk; null for one that cannot be read. */
    public static function ofFile(string $path): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $header = fread($handle, self::HEADER_BYTES);
        fclose($handle);

        return is_string($header) ? self::detect($header) : null;
    }
}
