<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * A whole form as the renderer, the validator, the submission handler and
 * the e-mail builder see it: its settings, already bilingual, plus its
 * fields in display order.
 *
 * This is the READ MODEL. Nothing here writes; App\Repository\FormRepository
 * owns the SQL and App\Service\Forms\FormCatalog owns the per-request cache.
 * The four consumers all take a FormDefinition rather than a row, which is
 * what stops "what fields does this form have" from being answered
 * differently in four places — the mistake the hardcoded contact form made.
 *
 * Fields whose type is not registered, and choice fields with no options
 * configured yet, are left out here (FormField::isRenderable()) rather than
 * being rendered broken. A form is therefore always internally consistent by
 * the time anything downstream touches it.
 */
final class FormDefinition
{
    /** @param list<FormField> $fields */
    private function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $internalKey,
        public readonly bool $isActive,
        public readonly FormText $submitLabel,
        public readonly FormText $successMessage,
        public readonly string $notificationEmail,
        public readonly ?string $replyToFieldKey,
        public readonly bool $storesSubmissions,
        public readonly array $fields,
    ) {
    }

    /**
     * @param array<string, mixed>            $form   a `forms` row
     * @param list<array<string, mixed>>      $fields its `form_fields` rows, already ordered
     */
    public static function fromRows(array $form, array $fields): self
    {
        $usable = [];
        foreach ($fields as $row) {
            $field = FormField::fromRow($row);
            if ($field !== null && $field->isRenderable()) {
                $usable[] = $field;
            }
        }

        $replyTo = trim((string) ($form['reply_to_field_key'] ?? ''));

        return new self(
            (int) ($form['id'] ?? 0),
            trim((string) ($form['name'] ?? '')),
            (string) ($form['internal_key'] ?? ''),
            (bool) ($form['is_active'] ?? false),
            // The submit button always says something: an empty stored label
            // would render a nameless button, which is worse than a generic
            // one. Neither default names a company or a product.
            self::textOrDefault($form['submit_label_nl'] ?? '', $form['submit_label_en'] ?? null, 'Versturen', 'Send'),
            self::textOrDefault(
                $form['success_message_nl'] ?? '',
                $form['success_message_en'] ?? null,
                'Bedankt — je bericht is verstuurd.',
                'Thanks — your message has been sent.'
            ),
            trim((string) ($form['notification_email'] ?? '')),
            $replyTo !== '' ? $replyTo : null,
            (bool) ($form['store_submissions'] ?? false),
            $usable,
        );
    }

    /** The field with this key, or null — the ONLY way a posted name is resolved. */
    public function field(string $key): ?FormField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function fieldKeys(): array
    {
        return array_map(static fn (FormField $field): string => $field->key, $this->fields);
    }

    public function hasFields(): bool
    {
        return $this->fields !== [];
    }

    /**
     * The e-mail field whose value may be used as the notification's
     * Reply-To, or null when the form has none configured (or the configured
     * one has since been deleted or changed type).
     */
    public function replyToField(): ?FormField
    {
        if ($this->replyToFieldKey === null) {
            return null;
        }

        $field = $this->field($this->replyToFieldKey);

        return ($field !== null && $field->canBeReplyTo()) ? $field : null;
    }

    /**
     * Whether this form can be shown to a visitor at all: switched on, and
     * with at least one usable field. An inactive or empty form renders
     * nothing rather than an empty box with a button
     * (FORMS.md, "Een formulier dat niet getoond kan worden").
     */
    public function isRenderable(): bool
    {
        return $this->isActive && $this->hasFields();
    }

    /** @return list<FormField> the fields that may be offered as Reply-To */
    public function replyToCandidates(): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (FormField $field): bool => $field->canBeReplyTo()
        ));
    }

    private static function textOrDefault(mixed $nl, mixed $en, string $defaultNl, string $defaultEn): FormText
    {
        $text = FormText::of(is_string($nl) ? $nl : '', is_string($en) ? $en : null);

        return $text->isEmpty() ? FormText::of($defaultNl, $defaultEn) : $text;
    }
}
