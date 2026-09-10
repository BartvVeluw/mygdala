<?php

declare(strict_types=1);

namespace App\Service\Forms\FieldTypes;

use App\Service\Forms\FormField;
use App\Service\Forms\FormText;

/**
 * One box to tick: "stuur me de nieuwsbrief", "ik wil graag gebeld worden".
 *
 * A browser posts nothing at all for an unticked box, so "empty" and
 * "unticked" are the same thing here — which is exactly what makes the
 * shared required rule do the right thing for a required checkbox without a
 * special case anywhere.
 *
 * The stored value is the word "Ja" rather than a 1: a submission is read by
 * a person in an e-mail and in the CMS, and a column of ones tells them
 * nothing.
 */
class CheckboxFieldType extends FormFieldType
{
    /** What a ticked box is recorded as, in both languages. */
    public const CHECKED_NL = 'Ja';
    public const CHECKED_EN = 'Yes';

    public function key(): string
    {
        return 'checkbox';
    }

    public function label(): string
    {
        return 'Vinkje (aan/uit)';
    }

    public function usesPlaceholder(): bool
    {
        return false;
    }

    public function labelPosition(): string
    {
        return 'wrap';
    }

    public function maxLength(): int
    {
        return 10;
    }

    public function normalize(mixed $raw, FormField $field): string
    {
        // Anything present and non-empty means ticked; a browser only ever
        // sends the value attribute of a box that was checked.
        $value = is_string($raw) ? trim($raw) : '';

        return $value !== '' ? self::CHECKED_NL : '';
    }

    public function requiredMessage(FormField $field): FormText
    {
        return FormText::of(
            'Zet een vinkje bij "' . $field->label->nl . '".',
            'Please tick "' . $field->label->en . '".'
        );
    }

    public function renderControl(FormFieldControl $control): void
    {
        echo '<input type="checkbox"' . $control->commonAttributes()
            . ' value="' . $control->escape(self::CHECKED_NL) . '"'
            . ($control->value !== '' ? ' checked' : '') . '>';
    }
}
