<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Repository\FormFieldOptionRepository;
use App\Repository\FormRepository;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormLocalization;

/**
 * Forms with words, for the tests that need real rows.
 *
 * Since Multilingual 2.0 phase 4 a form's button text and thank-you message,
 * a field's label, placeholder and help text and an option's label are stored
 * per website language, and an option is a row with its own stable value. A
 * test that wants "a form with a radio field" would otherwise write four
 * inserts; it writes one call here.
 *
 * The option shorthand is the list an editor sees, one per line, the VALUE
 * (and Dutch label) before the `|` and the English label after it:
 *
 *     "Particulier|Personal\nZakelijk|Business"
 */
final class FormFixture
{
    /**
     * Words for a form, a field or an option: language code => field => words.
     *
     * @param array<string, array<string, string>> $words
     */
    public static function formWords(int $formId, array $words): void
    {
        foreach ($words as $language => $fields) {
            FormLocalization::forms()->save($formId, (string) $language, $fields);
        }
    }

    /** @param array<string, array<string, string>> $words */
    public static function fieldWords(int $fieldId, array $words): void
    {
        foreach ($words as $language => $fields) {
            FormLocalization::fields()->save($fieldId, (string) $language, $fields);
        }
    }

    /**
     * One field with its words and, for a choice field, its options.
     *
     * @param array<string, mixed>                 $row   what App\Repository\FormRepository::createField() stores
     * @param array<string, array<string, string>> $words language code => label/placeholder/help_text
     */
    public static function field(int $formId, array $row, array $words, ?string $options = null): int
    {
        $fieldId = (new FormRepository())->createField($formId, $row);
        self::fieldWords($fieldId, $words);
        self::options($fieldId, $options);
        FormCatalog::clearCache();

        return $fieldId;
    }

    /**
     * The options of a choice field, in the editor's shorthand. Returns the
     * new option ids, in order.
     *
     * @return list<int>
     */
    public static function options(int $fieldId, ?string $options): array
    {
        if ($options === null || trim($options) === '') {
            return [];
        }

        $repository = new FormFieldOptionRepository();
        $ids = [];

        foreach (preg_split('/\R/', $options) ?: [] as $position => $line) {
            [$value, $english] = array_pad(explode('|', $line, 2), 2, null);
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $optionId = $repository->create($fieldId, $value, $position);
            FormLocalization::options()->save($optionId, 'nl', [FormLocalization::LABEL => $value]);
            if (trim((string) $english) !== '') {
                FormLocalization::options()->save($optionId, 'en', [FormLocalization::LABEL => trim((string) $english)]);
            }

            $ids[] = $optionId;
        }

        FormCatalog::clearCache();

        return $ids;
    }
}
