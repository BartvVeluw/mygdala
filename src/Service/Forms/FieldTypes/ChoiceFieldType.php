<?php

declare(strict_types=1);

namespace App\Service\Forms\FieldTypes;

use App\Service\Forms\FormField;
use App\Service\Language\SiteText;

/**
 * What a dropdown and a radio group have in common: an editor-configured,
 * closed list of choices, and the rule that a submitted value must be one of
 * them.
 *
 * THE CLOSED LIST IS A SECURITY BOUNDARY, not a convenience. A `<select>` or
 * a radio group in a browser enforces nothing at all — a request can post
 * any string it likes under that name — so the stored value can only ever be
 * one of the options the editor actually configured. The same reasoning
 * CONTENT-BLOCKS.md applies to block types and gallery sources.
 *
 * The two subclasses differ in exactly one thing: their markup.
 */
abstract class ChoiceFieldType extends FormFieldType
{
    public function usesOptions(): bool
    {
        return true;
    }

    public function usesPlaceholder(): bool
    {
        return false;
    }

    public function normalize(mixed $raw, FormField $field): string
    {
        return $this->clean($raw, $this->maxLength());
    }

    public function validate(string $value, FormField $field): ?string
    {
        if ($field->options->contains($value)) {
            return null;
        }

        return SiteText::pick(['nl' => 'Maak een keuze uit de lijst.', 'en' => 'Please choose one of the options.']);
    }
}
