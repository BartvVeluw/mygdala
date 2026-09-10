<?php

declare(strict_types=1);

namespace App\Service\Personalization;

use App\Service\ImageOptimizer;

/**
 * Validates one customer-supplied personalization image. Everything a browser
 * says about the file is ignored: the MIME type, the extension and the
 * original filename are all attacker-controlled, so the decision is made from
 * the file's ACTUAL contents (getimagesize() reads the real image header) and
 * the stored filename is generated server-side from a random token
 * (App\Service\Personalization\PersonalizationUploadStorage).
 *
 * Version 1 accepts PNG and JPEG only. SVG is refused on purpose — see
 * PersonalizationRules::ALLOWED_UPLOAD_TYPES — and anything that is not a
 * decodable raster image is refused with it, including a .png that is really
 * a PHP script, an HTML document or a polyglot file.
 *
 * Besides validating, this produces the ONE derivative the browser is ever
 * allowed to see: a fully re-encoded copy, made by App\Service\ImageOptimizer
 * (already used for Portfolio uploads, so its decompression-bomb caps, memory
 * budget and EXIF-orientation handling are shared rather than reimplemented).
 * Re-encoding through GD means the served preview is regenerated pixel data —
 * it cannot carry EXIF, an ICC payload, a comment block or an appended
 * archive. The customer's ORIGINAL file is never modified; it is what the
 * owner receives for production.
 *
 * This class never writes to disk and never touches the database.
 */
class PersonalizationUploadValidator
{
    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file one entry of $_FILES
     * @return array{tmp_path:string,extension:string,mime:string,original_filename:string,size:int,width:int,height:int,preview_bytes:string}
     * @throws \RuntimeException with a Dutch, customer-facing message
     */
    public function validate(array $file): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new \RuntimeException('Geen bestand geselecteerd.');
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \RuntimeException('De afbeelding is te groot (max. 5 MB).');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Uploaden van de afbeelding is mislukt. Probeer het opnieuw.');
        }

        $tmpName = $file['tmp_name'] ?? '';

        // is_uploaded_file() is what stops a crafted request from naming an
        // arbitrary server-side path as its "upload".
        if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \RuntimeException('Ongeldige upload.');
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            throw new \RuntimeException('Het bestand is leeg.');
        }

        if ($size > PersonalizationRules::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('De afbeelding is te groot (max. 5 MB).');
        }

        $info = @getimagesize($tmpName);

        if ($info === false || !isset(PersonalizationRules::ALLOWED_UPLOAD_TYPES[$info[2]])) {
            throw new \RuntimeException('Alleen PNG- en JPG-afbeeldingen zijn toegestaan.');
        }

        [$width, $height, $imageType] = [(int) $info[0], (int) $info[1], (int) $info[2]];

        if ($width < 1 || $height < 1) {
            throw new \RuntimeException('De afbeelding kon niet worden gelezen.');
        }

        if ($width > PersonalizationRules::MAX_UPLOAD_DIMENSION || $height > PersonalizationRules::MAX_UPLOAD_DIMENSION) {
            throw new \RuntimeException('De afbeelding is te groot in afmetingen (max. '
                . PersonalizationRules::MAX_UPLOAD_DIMENSION . ' pixels per zijde).');
        }

        if ($width * $height > PersonalizationRules::MAX_UPLOAD_PIXELS) {
            throw new \RuntimeException('De afbeelding heeft te veel pixels om te kunnen verwerken.');
        }

        [$extension, $mime] = PersonalizationRules::ALLOWED_UPLOAD_TYPES[$imageType];

        // Decoding is itself part of the validation: a file whose header
        // claims PNG but whose body is not a decodable image fails here.
        // ImageOptimizer throws a Dutch RuntimeException of its own for a
        // missing GD extension or an image it cannot process.
        $processed = ImageOptimizer::process($tmpName, $imageType);

        return [
            'tmp_path' => $tmpName,
            'extension' => $extension,
            'mime' => $mime,
            'original_filename' => self::sanitizeOriginalFilename($file['name'] ?? null, $extension),
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'preview_bytes' => $processed['full']['bytes'],
        ];
    }

    /**
     * The original filename is kept as DISPLAY METADATA only — the CMS shows
     * it so the owner recognises what the customer sent. It never becomes a
     * physical filename, never reaches a filesystem call and never decides a
     * MIME type. Any path component and every control character is stripped
     * anyway, so even the displayed value cannot smuggle a traversal segment
     * or a header-breaking character into a later download response.
     */
    public static function sanitizeOriginalFilename(mixed $name, string $extension): string
    {
        $name = is_string($name) ? $name : '';
        // Windows browsers can send a full "C:\path\file.png"; basename()
        // alone would keep that intact, so strip both separators first.
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F"]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return 'afbeelding.' . $extension;
        }

        return mb_strlen($name) > 200 ? mb_substr($name, 0, 200) : $name;
    }
}
