<?php

declare(strict_types=1);

namespace App\Service\Personalization;

/**
 * Validates and stores the DEDICATED preview image of one personalization
 * view — the picture the customer's engraving is composed on.
 *
 * ## Why this is not App\Service\ProductImageUploader
 *
 * A personalization preview and a product photo are different kinds of
 * image with different jobs, and mixing them caused the exact problem this
 * class exists to end:
 *
 *   - a PRODUCT photo is marketing. It may already show an engraving, a
 *     styled mock-up, a lifestyle scene or artwork. Composing a customer's
 *     text on top of one produces nonsense.
 *   - a PREVIEW image is a canvas. Every zone on it is stored as a
 *     PERCENTAGE OF THIS IMAGE, so it must be a stable, deliberately chosen
 *     picture that changes only when the owner replaces it here.
 *
 * They therefore live in different folders — assets/images/personalization/
 * rather than assets/images/products/ — and neither uploader can touch the
 * other's files: delete() is a no-op outside its own prefix. There is no
 * fallback from one to the other anywhere in the codebase: a view with no
 * preview image renders no personalization at all and says so in the CMS,
 * rather than silently borrowing the first gallery photo.
 *
 * The client-supplied name and MIME type are never trusted; the image type is
 * read from the file's own header and the stored filename is generated here.
 */
class PersonalizationPreviewImageUploader
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * The same four raster formats the product gallery accepts. A preview is
     * a photograph or a flat render of the product, so nothing exotic is
     * needed — and SVG is deliberately absent for the same reason it is
     * absent from customer uploads (see PersonalizationRules).
     *
     * @var array<int, string> IMAGETYPE_* => extension
     */
    private const ALLOWED_TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    ];

    public const PUBLIC_PREFIX = 'assets/images/personalization/';

    private string $uploadDir;

    public function __construct(?string $uploadDir = null)
    {
        $this->uploadDir = $uploadDir !== null
            ? rtrim($uploadDir, '/\\') . '/'
            : dirname(__DIR__, 3) . '/' . self::PUBLIC_PREFIX;

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file one entry of $_FILES
     * @return string the preview_image_path to store on the view (relative, web-servable)
     * @throws \RuntimeException with a Dutch, admin-facing message
     */
    public function store(array $file): string
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new \RuntimeException('Geen afbeelding geselecteerd.');
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \RuntimeException('De voorbeeldafbeelding is te groot (max. ' . self::maxMegabytes() . ' MB).');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Uploaden van de voorbeeldafbeelding is mislukt. Probeer het opnieuw.');
        }

        $tmpName = $file['tmp_name'] ?? '';

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \RuntimeException('Ongeldige upload.');
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new \RuntimeException('De voorbeeldafbeelding is te groot (max. ' . self::maxMegabytes() . ' MB).');
        }

        // Read the actual image header rather than believing the extension or
        // the browser's Content-Type; also rejects non-images outright.
        $info = @getimagesize($tmpName);

        if ($info === false || !isset(self::ALLOWED_TYPES[$info[2]])) {
            throw new \RuntimeException('Alleen JPG, PNG, WEBP of GIF afbeeldingen zijn toegestaan.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_TYPES[$info[2]];
        $destination = $this->uploadDir . $filename;

        // Suppressed on purpose: a write that fails (a folder the web user
        // cannot write to, a full disk) must surface as the clean message
        // below, not as a raw PHP warning printed before any header —
        // which would break the redirect as well as leaking a server path.
        if (!@move_uploaded_file($tmpName, $destination)) {
            throw new \RuntimeException('De voorbeeldafbeelding kon niet worden opgeslagen.');
        }

        chmod($destination, 0644);

        return self::PUBLIC_PREFIX . $filename;
    }

    /**
     * Deletes a preview image, but ONLY when it lives in the personalization
     * folder. A path under assets/images/products/ is left alone on purpose:
     * a Phase 1/2 view could still point at one, and that file may be a real
     * product photo and is certainly what a historical order's snapshot
     * refers to.
     */
    public function delete(?string $imagePath): void
    {
        if ($imagePath === null || !str_starts_with($imagePath, self::PUBLIC_PREFIX)) {
            return;
        }

        $path = $this->uploadDir . basename($imagePath);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function maxMegabytes(): int
    {
        return (int) round(self::MAX_BYTES / (1024 * 1024));
    }
}
