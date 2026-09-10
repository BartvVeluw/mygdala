<?php

declare(strict_types=1);

namespace App\Service\Forms\FieldTypes;

use App\Service\Forms\FormField;
use App\Service\Forms\FormText;

/**
 * An e-mail address. The only type whose value may become the notification
 * e-mail's Reply-To (holdsEmailAddress()), and the reason validation here is
 * strict rather than friendly: an address that reaches a mail header must be
 * one PHP itself accepts, or the owner cannot reply and the message may not
 * be delivered at all.
 */
final class EmailFieldType extends FormFieldType
{
    public function key(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'E-mailadres';
    }

    /** The longest address RFC 5321 allows. */
    public function maxLength(): int
    {
        return 254;
    }

    public function holdsEmailAddress(): bool
    {
        return true;
    }

    public function autocomplete(): ?string
    {
        return 'email';
    }

    public function normalize(mixed $raw, FormField $field): string
    {
        return $this->clean($raw, $this->maxLength());
    }

    public function validate(string $value, FormField $field): ?FormText
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return null;
        }

        return FormText::of(
            'Vul een geldig e-mailadres in.',
            'Please enter a valid email address.'
        );
    }

    public function renderControl(FormFieldControl $control): void
    {
        echo '<input type="email"' . $control->commonAttributes()
            . ' maxlength="' . $this->maxLength() . '"'
            . $this->placeholderAttributes($control)
            . ' value="' . $control->escape($control->value) . '">';
    }
}
