<?php

declare(strict_types=1);

namespace App\Service\Forms\FieldTypes;

use App\Service\Forms\FormField;
use App\Service\Language\SiteText;

/**
 * The box a visitor ticks to agree to something — a privacy statement, terms
 * and conditions, being contacted about their enquiry.
 *
 * Mechanically a checkbox; separate because its REQUIRED FLAG IS NOT A
 * SETTING. Consent that may be withheld and still let the form through is
 * not consent, and an editor who unticks "verplicht" on one would be given
 * the false impression the CMS is recording an agreement. So the admin shows
 * it as fixed and this class says so (requiredIsFixed()), rather than the
 * form editor carrying an `if` about one type.
 *
 * The label is ordinary editor text, printed escaped like all of it — this
 * is not a place to accept HTML, so a link inside the consent sentence is
 * deliberately not possible in V1 (FORMS.md, "Bewust niet ondersteund").
 */
final class ConsentFieldType extends CheckboxFieldType
{
    public function key(): string
    {
        return 'consent';
    }

    public function requiredIsFixed(): bool
    {
        return true;
    }

    public function requiredMessage(FormField $field): string
    {
        return SiteText::pick([
            'nl' => 'Je moet akkoord gaan met "' . $field->label . '" om te kunnen versturen.',
            'en' => 'You must agree to "' . $field->label . '" before sending.',
        ]);
    }
}
