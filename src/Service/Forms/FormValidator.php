<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Service\Language\SiteText;

/**
 * THE authority on whether a submission is acceptable.
 *
 * It walks the FORM DEFINITION, never the request. For each field the form
 * has, it reads whatever the request happens to carry under that key, hands
 * it to the field type to clean, applies the two rules that are the same for
 * every type (required, and not longer than the type allows) and then asks
 * the type for its own rule. A key the request sends that the form does not
 * have is never read, never validated and never stored — it simply does not
 * exist as far as this class is concerned.
 *
 * A FILE is an answer like any other: a field whose type accepts one
 * (FormFieldType::acceptsFile()) reads its own entry of $_FILES, by the same
 * key from the same definition, and App\Service\Forms\FormUploadInspector
 * decides whether it is acceptable. A file under a key the form has no upload
 * field for is never looked at.
 *
 * That direction is the whole point. The browser's `required`, `maxlength`
 * and `<select>` are conveniences; a POST can carry anything at all, and
 * assets/js/blocks/form.js may not run. Nothing outside this class decides
 * whether a submission is valid, and nothing downstream re-checks it.
 */
final class FormValidator
{
    public function __construct(
        private readonly FormUploadInspector $uploads = new FormUploadInspector(),
    ) {
    }

    /**
     * @param array<string, mixed> $request usually $_POST
     * @param array<string, mixed> $files   usually $_FILES
     */
    public function validate(FormDefinition $form, array $request, array $files = []): FormValidationResult
    {
        $values = [];
        $errors = [];
        $uploads = [];

        foreach ($form->fields as $field) {
            $type = $field->type;

            // A field answered with a file reads ITS entry of $_FILES, under
            // the same key from the same definition — never another entry,
            // and never $_POST. The inspector decides on the bytes; nothing
            // is written here (FormSubmissionHandler stores it once every
            // field has passed).
            if ($type->acceptsFile()) {
                $inspected = $this->uploads->inspect($files[$field->key] ?? null, $field);
                $values[$field->key] = '';

                if (is_string($inspected)) {
                    $errors[$field->key] = $inspected;
                } elseif ($inspected instanceof FormUpload) {
                    $uploads[$field->key] = $inspected;
                    $values[$field->key] = $inspected->answer();
                } elseif ($field->isRequired) {
                    $errors[$field->key] = $type->requiredMessage($field);
                }

                continue;
            }

            // Note the direction: the KEY comes from the definition. An
            // absent value is null, which every normalize() turns into ''.
            $raw = $request[$field->key] ?? null;
            $value = $type->normalize($raw, $field);

            $values[$field->key] = $value;

            if ($value === '') {
                if ($field->isRequired) {
                    $errors[$field->key] = $type->requiredMessage($field);
                }

                // An optional field left blank is finished: a type's own
                // rule never runs on an empty value.
                continue;
            }

            if (mb_strlen($value) > $type->maxLength()) {
                $errors[$field->key] = SiteText::pick([
                    'nl' => $field->label . ' mag maximaal ' . $type->maxLength() . ' tekens bevatten.',
                    'en' => $field->label . ' may be at most ' . $type->maxLength() . ' characters.',
                ]);

                continue;
            }

            $error = $type->validate($value, $field);
            if ($error !== null) {
                $errors[$field->key] = $error;
            }
        }

        // A form that keeps nothing delivers its files by e-mail and nowhere
        // else: a file that does not fit the message would be lost the moment
        // the visitor is thanked. So for such a form the accepted files
        // together may not exceed what one notification carries — the same
        // budget the notification itself uses
        // (FormSubmissionHandler::MAIL_ATTACHMENT_BUDGET). A form that keeps
        // its submissions has no such limit: what does not fit the e-mail
        // stays downloadable in the CMS.
        if (!$form->storesSubmissions && $uploads !== []) {
            $total = array_sum(array_map(static fn (FormUpload $upload): int => $upload->size, $uploads));

            if ($total > FormSubmissionHandler::MAIL_ATTACHMENT_BUDGET) {
                $budget = FormFileTypes::sizeLabel(FormSubmissionHandler::MAIL_ATTACHMENT_BUDGET);

                foreach (array_keys($uploads) as $key) {
                    $errors[$key] = SiteText::pick([
                        'nl' => 'De bestanden zijn samen te groot om te versturen: samen mogen ze maximaal ' . $budget . ' zijn. Kies kleinere bestanden of laat er een weg.',
                        'en' => 'The files are too large to send together: together they may be at most ' . $budget . '. Choose smaller files or leave one out.',
                    ]);
                }
            }
        }

        return new FormValidationResult($values, $errors, $uploads);
    }

    /**
     * The values to record, in the form's own field order, each carrying the
     * label and type it had at this moment. This is what makes a stored
     * submission survive the form being edited afterwards — see
     * App\Repository\FormSubmissionRepository.
     *
     * @param array<string, string> $values
     * @return list<array{field_key: string, field_label: string, field_type: string, value: string}>
     */
    public function snapshot(FormDefinition $form, array $values): array
    {
        $snapshot = [];

        foreach ($form->fields as $field) {
            $snapshot[] = [
                'field_key' => $field->key,
                // The default language's label, whatever the visitor had on
                // screen, so history does not depend on a language switch.
                'field_label' => $field->recordedLabel,
                'field_type' => $field->type->key(),
                'value' => $values[$field->key] ?? '',
            ];
        }

        return $snapshot;
    }
}
