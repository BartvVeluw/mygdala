<?php

declare(strict_types=1);

namespace App\Service\Forms\FieldTypes;

use App\Service\Forms\FormField;

/**
 * Everything a field type needs in order to echo its control, and nothing
 * else: which field, which DOM id and `name` to use, what the visitor last
 * typed, and which elements describe it.
 *
 * The ids come from the RENDERER, not from the field, because the same form
 * may appear twice on one page (FORMS.md, "Eén definitie, meerdere
 * plaatsingen"). Every id here is already scoped to one rendered instance,
 * so a type can print it verbatim without wondering whether it is unique.
 */
final class FormFieldControl
{
    public function __construct(
        public readonly FormField $field,
        /** DOM id of the control itself, unique on the page. */
        public readonly string $id,
        /** The `name` it posts under — the field key. */
        public readonly string $name,
        /** What the visitor previously entered, kept after a failed submit. */
        public readonly string $value,
        /** Space-separated ids for `aria-describedby`, or '' when there are none. */
        public readonly string $describedBy,
        /** Whether this control is currently showing an error. */
        public readonly bool $hasError,
    ) {
    }

    /**
     * The shared attributes every control carries. Built here so a type
     * cannot forget the accessibility ones.
     */
    public function commonAttributes(): string
    {
        $attributes = ' id="' . $this->escape($this->id) . '" name="' . $this->escape($this->name) . '"';

        if ($this->field->isRequired) {
            $attributes .= ' required aria-required="true"';
        }

        if ($this->hasError) {
            $attributes .= ' aria-invalid="true"';
        }

        if ($this->describedBy !== '') {
            $attributes .= ' aria-describedby="' . $this->escape($this->describedBy) . '"';
        }

        $autocomplete = $this->field->type->autocomplete();
        if ($autocomplete !== null) {
            $attributes .= ' autocomplete="' . $this->escape($autocomplete) . '"';
        }

        return $attributes;
    }

    /** The id of the nth sub-control of a radio group. */
    public function optionId(int $index): string
    {
        return $this->id . '-' . $index;
    }

    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
