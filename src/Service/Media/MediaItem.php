<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Service\AppUrl;

/**
 * One media item, as everything outside the Media Library sees it.
 *
 * This is the answer to "how does a template get a URL out of a media item"
 * (MEDIA.md): it asks this object. No partial concatenates a filesystem path
 * with a slash any more, no template guesses whether the stored value has a
 * leading one, and nothing outside this class needs to know that the library
 * stores paths WITHOUT a leading slash while the site serves them WITH one.
 *
 * Read-only on purpose. A media item is created and changed through
 * App\Service\Media\MediaService; this object is what you render.
 */
final class MediaItem
{
    private function __construct(
        public readonly int $id,
        /** Stored form: root-relative, no leading slash. */
        public readonly string $path,
        public readonly ?string $thumbnailPath,
        public readonly string $originalFilename,
        /** The name an editor gave it; '' for a row that has none (see displayName()). */
        public readonly string $displayName,
        public readonly string $mimeType,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?int $fileSize,
        public readonly string $altText,
        public readonly ?string $checksum,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row a `media` row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            path: ltrim((string) ($row['path'] ?? ''), '/'),
            thumbnailPath: self::nullableString($row['thumbnail_path'] ?? null),
            originalFilename: (string) ($row['original_filename'] ?? ''),
            displayName: (string) ($row['display_name'] ?? ''),
            mimeType: (string) ($row['mime_type'] ?? ''),
            width: self::nullableInt($row['width'] ?? null),
            height: self::nullableInt($row['height'] ?? null),
            fileSize: self::nullableInt($row['file_size'] ?? null),
            altText: (string) ($row['alt_text'] ?? ''),
            checksum: self::nullableString($row['checksum'] ?? null),
            createdAt: self::nullableString($row['created_at'] ?? null),
            updatedAt: self::nullableString($row['updated_at'] ?? null),
        );
    }

    /**
     * The URL an ordinary <img src> uses: root-relative with exactly one
     * leading slash, the convention every existing template in this project
     * already follows.
     */
    public function publicPath(): string
    {
        return '/' . $this->path;
    }

    /**
     * The small preview to show in the admin, or the image itself when this
     * item has no generated derivative — which is the case for every legacy
     * file adopted in place. The admin sizes it with CSS either way, so a
     * missing thumbnail costs bandwidth, never correctness.
     */
    public function displayPath(): string
    {
        return '/' . ($this->thumbnailPath ?? $this->path);
    }

    /**
     * The absolute URL, for the places that genuinely need one — og:image
     * and JSON-LD. Resolved against APP_URL through App\Service\AppUrl, never
     * against the request's Host header.
     */
    public function absoluteUrl(): string
    {
        return AppUrl::asset($this->path);
    }

    /** Where the file is on disk, whether or not it is actually there. */
    public function absolutePath(): string
    {
        return dirname(__DIR__, 3) . '/' . $this->path;
    }

    /** Whether the file this row describes is still on disk. */
    public function fileExists(): bool
    {
        return is_file($this->absolutePath());
    }

    /**
     * Whether both dimensions are known, so a caller can emit width/height
     * attributes and save the browser a reflow. Unknown for an adopted file
     * that was already missing, and for anything getimagesize() could not
     * read.
     */
    public function hasDimensions(): bool
    {
        return $this->width !== null && $this->width > 0
            && $this->height !== null && $this->height > 0;
    }

    /**
     * The name to show a human: the one the item has in the library (MEDIA.md,
     * "Bestandsnaam"). A row without one — seeded by a test, or written by a
     * path that did not choose one — falls back on the original filename, and
     * then on the stored file's own name.
     */
    public function displayName(): string
    {
        if ($this->displayName !== '') {
            return $this->displayName;
        }

        return $this->originalFilename !== '' ? $this->originalFilename : basename($this->path);
    }

    /**
     * The kind of file as an editor calls it — "JPG", "PNG", "SVG" — taken from
     * the stored MIME type, which the library read from the file itself, and
     * from the stored file's extension when that type is unknown. Never from
     * the name, which an editor can change.
     */
    public function typeLabel(): string
    {
        $slash = strrpos($this->mimeType, '/');
        $subtype = $slash === false ? '' : strtolower(substr($this->mimeType, $slash + 1));

        return match ($subtype) {
            'jpeg', 'pjpeg' => 'JPG',
            'svg+xml' => 'SVG',
            'x-icon', 'vnd.microsoft.icon' => 'ICO',
            '' => strtoupper(MediaFilename::extension($this->path)),
            default => strtoupper($subtype),
        };
    }

    /**
     * The extension a new name keeps when the item is renamed: the one its
     * name has now, or the stored file's when the name has none. Renaming
     * changes what comes before it and never the extension itself.
     */
    public function nameExtension(): string
    {
        $extension = MediaFilename::extension($this->displayName());

        return $extension !== '' ? $extension : MediaFilename::extension($this->path);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
