<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * The choices of a select or radio field: an ordered, closed list of
 * App\Service\Forms\FormOption — a stable value and a localized label each.
 *
 * STORED AS ROWS since Multilingual 2.0 phase 4: one `form_field_options` row
 * per option (its value and position) and its labels per website language in
 * `form_field_option_translations`, through App\Service\Forms\FormLocalization.
 * They used to be `NL|EN` lines in one text column, where the Dutch label was
 * the value too; a third language had nowhere to go, and translating a label
 * would have changed what a submission stores.
 *
 * THE SUBMITTED VALUE IS THE OPTION'S VALUE, never its label in the
 * visitor's language. It reads as a word (it was made from the default
 * language's label when the option was created), so the notification e-mail
 * and a stored submission still say what was chosen without looking anything
 * up, and validation is a plain "is this one of the configured values"
 * comparison. A submission snapshots the value it was given rather than
 * pointing back at this list, so deleting an option later cannot corrupt
 * history.
 */
final class FormFieldOptions
{
    /** An editor cannot make a dropdown longer than this. */
    public const MAX_OPTIONS = 50;

    /** The longest value and label a choice may have (the columns hold 255). */
    public const MAX_LENGTH = 255;

    /** @param list<FormOption> $options */
    private function __construct(private readonly array $options)
    {
    }

    /**
     * Builds the list from the option rows FormLocalization hands a field,
     * in their stored order. An empty value or a value seen before is
     * dropped, and everything past MAX_OPTIONS is ignored: a malformed row
     * degrades to fewer choices, never to an error. A label with no words in
     * any language shows the value, so a choice is never blank.
     *
     * @param list<array{value?: mixed, labels?: array<string, string>}> $rows
     */
    public static function fromRows(array $rows): self
    {
        $options = [];
        $seen = [];

        foreach ($rows as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $label = FormText::fromWords(
                array_map(static fn (mixed $words): array => ['label' => (string) $words], (array) ($row['labels'] ?? [])),
                'label'
            );

            $seen[$value] = true;
            $options[] = new FormOption($value, $label->isEmpty() ? FormText::of($value) : $label);

            if (count($options) >= self::MAX_OPTIONS) {
                break;
            }
        }

        return new self($options);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * One option label as the field editor sends it, as it will be stored:
     * one line, trimmed. A line break has no place in a choice, so every
     * control character becomes a space; anything that is not a string is
     * empty.
     */
    public static function rowText(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
    }

    /** @return list<FormOption> */
    public function all(): array
    {
        return $this->options;
    }

    public function isEmpty(): bool
    {
        return $this->options === [];
    }

    public function count(): int
    {
        return count($this->options);
    }

    /** Whether a submitted value is one of the configured choices. */
    public function contains(string $value): bool
    {
        return $this->find($value) !== null;
    }

    /** The option with this value, or null. */
    public function find(string $value): ?FormOption
    {
        foreach ($this->options as $option) {
            if ($option->value === $value) {
                return $option;
            }
        }

        return null;
    }
}
