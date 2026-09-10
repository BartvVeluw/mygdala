<?php

namespace App\Service;

/**
 * Validates and stores uploaded product photos. Files are renamed to a
 * random, safe filename (the client-supplied name/extension is never
 * trusted) and written to assets/images/products/ — the only folder this
 * service ever writes to or deletes from, so an image replace/delete can
 * never touch the site's existing shared images (assets/images/*.webp used
 * by the seeded test catalog and the rest of the site).
 */
class ProductImageUploader
{
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    private const ALLOWED_TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    ];

    private const PUBLIC_PREFIX = 'assets/images/products/';

    private string $uploadDir;

    public function __construct()
    {
        $this->uploadDir = dirname(__DIR__, 2) . '/assets/images/products/';

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file one entry of $_FILES
     * @return string the image_path to store on the product (relative, web-servable)
     * @throws \RuntimeException with a Dutch, user-facing message on invalid input
     */
    public function store(array $file): string
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new \RuntimeException('Geen bestand geselecteerd.');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Uploaden van de afbeelding is mislukt. Probeer het opnieuw.');
        }

        $tmpName = $file['tmp_name'] ?? '';

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \RuntimeException('Ongeldige upload.');
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new \RuntimeException('Afbeelding is te groot (max. 5 MB).');
        }

        // Never trust the client-supplied extension/MIME type — read the
        // actual image header instead. Also rejects non-image files outright.
        $info = @getimagesize($tmpName);

        if ($info === false || !isset(self::ALLOWED_TYPES[$info[2]])) {
            throw new \RuntimeException('Alleen JPG, PNG, WEBP of GIF afbeeldingen zijn toegestaan.');
        }

        $extension = self::ALLOWED_TYPES[$info[2]];
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $this->uploadDir . $filename;

        if (!move_uploaded_file($tmpName, $destination)) {
            throw new \RuntimeException('Afbeelding kon niet worden opgeslagen.');
        }

        chmod($destination, 0644);

        return self::PUBLIC_PREFIX . $filename;
    }

    /**
     * Deletes a previously admin-uploaded product image, but only when it
     * actually lives in the products upload folder — a no-op for anything
     * else (e.g. the seeded test products' assets/images/*.webp photos),
     * so replacing/removing a photo can never remove a shared site asset.
     */
    public function delete(?string $imagePath): void
    {
        if ($imagePath === null || !str_starts_with($imagePath, self::PUBLIC_PREFIX)) {
            return;
        }

        // basename() strips any directory traversal component, so this can
        // only ever resolve to a file directly inside uploadDir.
        $path = $this->uploadDir . basename($imagePath);

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
