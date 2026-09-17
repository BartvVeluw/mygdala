<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Repository\FormFieldOptionRepository;
use App\Service\Language\EntityTranslations;
use App\Service\Language\LanguageFallback;
use App\Service\Language\TranslationTable;

/**
 * THE way into the words of Core Forms in any website language
 * (Multilingual 2.0 phase 4, docs/multilingual/ARCHITECTURE.md):
 *
 *   form_translations               submit_label, success_message  of a form
 *   form_field_translations         label, placeholder, help_text  of a field
 *   form_field_option_translations  label                          of an option
 *
 * one row per owner per website language, through
 * App\Service\Language\EntityTranslations and its fallback (the asked-for
 * language, the default language, '').
 *
 * WHAT IS NOT A WORD, and stays in `forms`/`form_fields`/`form_field_options`:
 * the form's name (an editor's own name for it, never shown to a visitor),
 * its internal key, status, recipient, Reply-To and storage switch; a
 * field's key, type, required flag, position and default; an option's value
 * and position. A submission keeps its own snapshot and is not touched here.
 *
 * READ MODEL WIRING. FormDefinition and FormField read no storage: they read
 * rows this class has given their words (attachWords()), so a public form,
 * the validator, the submission handler and the block library's in-memory
 * sample all go through the same two constructors.
 */
final class FormLocalization
{
    public const SUBMIT_LABEL = 'submit_label';
    public const SUCCESS_MESSAGE = 'success_message';
    public const LABEL = 'label';
    public const PLACEHOLDER = 'placeholder';
    public const HELP_TEXT = 'help_text';

    private static ?EntityTranslations $forms = null;
    private static ?EntityTranslations $fields = null;
    private static ?EntityTranslations $options = null;

    public static function forms(): EntityTranslations
    {
        return self::$forms ??= new EntityTranslations(new TranslationTable(
            'form_translations',
            'form_id',
            [self::SUBMIT_LABEL => 150, self::SUCCESS_MESSAGE => 1000]
        ));
    }

    public static function fields(): EntityTranslations
    {
        return self::$fields ??= new EntityTranslations(new TranslationTable(
            'form_field_translations',
            'form_field_id',
            [self::LABEL => 200, self::PLACEHOLDER => 200, self::HELP_TEXT => 500]
        ));
    }

    public static function options(): EntityTranslations
    {
        return self::$options ??= new EntityTranslations(new TranslationTable(
            'form_field_option_translations',
            'form_field_option_id',
            [self::LABEL => FormFieldOptions::MAX_LENGTH]
        ));
    }

    /**
     * A form row and its field rows with their words attached: `translations`
     * on the form and on each field, and `choices` (value and labels, in
     * order) on each field. Four queries whatever the size of the form.
     *
     * @param array<string, mixed>       $form
     * @param list<array<string, mixed>> $fields
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    public static function attachWords(array $form, array $fields): array
    {
        $form['translations'] = self::forms()->words((int) ($form['id'] ?? 0));

        return [$form, self::attachFieldWords($fields)];
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    public static function attachFieldWords(array $fields): array
    {
        $fieldIds = array_map(static fn (array $field): int => (int) ($field['id'] ?? 0), $fields);
        self::fields()->preload($fieldIds);

        try {
            $optionRows = (new FormFieldOptionRepository())->findForFields($fieldIds);
        } catch (\Throwable $e) {
            error_log('[FormLocalization] the options could not be read: ' . $e->getMessage());
            $optionRows = [];
        }

        $optionIds = [];
        foreach ($optionRows as $rows) {
            foreach ($rows as $row) {
                $optionIds[] = $row['id'];
            }
        }
        self::options()->preload($optionIds);

        foreach ($fields as $index => $field) {
            $fieldId = (int) ($field['id'] ?? 0);
            $fields[$index]['translations'] = self::fields()->words($fieldId);
            $fields[$index]['choices'] = array_map(
                static fn (array $row): array => $row + ['labels' => self::optionLabels($row['id'])],
                $optionRows[$fieldId] ?? []
            );
        }

        return $fields;
    }

    /** @return array<string, string> language code => the option's label */
    public static function optionLabels(int $optionId): array
    {
        $labels = [];
        foreach (self::options()->words($optionId) as $code => $fields) {
            $labels[$code] = (string) ($fields[self::LABEL] ?? '');
        }

        return $labels;
    }

    /** What the CMS calls a field: its label in the default language, else the first language with one. */
    public static function fieldName(int $fieldId): string
    {
        return self::fields()->name($fieldId, self::LABEL);
    }

    /** The website's default language, where a new field or option starts. */
    public static function defaultLanguage(): string
    {
        return LanguageFallback::defaultLanguage();
    }

    public static function clearCache(): void
    {
        self::forms()->clearCache();
        self::fields()->clearCache();
        self::options()->clearCache();
    }
}
