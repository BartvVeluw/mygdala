<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Service\Language\SiteText;

/**
 * Decides whether ONE entry of $_FILES is a file an upload field accepts.
 * It reads the temporary file and writes nothing: storing is
 * App\Service\Forms\FormSubmissionHandler's, and only after every field of
 * the form passed.
 *
 * NOTHING THE CLIENT SAYS ABOUT THE FILE IS BELIEVED. Not its MIME type (not
 * even read), not its size (the file on disk is measured), not its name
 * beyond one check: the name's extension must be one the field accepts, and
 * the bytes must then turn out to be exactly that type. A PNG renamed to
 * `.pdf`, a script renamed to `.jpg` and a `.php` of any content are all
 * refused. The rules per type are App\Service\Forms\FormFileTypes'.
 *
 * Every refusal is a sentence the visitor can act on, in the language of the
 * request — including PHP's own refusals (UPLOAD_ERR_INI_SIZE and friends),
 * which would otherwise arrive as a silently missing file.
 *
 * THE ANSWER is null (no file was sent), a FormUpload (accepted), or a
 * string (the message beside the field).
 */
final class FormUploadInspector
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

    public function inspect(mixed $entry, FormField $field): FormUpload|string|null
    {
        if ($entry === null) {
            return null;
        }

        // One file per field. An entry whose parts are arrays is what PHP
        // builds for `name="veld[]"` or a crafted multipart body; it is not a
        // file this field asked for.
        if (!is_array($entry) || !isset($entry['error']) || is_array($entry['error'])
            || is_array($entry['tmp_name'] ?? null) || is_array($entry['name'] ?? null)
        ) {
            return $this->say($field, 'Kies één bestand bij :label.', 'Choose one file for :label.');
        }

        $error = (int) $entry['error'];
        $maxBytes = $field->fileMaxBytes;

        switch ($error) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return null;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return $this->tooLarge($field, $maxBytes);
            case UPLOAD_ERR_PARTIAL:
                return $this->say(
                    $field,
                    'Het bestand bij :label kwam niet helemaal aan. Probeer het opnieuw.',
                    'The file for :label did not arrive completely. Please try again.'
                );
            default:
                // No temporary directory, a disk that refused the write, or
                // an extension that stopped it: the server's problem, not
                // the visitor's, and worth a line in the log.
                error_log('[FormUploadInspector] PHP refused an upload for field ' . $field->key . ' with error ' . $error);

                return $this->say(
                    $field,
                    'Het bestand bij :label kon niet worden ontvangen. Probeer het later opnieuw.',
                    'The file for :label could not be received. Please try again later.'
                );
        }

        $tmpPath = $entry['tmp_name'] ?? null;
        if (!is_string($tmpPath) || $tmpPath === '' || !($this->isUploadedFile)($tmpPath) || !is_file($tmpPath)) {
            return $this->say($field, 'Het bestand bij :label is geen geldige upload.', 'The file for :label is not a valid upload.');
        }

        $size = (int) filesize($tmpPath);
        if ($size < 1) {
            return $this->say($field, 'Het bestand bij :label is leeg.', 'The file for :label is empty.');
        }

        if ($size > $maxBytes) {
            return $this->tooLarge($field, $maxBytes);
        }

        $name = self::cleanName($entry['name'] ?? '');
        $extension = self::extension($name);
        $typeKey = null;

        foreach ($field->fileTypes as $key) {
            if (in_array($extension, FormFileTypes::extensions($key), true)) {
                $typeKey = $key;
                break;
            }
        }

        if ($typeKey === null) {
            return $this->wrongType($field);
        }

        if (!self::bytesAre($tmpPath, $typeKey)) {
            return SiteText::pick([
                'nl' => 'Het bestand bij ' . $field->label . ' is geen echt ' . FormFileTypes::label($typeKey) . '-bestand.',
                'en' => 'The file for ' . $field->label . ' is not a real ' . FormFileTypes::label($typeKey) . ' file.',
            ]);
        }

        $hash = hash_file('sha256', $tmpPath);

        return new FormUpload(
            $field->key,
            $tmpPath,
            $name === '' ? $field->key . '.' . FormFileTypes::storedExtension($typeKey) : $name,
            $typeKey,
            $size,
            is_string($hash) ? $hash : ''
        );
    }

    /**
     * The visitor's file name, safe to show and to put in a quoted header:
     * no directory part (either slash), no control characters (NUL included),
     * no Unicode direction overrides that make `gpj.exe` read as `exe.jpg`,
     * no quotes, no leading dots, at most 255 characters. It is a label and
     * nothing more — storage never uses it.
     */
    public static function cleanName(mixed $name): string
    {
        if (!is_string($name)) {
            return '';
        }

        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        // Direction overrides and isolates, zero-width characters and the BOM.
        $name = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $name) ?? '';
        $name = str_replace(['"', '/'], '', $name);
        $name = trim($name, " .\t");

        if (!mb_check_encoding($name, 'UTF-8')) {
            return '';
        }

        return mb_strlen($name) > 255 ? mb_substr($name, -255) : $name;
    }

    /** The lower-case part after the last dot, or ''. */
    private static function extension(string $name): string
    {
        $position = strrpos($name, '.');

        return $position === false ? '' : strtolower(substr($name, $position + 1));
    }

    /** Whether the file's own bytes are the type its name claims. */
    private static function bytesAre(string $path, string $typeKey): bool
    {
        if (FormFileTypes::check($typeKey) === 'pdf') {
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                return false;
            }
            $header = fread($handle, 5);
            fclose($handle);

            return $header === '%PDF-';
        }

        $info = @getimagesize($path);

        return $info !== false && ($info[2] ?? null) === FormFileTypes::imageType($typeKey);
    }

    private function tooLarge(FormField $field, int $maxBytes): string
    {
        $size = FormFileTypes::sizeLabel($maxBytes);

        return SiteText::pick([
            'nl' => 'Het bestand bij ' . $field->label . ' is te groot. Het mag maximaal ' . $size . ' zijn.',
            'en' => 'The file for ' . $field->label . ' is too large. It may be at most ' . $size . '.',
        ]);
    }

    private function wrongType(FormField $field): string
    {
        return SiteText::pick([
            'nl' => 'Dit soort bestand is niet toegestaan bij ' . $field->label . '. Kies een ' . FormFileTypes::listLabel($field->fileTypes, 'of') . '.',
            'en' => 'This kind of file is not allowed for ' . $field->label . '. Choose a ' . FormFileTypes::listLabel($field->fileTypes, 'or') . '.',
        ]);
    }

    private function say(FormField $field, string $nl, string $en): string
    {
        return SiteText::pick([
            'nl' => str_replace(':label', $field->label, $nl),
            'en' => str_replace(':label', $field->label, $en),
        ]);
    }
}
