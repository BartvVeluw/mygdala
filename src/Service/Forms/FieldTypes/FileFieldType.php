<?php

declare(strict_types=1);

namespace App\Service\Forms\FieldTypes;

use App\Service\Forms\FormField;
use App\Service\Forms\FormFileTypes;
use App\Service\Language\SiteText;

/**
 * One file: a photo, a drawing, a PDF (FORMS.md, "Bestand uploaden").
 *
 * An ordinary field in every way the builder knows — a label, a hint, a
 * required switch, a width, a place in the list — plus two settings of its
 * own: which kinds of file it accepts and how large one may be
 * (App\Service\Forms\FormFileTypes, both closed lists). Exactly one file per
 * field in V1.
 *
 * Its answer is not text, so it never reads $_POST: acceptsFile() makes
 * App\Service\Forms\FormValidator hand its $_FILES entry to
 * App\Service\Forms\FormUploadInspector, which decides on the bytes. What a
 * submission records as its answer is the visitor's file name and size;
 * the file itself is stored and linked to the submission and this field
 * (form_submission_attachments.field_key).
 *
 * The control prints what it accepts in words, under the input, so nobody
 * has to find out by being refused. `accept` only filters the file picker
 * and decides nothing.
 *
 * AFTER A REFUSED SUBMISSION the browser cannot put a file back into a file
 * input, and this CMS does not keep one waiting on the server. Without
 * JavaScript the form comes back empty here, with reselectMessage() beside
 * it; with JavaScript the page never reloads and the chosen file is still
 * there.
 */
final class FileFieldType extends FormFieldType
{
    public function key(): string
    {
        return 'file';
    }

    public function acceptsFile(): bool
    {
        return true;
    }

    public function usesPlaceholder(): bool
    {
        return false;
    }

    /**
     * A POST value under this key means nothing: the answer is a file. Only
     * App\Service\Forms\FormValidator gives this field its value, from the
     * inspected upload.
     */
    public function normalize(mixed $raw, FormField $field): string
    {
        return '';
    }

    /** Long enough for "a 255-character file name (10,0 MB)". */
    public function maxLength(): int
    {
        return 300;
    }

    public function requiredMessage(FormField $field): string
    {
        return SiteText::pick([
            'nl' => 'Kies een bestand bij ' . $field->label . '.',
            'en' => 'Choose a file for ' . $field->label . '.',
        ]);
    }

    /**
     * Beside a file that WAS accepted, when the form came back without
     * JavaScript because another field failed: the file is not kept, so
     * the visitor has to choose it again.
     */
    public function reselectMessage(FormField $field): string
    {
        return SiteText::pick([
            'nl' => 'Kies het bestand bij ' . $field->label . ' opnieuw: een bestand wordt niet bewaard als het formulier terugkomt.',
            'en' => 'Choose the file for ' . $field->label . ' again: a file is not kept when the form comes back.',
        ]);
    }

    public function renderControl(FormFieldControl $control): void
    {
        $field = $control->field;
        $rulesId = $control->id . '-rules';
        $types = FormFileTypes::listLabel($field->fileTypes, SiteText::pick(['nl' => 'of', 'en' => 'or']));
        $size = FormFileTypes::sizeLabel($field->fileMaxBytes);

        echo '<input type="file"' . $control->commonAttributes($rulesId)
            . ' accept="' . $control->escape(FormFileTypes::acceptAttribute($field->fileTypes)) . '"'
            . ' data-max-bytes="' . $field->fileMaxBytes . '">';

        echo '<span class="hint form-file-rules" id="' . $control->escape($rulesId) . '">'
            . $control->escape(SiteText::pick([
                'nl' => $types . ', max. ' . $size . '.',
                'en' => $types . ', max. ' . $size . '.',
            ]))
            . '</span>';
    }
}
