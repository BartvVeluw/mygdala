<?php

declare(strict_types=1);

namespace App\Service\OrderFields;

use App\Service\ImageOptimizer;
use App\Service\Language\SiteText;

/**
 * Decides whether ONE entry of $_FILES is a picture an "Afbeelding uploaden"
 * question takes (App\Service\OrderFields\OrderFieldUploadPolicy). Reads the
 * temporary file, writes nothing.
 *
 * NOTHING THE BROWSER SAYS ABOUT THE FILE IS BELIEVED: not its MIME type (not
 * read), not its size (the file on disk is measured), not its extension (not
 * read), not its name (kept as display text only, stripped of any path). In
 * this order:
 *
 *   1. one file, and PHP's own upload status is OK;
 *   2. a real PHP upload (is_uploaded_file), not a path a request named;
 *   3. not empty, and not above the question's effective limit;
 *   4. the bytes' MIME type (finfo) is JPEG, PNG or WebP — so SVG, HTML,
 *      text or a script with a picture's name is refused here;
 *   5. getimagesize() reads the same format from the header, with sane
 *      dimensions within MAX_DIMENSION and MAX_PIXELS;
 *   6. GD really DECODES it (App\Service\ImageOptimizer, which re-applies the
 *      pixel ceiling and raises memory within its budget) and draws the small
 *      re-encoded thumbnail the order screen shows.
 *
 * Every refusal is a sentence the customer can act on, in the language of
 * the page.
 */
final class OrderFieldUploadValidator
{
    /** @var \Closure(string): bool */
    private readonly \Closure $isUploadedFile;

    /**
     * @param (\Closure(string): bool)|null $isUploadedFile is_uploaded_file(); a test hands in its own
     */
    public function __construct(?\Closure $isUploadedFile = null)
    {
        $this->isUploadedFile = $isUploadedFile ?? static fn (string $path): bool => is_uploaded_file($path);
    }

    /**
     * @return array{tmp_path: string, extension: string, mime: string, original_filename: string, size: int, width: int, height: int, thumbnail_bytes: string}
     *
     * @throws OrderFieldUploadException with a customer-facing message
     */
    public function validate(mixed $entry, int $maxBytes, string $languageCode): array
    {
        if (!is_array($entry) || !isset($entry['error']) || is_array($entry['error'])
            || is_array($entry['tmp_name'] ?? null) || is_array($entry['name'] ?? null)) {
            throw $this->refuse('one', $languageCode);
        }

        switch ((int) $entry['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw $this->refuse('none', $languageCode);
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw $this->refuse('too_large', $languageCode, $maxBytes);
            case UPLOAD_ERR_PARTIAL:
                throw $this->refuse('partial', $languageCode);
            default:
                error_log('[OrderFieldUploadValidator] PHP refused an upload with error ' . (int) $entry['error']);
                throw $this->refuse('failed', $languageCode);
        }

        $tmp = $entry['tmp_name'] ?? '';
        if (!is_string($tmp) || $tmp === '' || !($this->isUploadedFile)($tmp) || !is_file($tmp)) {
            throw $this->refuse('failed', $languageCode);
        }

        $size = (int) filesize($tmp);
        if ($size <= 0) {
            throw $this->refuse('empty', $languageCode);
        }
        if ($size > $maxBytes) {
            throw $this->refuse('too_large', $languageCode, $maxBytes);
        }

        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $allowedMimes = array_column(OrderFieldUploadPolicy::FORMATS, 1);
        if (!is_string($sniffed) || !in_array($sniffed, $allowedMimes, true)) {
            throw $this->refuse('type', $languageCode);
        }

        $info = @getimagesize($tmp);
        if ($info === false || !isset(OrderFieldUploadPolicy::FORMATS[$info[2]])) {
            throw $this->refuse('type', $languageCode);
        }
        [$extension, $mime] = OrderFieldUploadPolicy::FORMATS[$info[2]];
        if ($mime !== $sniffed) {
            // The header says one format and the bytes another: a polyglot.
            throw $this->refuse('type', $languageCode);
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 1 || $height < 1) {
            throw $this->refuse('unreadable', $languageCode);
        }
        if ($width > OrderFieldUploadPolicy::MAX_DIMENSION || $height > OrderFieldUploadPolicy::MAX_DIMENSION
            || $width * $height > OrderFieldUploadPolicy::MAX_PIXELS) {
            throw $this->refuse('pixels', $languageCode);
        }

        try {
            $processed = ImageOptimizer::process($tmp, (int) $info[2]);
        } catch (\Throwable $e) {
            throw $this->refuse('unreadable', $languageCode);
        }

        return [
            'tmp_path' => $tmp,
            'extension' => $extension,
            'mime' => $mime,
            'original_filename' => self::displayName($entry['name'] ?? null, $extension),
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'thumbnail_bytes' => $processed['thumbnail']['bytes'],
        ];
    }

    /**
     * The customer's filename as display text only: never a path, never a
     * MIME type, never part of a stored filename. Any directory, control
     * character, quote or backslash is gone, so it cannot break a header or
     * a line of a mail either.
     */
    public static function displayName(mixed $name, string $extension): string
    {
        $name = is_string($name) ? $name : '';
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F"\/\\\\]/u', '', $name);
        $name = trim($name);
        if (preg_match('//u', $name) !== 1) {
            $name = '';
        }

        if ($name === '' || $name === '.' || $name === '..') {
            return 'afbeelding.' . $extension;
        }

        return mb_strlen($name) > 200 ? mb_substr($name, 0, 200) : $name;
    }

    private function refuse(string $kind, string $languageCode, int $maxBytes = 0): OrderFieldUploadException
    {
        return new OrderFieldUploadException($kind, self::message($kind, $languageCode, $maxBytes));
    }

    public static function message(string $kind, string $languageCode, int $maxBytes = 0): string
    {
        $max = OrderFieldUploadPolicy::formatBytes($maxBytes, $languageCode);
        $formats = OrderFieldUploadPolicy::formatsText($languageCode);

        $sentence = match ($kind) {
            'none' => ['nl' => 'Kies een afbeelding.', 'en' => 'Please choose a picture.'],
            'one' => ['nl' => 'Kies één afbeelding.', 'en' => 'Please choose one picture.'],
            'too_large' => ['nl' => 'Deze afbeelding is te groot. Het maximum is {max}.', 'en' => 'This picture is too large. The maximum is {max}.'],
            'partial' => ['nl' => 'De afbeelding kwam niet helemaal aan. Probeer het opnieuw.', 'en' => 'The picture did not arrive completely. Please try again.'],
            'empty' => ['nl' => 'Dit bestand is leeg.', 'en' => 'This file is empty.'],
            'type' => ['nl' => 'Dit bestand is geen {formats}-afbeelding.', 'en' => 'This file is not a {formats} picture.'],
            'pixels' => ['nl' => 'Deze afbeelding heeft te veel pixels. Kies een kleinere versie.', 'en' => 'This picture has too many pixels. Please choose a smaller version.'],
            'unreadable' => ['nl' => 'Deze afbeelding kan niet worden gelezen. Kies een andere.', 'en' => 'This picture cannot be read. Please choose another one.'],
            default => ['nl' => 'Het uploaden is mislukt. Probeer het opnieuw.', 'en' => 'The upload failed. Please try again.'],
        };

        return strtr(SiteText::pick($sentence, $languageCode), ['{max}' => $max, '{formats}' => $formats]);
    }
}
