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
     * @param array<string, string>   $values cleaned, in definition order
     * @param array<string, string> $errors in the language of the request, one per failing field
     */
    public function __construct(
        public readonly array $values,
        public readonly array $errors,
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
        return $this->values;
    }
}
