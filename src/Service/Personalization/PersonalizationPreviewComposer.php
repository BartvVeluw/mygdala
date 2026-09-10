<?php

declare(strict_types=1);

namespace App\Service\Personalization;

/**
 * Validates the COMPOSED preview PNG the browser posts — the picture of the
 * finished product exactly as the customer saw it, with their text in their
 * font and colour, their uploaded image, and everything at the position,
 * scale and rotation they chose.
 *
 * ## Why the browser composes it and the server does not
 *
 * The server has all the structured data and could draw its own version, but
 * it cannot draw THE CUSTOMER'S version: the text is set in a webfont the
 * browser downloaded (a WOFF2 the owner uploaded, or a Google-hosted family
 * this site loads), and GD can only use a local TTF/OTF. A server-drawn
 * picture would therefore differ from what the customer approved in exactly
 * the cases where the difference matters most. So the browser rasterises what
 * it actually rendered, and this class makes sure what arrives is a real
 * image and nothing else.
 *
 * ## What "validated" means here
 *
 * The posted bytes are never trusted and never stored as received:
 *
 *   1. it must be a real PNG according to getimagesize(), not according to
 *      its filename or its Content-Type;
 *   2. it must be within the size and dimension ceilings below;
 *   3. it is DECODED and RE-ENCODED through GD, so the bytes that reach the
 *      disk are pixels this server produced. That is what strips any EXIF,
 *      any appended payload and any polyglot construction — the same
 *      reasoning PersonalizationUploadValidator applies to the customer's own
 *      upload.
 *
 * A snapshot is SUPPLEMENTARY. It never replaces the customer's original
 * upload, and the structured personalization stays the source of truth: an
 * order with no snapshot (every historical one, and any browser where this
 * step failed) is still complete and still renders its reconstruction in the
 * CMS.
 */
class PersonalizationPreviewComposer
{
    /** A composed preview is a flat raster of one product photo; 8 MB is generous. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** Wide enough to be useful in production, small enough to bound the work. */
    public const MAX_DIMENSION = 4000;
    public const MIN_DIMENSION = 64;

    /**
     * What the browser is asked to render at. Not enforced — the checks above
     * are — but published so the client and the server agree on the intent.
     */
    public const TARGET_WIDTH = 1400;

    /**
     * @param array{tmp_name?:string,error?:int,size?:int}|null $file one entry of $_FILES
     * @param bool $requireUploadedFile false only for a file that did not
     *        arrive through PHP's upload machinery (tests, seeds) — the
     *        production path keeps its is_uploaded_file() guarantee, exactly
     *        like App\Service\Personalization\PersonalizationFontUploader.
     * @return array{bytes: string, width: int, height: int, size: int}
     * @throws \RuntimeException with a customer-facing (Dutch) message
     */
    public function validate(?array $file, bool $requireUploadedFile = true): array
    {
        if (!is_array($file)) {
            throw new \RuntimeException('Geen voorbeeld ontvangen.');
        }

        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \RuntimeException('Het voorbeeld is te groot.');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Het voorbeeld kon niet worden verwerkt.');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');

        if ($tmpPath === '' || !is_file($tmpPath) || ($requireUploadedFile && !is_uploaded_file($tmpPath))) {
            throw new \RuntimeException('Ongeldige upload.');
        }

        $size = (int) ($file['size'] ?? filesize($tmpPath));

        if ($size <= 0) {
            throw new \RuntimeException('Het voorbeeld is leeg.');
        }

        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException('Het voorbeeld is te groot.');
        }

        // The file's own header decides what it is — never its name, never
        // the browser's Content-Type.
        $info = @getimagesize($tmpPath);

        if ($info === false || ($info[2] ?? null) !== IMAGETYPE_PNG) {
            throw new \RuntimeException('Het voorbeeld moet een PNG zijn.');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];

        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION) {
            throw new \RuntimeException('Het voorbeeld is te klein.');
        }

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new \RuntimeException('Het voorbeeld is te groot.');
        }

        return ['bytes' => $this->reencode($tmpPath, $width, $height)] + [
            'width' => $width,
            'height' => $height,
            'size' => $size,
        ];
    }

    /**
     * Decodes and re-encodes the PNG through GD. Alpha is preserved, because
     * a composed preview of a product photographed on a transparent
     * background is a legitimate thing to receive.
     *
     * @throws \RuntimeException
     */
    private function reencode(string $tmpPath, int $width, int $height): string
    {
        if (!function_exists('imagecreatefrompng')) {
            throw new \RuntimeException('Voorbeelden kunnen op deze server niet worden verwerkt.');
        }

        $source = @imagecreatefrompng($tmpPath);

        if ($source === false) {
            throw new \RuntimeException('Het voorbeeld kon niet worden gelezen.');
        }

        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            imagedestroy($source);
            throw new \RuntimeException('Het voorbeeld kon niet worden verwerkt.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
        imagedestroy($source);

        ob_start();
        $ok = imagepng($canvas, null, 6);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        if (!$ok || $bytes === '') {
            throw new \RuntimeException('Het voorbeeld kon niet worden verwerkt.');
        }

        return $bytes;
    }
}
