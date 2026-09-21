<?php

declare(strict_types=1);

namespace App\Service\Forms\FieldTypes;

/**
 * A dropdown with a closed list of choices — see ChoiceFieldType for the
 * rule that makes that list mean something on the server.
 */
final class SelectFieldType extends ChoiceFieldType
{
    public function key(): string
    {
        return 'select';
    }

    public function renderControl(FormFieldControl $control): void
    {
        echo '<select' . $control->commonAttributes() . '>';

        // An empty first entry, so a required dropdown never pre-selects an
        // answer the visitor did not give. An optional one gets it too, as
        // the way to say "none of these".
        echo '<option value=""' . ($control->value === '' ? ' selected' : '') . '>&mdash;</option>';

        foreach ($control->field->options->all() as $option) {
            // The value is the option's stable identity; only the label is
            // in the language being read (App\Service\Forms\FormOption).
            echo '<option value="' . $control->escape($option->value) . '"'
                . ($control->value === $option->value ? ' selected' : '') . '>'
                . $control->escape($option->label)
                . '</option>';
        }

        echo '</select>';
    }
}
