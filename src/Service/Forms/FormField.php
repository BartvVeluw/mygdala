<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Service\Forms\FieldTypes\FormFieldType;

/**
 * One field of a form, as everything downstream sees it: its key, its type
 * object, its already-bilingual texts and its options.
 *
 * Built ONLY from a `form_fields` row (fromRow()), and only when that row
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
        public readonly FormText $label,
        public readonly FormText $placeholder,
        public readonly FormText $helpText,
        public readonly bool $isRequired,
        public readonly int $sortOrder,
        public readonly FormFieldOptions $options,
        /**
         * The option this field starts on, or '' for none. Guaranteed to be
         * one of $options — fromRow() drops anything else — so a renderer can
         * print it without checking.
         */
        public readonly string $defaultValue,
    ) {
    }

    /**
     * @param array<string, mixed> $row a `form_fields` row
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

        $options = $type->usesOptions()
            ? FormFieldOptions::fromStored($row['options'] ?? null)
            : FormFieldOptions::fromStored(null);

        return new self(
            (int) ($row['id'] ?? 0),
            $key,
            $type,
            FormText::of($row['label_nl'] ?? '', $row['label_en'] ?? null),
            $type->usesPlaceholder()
                ? FormText::of($row['placeholder_nl'] ?? '', $row['placeholder_en'] ?? null)
                : FormText::of(''),
            FormText::of($row['help_text_nl'] ?? '', $row['help_text_en'] ?? null),
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
        );
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
