<?php

declare(strict_types=1);

namespace App\Service\Forms\FieldTypes;

use App\Service\Forms\FormField;

/**
 * A multi-line answer: the description, the question, the message. The only
 * type that keeps its newlines (see cleanMultiline()), and the only one with
 * a length worth thinking about — 5000 characters is what the quote form has
 * always accepted, which is generous for a person and still far short of
 * anything that would make a notification e-mail unusable.
 */
final class TextareaFieldType extends FormFieldType
{
    public function key(): string
    {
        return 'textarea';
    }

    public function label(): string
    {
        return 'Tekst (meerdere regels)';
    }

    public function maxLength(): int
    {
        return 5000;
    }

    public function normalize(mixed $raw, FormField $field): string
    {
        return $this->cleanMultiline($raw, $this->maxLength());
    }

    public function renderControl(FormFieldControl $control): void
    {
        echo '<textarea' . $control->commonAttributes()
            . ' rows="6" maxlength="' . $this->maxLength() . '"'
            . $this->placeholderAttributes($control) . '>'
            . $control->escape($control->value)
            . '</textarea>';
    }
}
