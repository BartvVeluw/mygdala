<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * One file a visitor sent with a submission, after App\Service\Forms\
 * FormUploadInspector accepted it and before anything was written: it still
 * sits in PHP's temporary upload directory.
 *
 * Everything here was decided by the server. The type comes from the file's
 * bytes (App\Service\Forms\FormFileTypes), the size from the file on disk,
 * and the original name is only ever a label: cleaned of paths, control and
 * direction characters, and never used to name, place or serve the file.
 */
final class FormUpload
{
    public function __construct(
        /** The field this file was sent for. */
        public readonly string $fieldKey,
        /** PHP's temporary file; only ever moved by ContactAttachmentStorage::store(). */
        public readonly string $tmpPath,
        /** For display and the download's filename, never for storage. */
        public readonly string $originalName,
        /** A FormFileTypes key. */
        public readonly string $typeKey,
        public readonly int $size,
        public readonly string $sha256,
    ) {
    }

    public function mime(): string
    {
        return FormFileTypes::mime($this->typeKey);
    }

    /** The extension it is stored under: the type's own, never the visitor's. */
    public function storedExtension(): string
    {
        return FormFileTypes::storedExtension($this->typeKey);
    }

    /**
     * What a submission records as the field's answer, and what the
     * notification lists: the name the visitor gave it, and its size.
     */
    public function answer(): string
    {
        return $this->originalName . ' (' . self::sizeLabel($this->size) . ')';
    }

    /** "240 kB", "3,4 MB". */
    public static function sizeLabel(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return number_format(max(1, (int) round($bytes / 1024)), 0, ',', '.') . ' kB';
        }

        return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
    }
}
