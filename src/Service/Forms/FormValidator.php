<?php

declare(strict_types=1);

namespace App\Service\Forms;

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
 * That direction is the whole point. The browser's `required`, `maxlength`
 * and `<select>` are conveniences; a POST can carry anything at all, and
 * assets/js/blocks/form.js may not run. Nothing outside this class decides
 * whether a submission is valid, and nothing downstream re-checks it.
 */
final class FormValidator
{
    /**
     * @param array<string, mixed> $request usually $_POST
     */
    public function validate(FormDefinition $form, array $request): FormValidationResult
    {
        $values = [];
        $errors = [];

        foreach ($form->fields as $field) {
            $type = $field->type;

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
                $errors[$field->key] = FormText::of(
                    $field->label->nl . ' mag maximaal ' . $type->maxLength() . ' tekens bevatten.',
                    $field->label->en . ' may be at most ' . $type->maxLength() . ' characters.'
                );

                continue;
            }

            $error = $type->validate($value, $field);
            if ($error !== null) {
                $errors[$field->key] = $error;
            }
        }

        return new FormValidationResult($values, $errors);
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
