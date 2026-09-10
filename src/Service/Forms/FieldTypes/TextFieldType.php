<?php

declare(strict_types=1);

namespace App\Service\Forms\FieldTypes;

use App\Service\Forms\FormField;

/**
 * A single line of ordinary text — a name, a company, a subject. The
 * plainest type there is, and the one the others are variations of.
 */
final class TextFieldType extends FormFieldType
{
    public function key(): string
    {
        return 'text';
    }

    public function label(): string
    {
        return 'Tekst (één regel)';
    }

    public function normalize(mixed $raw, FormField $field): string
    {
        return $this->clean($raw, $this->maxLength());
    }

    public function renderControl(FormFieldControl $control): void
    {
        echo '<input type="text"' . $control->commonAttributes()
            . ' maxlength="' . $this->maxLength() . '"'
            . $this->placeholderAttributes($control)
            . ' value="' . $control->escape($control->value) . '">';
    }
}
