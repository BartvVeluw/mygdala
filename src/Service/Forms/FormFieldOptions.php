<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * The choices of a select or radio field: an ordered, closed list of
 * bilingual labels.
 *
 * STORED AS ONE TEXT COLUMN, one option per line, `NL|EN` with the English
 * half optional. That is not a shortcut around a proper table — it is the
 * whole configuration a Forms V1 choice field has, an editor fills it in as
 * a list of rows on one screen, and a child table would buy nothing but
 * three more write endpoints.
 * A JSON blob would buy even less and read worse in a database client.
 *
 * THE SUBMITTED VALUE IS THE DUTCH LABEL. There is deliberately no separate
 * machine value for an editor to invent and keep in sync: the notification
 * e-mail and the stored submission then read as the visitor saw them, and
 * validation is a plain "is this one of the configured labels" comparison
 * (App\Service\Forms\FieldTypes\SelectFieldType). Renaming an option later
 * cannot corrupt history, because a submission snapshots the value it was
 * given rather than pointing back at this list.
 */
final class FormFieldOptions
{
    /** An editor cannot make a dropdown longer than this. */
    public const MAX_OPTIONS = 50;

    /** Where a stored line's English half starts. */
    public const SEPARATOR = '|';

    /** @param list<FormText> $options */
    private function __construct(private readonly array $options)
    {
    }

    /**
     * Parses the stored column. Blank lines are dropped, duplicates (by
     * Dutch label) are dropped, and everything past MAX_OPTIONS is ignored —
     * a malformed row degrades to fewer choices, never to an error.
     */
    public static function fromStored(?string $stored): self
    {
        $options = [];
        $seen = [];

        foreach (preg_split('/\R/', (string) $stored) ?: [] as $line) {
            [$nl, $en] = array_pad(explode(self::SEPARATOR, $line, 2), 2, null);

            $option = FormText::of($nl, $en);
            if ($option->nl === '' || isset($seen[$option->nl])) {
                continue;
            }

            $seen[$option->nl] = true;
            $options[] = $option;

            if (count($options) >= self::MAX_OPTIONS) {
                break;
            }
        }

        return new self($options);
    }

    /**
     * Canonical form for storage: the same parsing rules applied to whatever
     * the admin textarea submitted, written back as one clean line per
     * option. What comes out of the editor is therefore always what
     * fromStored() will read back.
     */
    public static function toStored(?string $submitted): string
    {
        $lines = [];

        foreach (self::fromStored($submitted)->all() as $option) {
            $lines[] = $option->nl === $option->en
                ? $option->nl
                : $option->nl . self::SEPARATOR . $option->en;
        }

        return implode("\n", $lines);
    }

    /**
     * The same canonical text, from the field editor's option rows: a Dutch
     * and an English box per option (admin/form-field.php) instead of
     * `NL|EN` typed into a textarea. Each row becomes the line the textarea
     * used to hold and goes through toStored(), so empty rows, duplicates
     * and the cap follow the one set of rules this class already has, and
     * the stored format is exactly what it always was.
     *
     * A row's Dutch half must not hold the separator — it would start the
     * English half — so api/admin/update-form-field.php refuses one
     * (holdsSeparator()) before it gets here.
     *
     * @param list<array{nl: string, en: string}> $rows as rowText() cleaned them
     */
    public static function rowsToStored(array $rows): string
    {
        $lines = [];

        foreach ($rows as $row) {
            $lines[] = $row['en'] === '' ? $row['nl'] : $row['nl'] . self::SEPARATOR . $row['en'];
        }

        return self::toStored(implode("\n", $lines));
    }

    /**
     * One half of an option row, as it will be stored: one line, trimmed. A
     * line break would split the option in two, so every control character
     * becomes a space; anything that is not a string is empty.
     */
    public static function rowText(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
    }

    /** Whether a row's Dutch half holds the character that starts the English one. */
    public static function holdsSeparator(string $dutch): bool
    {
        return str_contains($dutch, self::SEPARATOR);
    }

    /** @return list<FormText> */
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
        foreach ($this->options as $option) {
            if ($option->nl === $value) {
                return true;
            }
        }

        return false;
    }

    /** The option matching a submitted value, or null — used to show it bilingually. */
    public function find(string $value): ?FormText
    {
        foreach ($this->options as $option) {
            if ($option->nl === $value) {
                return $option;
            }
        }

        return null;
    }
}
