<?php

declare(strict_types=1);

namespace App\Service\OrderFields;

use App\Service\Language\SiteText;

/**
 * What an "Afbeelding uploaden" question accepts (Shop Admin UX & Order
 * Fields 2.0, MODULES.md "Bestelvelden"). One place for every limit, read by
 * the validator, the upload endpoint, the product page and the editor.
 *
 * FORMATS: JPEG, PNG and WebP — raster pictures PHP's GD can really decode
 * here, which is what the validator does with every upload. No SVG (it can
 * carry script, and a customer photo never needs it), no GIF, and no
 * HEIC/HEIF/AVIF: this server's GD cannot read them, so they would be files
 * nobody can check. A phone that shoots HEIC converts to JPEG when a browser
 * picks the photo for an upload.
 *
 * SIZE: the question's own choice of 2, 5 or 10 MB (DEFAULT_MB when it made
 * none), but NEVER more than PHP itself accepts (upload_max_filesize, and
 * post_max_size with room for the rest of the request): effectiveMaxBytes()
 * is what is checked and what the page says.
 *
 * PIXELS: at most MAX_DIMENSION per side and MAX_PIXELS in all, so a small
 * file that claims an enormous picture (a decompression bomb) is refused
 * before it is decoded. 40 megapixels is more than a phone's normal photo;
 * App\Service\ImageOptimizer applies the same ceiling again while decoding.
 *
 * LIFETIME: a temporary upload lives TTL_HOURS — as long as a personalized
 * picture waits for its order (PersonalizationRules::UNCLAIMED_UPLOAD_TTL_HOURS),
 * so a cart kept for a few days still orders.
 */
final class OrderFieldUploadPolicy
{
    /** @var list<int> the sizes an editor can choose, in MB */
    public const SIZE_CHOICES_MB = [2, 5, 10];
    public const DEFAULT_MB = 10;

    /** @var array<int, array{0: string, 1: string}> IMAGETYPE_* => [extension, MIME type] */
    public const FORMATS = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    public const MAX_DIMENSION = 12000;
    public const MAX_PIXELS = 40_000_000;

    public const TTL_HOURS = 72;

    /**
     * The ceiling on all TEMPORARY pictures together: whatever the per-visitor
     * rate limit lets through (and an IPv6 visitor can change address), a
     * shop never keeps more than this of pictures nobody ordered. Above it a
     * new upload is refused with "try again later" until the sweep made room.
     */
    public const MAX_TEMPORARY_BYTES = 2 * 1024 * 1024 * 1024;

    /** Room left in post_max_size for the rest of a multipart request. */
    private const REQUEST_OVERHEAD_BYTES = 64 * 1024;

    public static function isSizeChoice(mixed $mb): bool
    {
        return is_int($mb) && in_array($mb, self::SIZE_CHOICES_MB, true);
    }

    /** The limit a question asks for, in MB: its own choice or the default. */
    public static function chosenMb(?int $own): int
    {
        return $own !== null && self::isSizeChoice($own) ? $own : self::DEFAULT_MB;
    }

    /** The limit that is really checked: the question's, within what PHP accepts. */
    public static function effectiveMaxBytes(?int $ownMb): int
    {
        $wanted = self::chosenMb($ownMb) * 1024 * 1024;

        return max(1, min($wanted, self::serverMaxBytes()));
    }

    /** What PHP accepts for one uploaded file on this server. */
    public static function serverMaxBytes(): int
    {
        $limits = [];
        $upload = self::iniBytes((string) ini_get('upload_max_filesize'));
        if ($upload > 0) {
            $limits[] = $upload;
        }
        $post = self::iniBytes((string) ini_get('post_max_size'));
        if ($post > 0) {
            $limits[] = max(1, $post - self::REQUEST_OVERHEAD_BYTES);
        }

        return $limits === [] ? PHP_INT_MAX : min($limits);
    }

    /** "10 MB", "2,5 MB": a limit as the customer reads it. */
    public static function formatBytes(int $bytes, string $languageCode): string
    {
        $mb = round($bytes / (1024 * 1024), 1);
        $number = fmod($mb, 1.0) === 0.0 ? (string) (int) $mb : number_format($mb, 1, $languageCode === 'nl' ? ',' : '.', '');

        return $number . ' MB';
    }

    /** "JPG, PNG of WebP": the formats as the customer reads them. */
    public static function formatsText(string $languageCode): string
    {
        return SiteText::pick(['nl' => 'JPG, PNG of WebP', 'en' => 'JPG, PNG or WebP'], $languageCode);
    }

    /** The value of the file input's accept attribute (a hint only; the server decides). */
    public static function acceptAttribute(): string
    {
        return implode(',', array_column(self::FORMATS, 1));
    }

    /** A token as the browser holds it: 64 hex characters (256 random bits). */
    public static function isToken(mixed $token): bool
    {
        return is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token) === 1;
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** What the database keeps of a token. */
    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
