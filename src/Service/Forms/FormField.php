<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Service\Forms\FieldTypes\FormFieldType;
use App\Service\Language\LanguageFallback;

/**
 * One field of a form, as everything downstream sees it: its key, its type
 * object, its texts (in the language of the request, the fallback already
 * applied) and its options.
 *
 * Built ONLY from a `form_fields` row (fromRow()) that
 * App\Service\Forms\FormLocalization has given its words — `translations`,
 * language code => label/placeholder/help_text — and its option rows —
 * `choices`, each a value with its labels per language. Nothing here reads
 * storage. And only when that row
 * names a type App\Service\Forms\FormFieldTypes actually knows — a row with
 * an unregistered `field_type` yields null and is skipped by the definition,
 * so a half-rolled-back migration or a hand-edited row makes a form render
 * one field short instead of crashing a public page. The same degradation
 * rule the content blocks follow (CONTENT-BLOCKS.md).
 */
final class FormField
{
    private function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly FormFieldType $type,
        public readonly string $label,
        public readonly string $placeholder,
        public readonly string $helpText,
        public readonly bool $isRequired,
        public readonly int $sortOrder,
        public readonly FormFieldOptions $options,
        /**
         * The option VALUE this field starts on, or '' for none. Guaranteed
         * to be one of $options — fromRow() drops anything else — so a
         * renderer can print it without checking.
         */
        public readonly string $defaultValue,
        /**
         * The label in the website's DEFAULT language: what a stored
         * submission and the notification e-mail record as the field's name,
         * whatever language the visitor had on screen.
         */
        public readonly string $recordedLabel,
    ) {
    }

    /**
     * @param array<string, mixed> $row a `form_fields` row with its `translations` and `choices`
     * @return self|null null when the row names a type that is not registered
     */
    public static function fromRow(array $row): ?self
    {
        $type = FormFieldTypes::get((string) ($row['field_type'] ?? ''));
        if ($type === null) {
            return null;
        }

        $key = (string) ($row['field_key'] ?? '');
        if (!FormFieldKey::isValid($key)) {
            return null;
        }

        $translations = is_array($row['translations'] ?? null) ? $row['translations'] : [];
        $options = $type->usesOptions()
            ? FormFieldOptions::fromRows(is_array($row['choices'] ?? null) ? $row['choices'] : [])
            : FormFieldOptions::none();

        return new self(
            (int) ($row['id'] ?? 0),
            $key,
            $type,
            FormLocalization::visible($translations, 'label'),
            $type->usesPlaceholder()
                ? FormLocalization::visible($translations, 'placeholder')
                : '',
            FormLocalization::visible($translations, 'help_text'),
            // A consent box is required whatever the row says: see
            // App\Service\Forms\FieldTypes\ConsentFieldType.
            $type->requiredIsFixed() ? true : (bool) ($row['is_required'] ?? false),
            (int) ($row['sort_order'] ?? 0),
            $options,
            // A default is only ever one of this field's own options. A row
            // holding anything else — a type that was switched afterwards, an
            // option that was renamed, a hand-edited row — yields no default
            // rather than a pre-selection nothing matches.
            self::usableDefault($type, $options, $row['default_value'] ?? null),
            self::recordedLabel($translations),
        );
    }

    /**
     * The label a submission records: the default language's, else the first
     * language with one (a label only a translation has is still a name).
     *
     * @param array<string, array<string, string>> $translations
     */
    private static function recordedLabel(array $translations): string
    {
        $labels = [];
        foreach ($translations as $code => $fields) {
            $labels[(string) $code] = (string) ($fields['label'] ?? '');
        }

        return LanguageFallback::name($labels);
    }

    /**
     * THE one rule about default values: a field may have one only if its
     * type takes one, and it must be one of that field's own options.
     *
     * Both directions go through it — the admin before writing
     * (api/admin/update-form-field.php refuses anything else, so the editor
     * hears about it) and the read model after reading (a row that got past
     * that anyway simply yields no default). One rule, no drift, and nothing
     * downstream ever has to wonder whether a default is real.
     */
    public static function isUsableDefault(FormFieldType $type, FormFieldOptions $options, string $value): bool
    {
        if (!$type->usesDefaultValue() || trim($value) === '') {
            return false;
        }

        return $options->contains(trim($value));
    }

    private static function usableDefault(FormFieldType $type, FormFieldOptions $options, mixed $stored): string
    {
        if (!is_string($stored) || !self::isUsableDefault($type, $options, $stored)) {
            return '';
        }

        return trim($stored);
    }

    /** Whether this field starts on one of its options. */
    public function hasDefaultValue(): bool
    {
        return $this->defaultValue !== '';
    }

    /**
     * Whether this field can actually be rendered. A choice field with no
     * options configured yet would render an empty dropdown the visitor
     * cannot satisfy, so it is left out until the editor fills it in — and
     * the form editor says so in as many words.
     */
    public function isRenderable(): bool
    {
        return !$this->type->usesOptions() || !$this->options->isEmpty();
    }

    /** Whether this field may be picked as the notification's Reply-To. */
    public function canBeReplyTo(): bool
    {
        return $this->type->holdsEmailAddress();
    }
}
