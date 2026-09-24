<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * What came back from validating a submission: the cleaned value of every
 * field the form actually has, and a message for each one that failed.
 *
 * Both maps are keyed by field key, and both only ever contain keys that are
 * in the form definition — which is the property that makes "never blindly
 * persist $_POST" true by construction rather than by discipline.
 */
final class FormValidationResult
{
    /**
     * @param array<string, string>     $values  cleaned, in definition order; an upload field's is its file's name and size
     * @param array<string, string>     $errors  in the language of the request, one per failing field
     * @param array<string, FormUpload> $uploads the files that were accepted, still in PHP's temporary directory
     */
    public function __construct(
        public readonly array $values,
        public readonly array $errors,
        public readonly array $uploads = [],
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function errorFor(string $fieldKey): ?string
    {
        return $this->errors[$fieldKey] ?? null;
    }

    public function valueFor(string $fieldKey): string
    {
        return $this->values[$fieldKey] ?? '';
    }

    /**
     * The values that are safe to put back into the form after a failure.
     * Everything here has already been through the field type's normalize(),
     * so it carries no control characters and no over-long string — but a
     * template must still escape it, exactly like any other output.
     *
     * @return array<string, string>
     */
    public function retainableValues(): array
    {
        return array_diff_key($this->values, $this->uploads);
    }

    /**
     * The errors to show after a refused submission that goes back through a
     * REDIRECT (no JavaScript). Next to the fields' own errors, every file
     * that WAS accepted gets its type's reselectMessage(): the browser cannot
     * put it back into its input and nothing keeps it on the server, so the
     * form would otherwise come back quietly without it. The fetch() answer
     * does not need this — the page never left, and the file is still chosen.
     *
     * @return array<string, string>
     */
    public function errorsAfterRedirect(FormDefinition $form): array
    {
        $errors = $this->errors;

        foreach ($form->fields as $field) {
            if (!isset($this->uploads[$field->key]) || isset($errors[$field->key])) {
                continue;
            }

            $message = $field->type->reselectMessage($field);
            if ($message !== null) {
                $errors[$field->key] = $message;
            }
        }

        return $errors;
    }
}
