<?php

namespace App\Service;

/**
 * Validates an optional file attached to the contact/quote request form.
 * Never trusts the client-supplied filename/MIME type: images are verified
 * via getimagesize(), PDFs via their magic bytes. Returns the still-in-tmp
 * upload plus everything the caller needs to both persist it
 * (App\Service\ContactAttachmentStorage) and email it
 * (App\Service\Mailer::send() attachments) — this class itself never writes
 * or sends anything.
 */
class ContactAttachmentValidator
{
    private const MAX_BYTES = 8 * 1024 * 1024; // 8 MB

    private const ALLOWED_IMAGE_TYPES = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
        IMAGETYPE_GIF => ['gif', 'image/gif'],
    ];

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file one entry of $_FILES
     * @return array{tmp_path:string,extension:string,mime:string,mail_name:string,original_filename:string,size:int}|null
     *         null if no file was submitted
     * @throws \RuntimeException with a user-facing message on an invalid/unsafe file
     */
    public function validate(array $file): ?array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Uploaden van het bestand is mislukt. Probeer het opnieuw.');
        }

        $tmpName = $file['tmp_name'] ?? '';

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \RuntimeException('Ongeldige upload.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException('Bestand is te groot (max. 8 MB).');
        }

        $imageInfo = @getimagesize($tmpName);
        if ($imageInfo !== false && isset(self::ALLOWED_IMAGE_TYPES[$imageInfo[2]])) {
            [$extension, $mime] = self::ALLOWED_IMAGE_TYPES[$imageInfo[2]];
            return $this->result($tmpName, $extension, $mime, $file['name'] ?? '', $size);
        }

        // Not a recognised image — allow it through only if it's genuinely a
        // PDF (checked via its magic bytes, never the client-supplied
        // extension/MIME type).
        $handle = fopen($tmpName, 'rb');
        $header = $handle !== false ? fread($handle, 5) : '';
        if ($handle !== false) {
            fclose($handle);
        }

        if ($header === '%PDF-') {
            return $this->result($tmpName, 'pdf', 'application/pdf', $file['name'] ?? '', $size);
        }

        throw new \RuntimeException('Alleen JPG, PNG, WEBP, GIF of PDF-bestanden zijn toegestaan.');
    }

    /**
     * @return array{tmp_path:string,extension:string,mime:string,mail_name:string,original_filename:string,size:int}
     */
    private function result(string $tmpPath, string $extension, string $mime, mixed $clientName, int $size): array
    {
        return [
            'tmp_path' => $tmpPath,
            'extension' => $extension,
            'mime' => $mime,
            // Fixed, generic name for the mail attachment — never the
            // client-supplied filename (unchanged from before this class
            // also started reporting the original filename separately).
            'mail_name' => 'bijlage.' . $extension,
            'original_filename' => self::sanitizeOriginalFilename($clientName, $extension),
            'size' => $size,
        ];
    }

    /**
     * The original filename is stored for display only (contact_request_attachments.original_filename)
     * — it never drives storage location, MIME handling, or execution. Strips
     * any path component and control characters, and falls back to the
     * generic mail_name if nothing usable is left.
     */
    private static function sanitizeOriginalFilename(mixed $name, string $extension): string
    {
        $name = is_string($name) ? basename($name) : '';
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '') {
            return 'bijlage.' . $extension;
        }

        return mb_strlen($name) > 255 ? mb_substr($name, 0, 255) : $name;
    }
}
