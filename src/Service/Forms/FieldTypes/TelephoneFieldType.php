<?php

declare(strict_types=1);

namespace App\Service\Forms\FieldTypes;

use App\Service\Forms\FormField;
use App\Service\Language\SiteText;

/**
 * A telephone number.
 *
 * NO FORMAT IS ENFORCED, deliberately. A Dutch mobile, a Belgian landline
 * with a country code, an extension written behind a slash and a number a
 * visitor typed with spaces are all things an owner wants to be able to ring
 * back. Pinning one country's pattern here would reject real customers to
 * buy tidiness nobody asked for, so the rule is only "it must plausibly be a
 * phone number and not a paragraph": a length cap and a character whitelist.
 */
final class TelephoneFieldType extends FormFieldType
{
    public function key(): string
    {
        return 'tel';
    }

    public function maxLength(): int
    {
        return 40;
    }

    public function autocomplete(): ?string
    {
        return 'tel';
    }

    public function normalize(mixed $raw, FormField $field): string
    {
        return $this->clean($raw, $this->maxLength());
    }

    public function validate(string $value, FormField $field): ?string
    {
        // Digits, and the punctuation international numbers are actually
        // written with. At least three digits, so a stray word is caught
        // while "+31 (0)6 - 123 456 78" and "0612345678 / 202" both pass.
        if (preg_match('/^[0-9+()\/.\- ]+$/', $value) === 1
            && preg_match_all('/[0-9]/', $value) >= 3
        ) {
            return null;
        }

        return SiteText::pick([
            'nl' => 'Vul een geldig telefoonnummer in.',
            'en' => 'Please enter a valid phone number.',
        ]);
    }

    public function renderControl(FormFieldControl $control): void
    {
        echo '<input type="tel"' . $control->commonAttributes()
            . ' maxlength="' . $this->maxLength() . '"'
            . $this->placeholderAttributes($control)
            . ' value="' . $control->escape($control->value) . '">';
    }
}
