<?php

declare(strict_types=1);

namespace App\Service\Forms\FieldTypes;

/**
 * The same closed list as a dropdown, shown as radio buttons — for two or
 * three choices where seeing them all at once is the point.
 *
 * Rendered as a `<fieldset>` with a `<legend>`: a group of radios needs one
 * accessible name for the whole group, which a plain `<label>` cannot give
 * it. partials/form.php knows that from labelPosition() and prints no
 * `<label>` of its own — the legend is the label.
 */
final class RadioFieldType extends ChoiceFieldType
{
    public function key(): string
    {
        return 'radio';
    }

    public function label(): string
    {
        return 'Keuzerondjes (één keuze)';
    }

    public function labelPosition(): string
    {
        return 'legend';
    }

    public function renderControl(FormFieldControl $control): void
    {
        $field = $control->field;

        $attributes = '';
        if ($field->isRequired) {
            $attributes .= ' required aria-required="true"';
        }
        if ($control->hasError) {
            $attributes .= ' aria-invalid="true"';
        }

        echo '<div class="check-grid">';

        foreach ($field->options->all() as $index => $option) {
            $id = $control->optionId($index);

            echo '<label class="check-pill" for="' . $control->escape($id) . '">'
                . '<input type="radio" id="' . $control->escape($id) . '"'
                . ' name="' . $control->escape($control->name) . '"'
                . ' value="' . $control->escape($option->nl) . '"'
                . ($control->value === $option->nl ? ' checked' : '')
                . $attributes . '>'
                . '<span data-nl="' . $control->escape($option->nl) . '"'
                . ' data-en="' . $control->escape($option->en) . '">'
                . $control->escape($option->nl)
                . '</span>'
                . '</label>';
        }

        echo '</div>';
    }
}
