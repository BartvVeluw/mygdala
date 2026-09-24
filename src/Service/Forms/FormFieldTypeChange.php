<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Service\Forms\FieldTypes\FormFieldType;

/**
 * What giving a stored field another type would throw away.
 *
 * A type decides which of a field's stored settings mean anything
 * (FormFieldType::usesPlaceholder() and its neighbours), so a new type can
 * leave a setting with nothing to belong to: the options and the default of
 * a dropdown that becomes a text box, the placeholder of a text box that
 * becomes a checkbox, the Reply-To of an e-mail field that stops holding
 * addresses. This answers "what exactly would be lost" from the stored row
 * and the two types' own declarations. There is no table of type pairs, so a
 * type registered tomorrow is classified the day it exists.
 *
 * ONLY A SETTING THAT HOLDS SOMETHING COUNTS. An empty placeholder is no
 * loss, and neither is a default that matches none of the options (the read
 * model already ignores it, FormField::isUsableDefault()). A setting the OLD
 * type never used is no loss either: nobody could see it, and a type change
 * leaves it exactly where it is. What the new type validates differently
 * (an e-mail address, a phone number, a longer text) is no loss: every
 * stored submission keeps its own copy of what was sent.
 *
 * Two callers, one answer. The field editor (admin/form-field.php) says on
 * every type card what choosing it would lose, before anything is sent, and
 * api/admin/update-form-field.php writes a change that loses something only
 * once the editor has confirmed that very type — and then clears exactly
 * what this listed, nothing more. FORMS.md, "Een ander soort veld".
 */
final class FormFieldTypeChange
{
    public const PLACEHOLDER = 'placeholder';
    public const OPTIONS = 'options';
    public const DEFAULT_VALUE = 'default_value';
    public const REPLY_TO = 'reply_to';
    public const FILE_SETTINGS = 'file_settings';

    /**
     * @param array<string, mixed> $row        the field's stored `form_fields` row, with its
     *                                         `translations` and `choices` (FormLocalization::attachFieldWords())
     * @param string|null          $replyToKey the form's stored `reply_to_field_key`
     * @return list<string> the settings that would be lost, as the constants
     *                      above, in that order; [] when nothing would be
     */
    public static function losses(array $row, FormFieldType $to, ?string $replyToKey): array
    {
        $from = FormFieldTypes::get((string) ($row['field_type'] ?? ''));

        // An unregistered stored type used nothing anybody could see
        // (FormField::fromRow() skips the whole row), so nothing is lost.
        if ($from === null || $from->key() === $to->key()) {
            return [];
        }

        $losses = [];

        // A placeholder in ANY language is something an editor wrote.
        $placeholder = false;
        foreach ((array) ($row['translations'] ?? []) as $fields) {
            $placeholder = $placeholder || self::holds($fields['placeholder'] ?? null);
        }

        if ($from->usesPlaceholder() && !$to->usesPlaceholder() && $placeholder) {
            $losses[] = self::PLACEHOLDER;
        }

        $options = FormFieldOptions::fromRows(is_array($row['choices'] ?? null) ? $row['choices'] : []);

        if ($from->usesOptions() && !$to->usesOptions() && !$options->isEmpty()) {
            $losses[] = self::OPTIONS;
        }

        if ($from->usesDefaultValue() && !$to->usesDefaultValue()
            && FormField::isUsableDefault($from, $options, (string) ($row['default_value'] ?? ''))
        ) {
            $losses[] = self::DEFAULT_VALUE;
        }

        // An upload field's accepted kinds and size limit: an editor chose
        // them, so they count as soon as either is stored.
        if ($from->acceptsFile() && !$to->acceptsFile()
            && (self::holds($row['file_types'] ?? null) || (int) ($row['file_max_bytes'] ?? 0) > 0)
        ) {
            $losses[] = self::FILE_SETTINGS;
        }

        if ($from->holdsEmailAddress() && !$to->holdsEmailAddress()
            && self::holds($replyToKey)
            && $replyToKey === (string) ($row['field_key'] ?? '')
        ) {
            $losses[] = self::REPLY_TO;
        }

        return $losses;
    }

    private static function holds(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
