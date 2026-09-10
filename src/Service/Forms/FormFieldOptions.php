<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * The choices of a select or radio field: an ordered, closed list of
 * bilingual labels.
 *
 * STORED AS ONE TEXT COLUMN, one option per line, `NL|EN` with the English
 * half optional. That is not a shortcut around a proper table — it is the
 * whole configuration a Forms V1 choice field has, an editor types it as a
 * list, and a child table would buy nothing but three more write endpoints.
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
            [$nl, $en] = array_pad(explode('|', $line, 2), 2, null);

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
                : $option->nl . '|' . $option->en;
        }

        return implode("\n", $lines);
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
