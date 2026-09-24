<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * THE closed list of file types an upload field can accept, and the size
 * limits it can choose from (FORMS.md, "Bestand uploaden").
 *
 * A PRESET, NOT A MIME STRING. The field editor offers these keys as
 * checkboxes; api/admin/update-form-field.php stores nothing else, and the
 * read model drops anything else a row holds. A key decides three things at
 * once, all written here and nowhere else: which extensions a file's own name
 * may carry, what its bytes must turn out to be, and the one MIME type it is
 * stored, mailed and downloaded as.
 *
 * WHAT A FILE IS comes from its bytes (App\Service\Forms\FormUploadInspector):
 * getimagesize() for the images, the `%PDF-` signature for a PDF. The name's
 * extension must ALSO be one of the type's own, so a PDF called `foto.jpg`
 * and a PNG called `scan.pdf` are both refused rather than stored under a
 * name that lies. The client's MIME type is never read.
 *
 * DELIBERATELY NOT HERE, with the reason FORMS.md repeats:
 *
 *   svg    an SVG is a document that can carry script. Stored and downloaded
 *          as an attachment it is harmless in the CMS, but the notification
 *          e-mail hands it to whatever the owner's mail client opens it in,
 *          and sanitizing a visitor's SVG well enough to promise otherwise is
 *          a project of its own. Not in V1.
 *   zip    an archive cannot be inspected without unpacking it, which is
 *          exactly what a decompression bomb wants, and its contents are
 *          whatever the sender chose. Not in V1.
 *   html, php, anything executable, anything office-like: never.
 */
final class FormFileTypes
{
    /**
     * key => extensions the name may carry, the stored/served MIME type, and
     * how the bytes are recognised ('image' = one getimagesize() type,
     * 'pdf' = the PDF signature).
     *
     * @var array<string, array{extensions: list<string>, mime: string, check: string, image_type?: int}>
     */
    private const TYPES = [
        'jpg' => ['extensions' => ['jpg', 'jpeg'], 'mime' => 'image/jpeg', 'check' => 'image', 'image_type' => IMAGETYPE_JPEG],
        'png' => ['extensions' => ['png'], 'mime' => 'image/png', 'check' => 'image', 'image_type' => IMAGETYPE_PNG],
        'webp' => ['extensions' => ['webp'], 'mime' => 'image/webp', 'check' => 'image', 'image_type' => IMAGETYPE_WEBP],
        'gif' => ['extensions' => ['gif'], 'mime' => 'image/gif', 'check' => 'image', 'image_type' => IMAGETYPE_GIF],
        'pdf' => ['extensions' => ['pdf'], 'mime' => 'application/pdf', 'check' => 'pdf'],
    ];

    /** What a new upload field starts with. */
    public const DEFAULT_TYPES = ['jpg', 'png', 'pdf'];

    /**
     * The sizes an editor can choose from, in bytes. The largest is the
     * application's hard ceiling (MAX_BYTES); PHP's own limits can make the
     * real ceiling lower (systemMaxBytes()).
     */
    public const SIZE_CHOICES = [
        1 * 1024 * 1024,
        2 * 1024 * 1024,
        5 * 1024 * 1024,
        8 * 1024 * 1024,
        10 * 1024 * 1024,
    ];

    /** THE hard ceiling per file, whatever a field or php.ini says. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** What a new upload field starts with. */
    public const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * What a request carries besides its files — the answers, the hidden
     * controls and the multipart boundaries — kept free inside post_max_size.
     * The same allowance App\Service\Media\MediaUploader keeps.
     */
    private const REQUEST_OVERHEAD_BYTES = 64 * 1024;

    /** @return list<string> every key, in the order the editor offers them */
    public static function keys(): array
    {
        return array_keys(self::TYPES);
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::TYPES);
    }

    public static function mime(string $key): string
    {
        return self::TYPES[$key]['mime'] ?? 'application/octet-stream';
    }

    /** @return list<string> */
    public static function extensions(string $key): array
    {
        return self::TYPES[$key]['extensions'] ?? [];
    }

    /** The extension a stored file gets: the type's first, never the visitor's. */
    public static function storedExtension(string $key): string
    {
        return self::TYPES[$key]['extensions'][0] ?? 'bin';
    }

    /** How the bytes of this type are recognised: 'image' or 'pdf'. */
    public static function check(string $key): string
    {
        return self::TYPES[$key]['check'] ?? '';
    }

    public static function imageType(string $key): ?int
    {
        return self::TYPES[$key]['image_type'] ?? null;
    }

    /** The name a visitor and an editor read: "JPG", "PNG", "PDF". */
    public static function label(string $key): string
    {
        return strtoupper($key);
    }

    /**
     * The key a MIME type belongs to, for a row stored before this list
     * existed. Null for anything that is not on the list.
     */
    public static function forMime(string $mime): ?string
    {
        foreach (self::TYPES as $key => $type) {
            if ($type['mime'] === $mime) {
                return $key;
            }
        }

        return null;
    }

    /**
     * A stored `file_types` value as keys of this list, in the list's own
     * order and without duplicates. Anything that is not a key is dropped; a
     * value that holds none at all yields the default, so a field never
     * accepts nothing because a row was hand-edited.
     *
     * @return list<string>
     */
    public static function fromStored(mixed $stored): array
    {
        $given = is_string($stored) ? array_map('trim', explode(',', $stored)) : [];
        $keys = array_values(array_filter(self::keys(), static fn (string $key): bool => in_array($key, $given, true)));

        return $keys === [] ? self::DEFAULT_TYPES : $keys;
    }

    /**
     * Keys as they are stored: comma-separated, in the list's order. Returns
     * null when the list holds no known key, so a caller can refuse it.
     *
     * @param list<mixed> $keys
     */
    public static function toStored(array $keys): ?string
    {
        $known = array_values(array_filter(self::keys(), static fn (string $key): bool => in_array($key, $keys, true)));

        return $known === [] ? null : implode(',', $known);
    }

    /** The `accept` attribute: a convenience for the file picker, never a check. */
    public static function acceptAttribute(array $keys): string
    {
        $accept = [];
        foreach ($keys as $key) {
            foreach (self::extensions($key) as $extension) {
                $accept[] = '.' . $extension;
            }
            $accept[] = self::mime($key);
        }

        return implode(',', array_values(array_unique($accept)));
    }

    /** "JPG, PNG of PDF" / "JPG, PNG or PDF". */
    public static function listLabel(array $keys, string $or): string
    {
        $labels = array_map([self::class, 'label'], $keys);
        $last = array_pop($labels);

        return $labels === [] ? (string) $last : implode(', ', $labels) . ' ' . $or . ' ' . $last;
    }

    /**
     * The largest file this installation really accepts: the application's
     * ceiling, or less where PHP is configured stricter (upload_max_filesize,
     * post_max_size). A form that promised 10 MB on a host that takes 8 would
     * let a visitor wait for an upload that can only fail.
     */
    public static function systemMaxBytes(): int
    {
        $limits = [self::MAX_BYTES];

        $perFile = self::iniBytes((string) ini_get('upload_max_filesize'));
        if ($perFile > 0) {
            $limits[] = $perFile;
        }

        $perRequest = self::iniBytes((string) ini_get('post_max_size'));
        if ($perRequest > 0) {
            $limits[] = $perRequest - self::REQUEST_OVERHEAD_BYTES;
        }

        return max(1, min($limits));
    }

    /**
     * The size choices this installation can honour: every preset up to
     * systemMaxBytes(). Never empty — on a host stricter than the smallest
     * preset, the host's own limit is the one choice.
     *
     * @return list<int>
     */
    public static function sizeChoices(): array
    {
        $system = self::systemMaxBytes();
        $choices = array_values(array_filter(self::SIZE_CHOICES, static fn (int $bytes): bool => $bytes <= $system));

        return $choices === [] ? [$system] : $choices;
    }

    public static function isSizeChoice(int $bytes): bool
    {
        return in_array($bytes, self::sizeChoices(), true);
    }

    /**
     * A stored `file_max_bytes` as the limit that really applies: the
     * default for nothing usable, and never above what the installation
     * accepts today, whatever the row says.
     */
    public static function effectiveMaxBytes(mixed $stored): int
    {
        $bytes = is_numeric($stored) ? (int) $stored : 0;
        if ($bytes < 1) {
            $bytes = self::DEFAULT_MAX_BYTES;
        }

        return min($bytes, self::systemMaxBytes());
    }

    /** "5 MB", "0,5 MB". */
    public static function sizeLabel(int $bytes): string
    {
        $megabytes = $bytes / (1024 * 1024);
        $decimals = abs($megabytes - round($megabytes)) < 0.05 ? 0 : 1;

        return number_format((float) $megabytes, $decimals, ',', '.') . ' MB';
    }

    /**
     * php.ini shorthand ("35M", "2G", "512K") as bytes. 0 means "no limit"
     * there, and is returned as 0 so the caller leaves it out.
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !is_numeric(rtrim($value, 'kKmMgG'))) {
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
