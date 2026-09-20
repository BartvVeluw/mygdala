<?php

declare(strict_types=1);

namespace App\Service\Language;

/**
 * The declaration of one TYPED translation table of Multilingual 2.0
 * (docs/multilingual/ARCHITECTURE.md): its name, the column that names the
 * owner row, and the localized fields with their maximum length in
 * characters.
 *
 * Every such table has the same shape, which is why one declaration is enough
 * for App\Repository\EntityTranslationRepository to write its SQL:
 *
 *     id, <owner column>, language_code, <field>…, created_at, updated_at
 *     UNIQUE(<owner column>, language_code)
 *     FK <owner column>  -> the owner,               ON DELETE CASCADE
 *     FK language_code   -> site_languages.code,     ON DELETE RESTRICT
 *
 * CLOSED. A declaration is written in code by the domain that owns the table
 * (App\Service\NavigationLocalization, App\Service\FooterLocalization,
 * App\Service\Forms\FormLocalization), so a table, column or field name never
 * comes from a request or a database row. The constructor refuses anything
 * that is not a plain lowercase identifier, because these names reach SQL.
 */
final class TranslationTable
{
    /**
     * The one field that is an ADDRESS rather than words (Multilingual 2.0
     * phase 6, docs/multilingual/ROUTING.md).
     *
     * A table that declares it is routable per language: its rows have a
     * public URL of their own in every language they have one for. It is
     * declared like any other field — same column, same width check, same
     * save path — and read completely differently, because a URL may never
     * fall back to another language's. App\Service\Language\EntityTranslations
     * refuses to hand it to any reader that applies a fallback.
     */
    public const SLUG = 'slug';

    private const IDENTIFIER = '/\A[a-z][a-z0-9_]{0,63}\z/';

    /**
     * @param array<string, int> $fields field => maximum length in characters
     */
    public function __construct(
        public readonly string $name,
        public readonly string $ownerColumn,
        public readonly array $fields,
    ) {
        if ($fields === []) {
            throw new \InvalidArgumentException('A translation table declares at least one field.');
        }

        foreach (array_merge([$name, $ownerColumn], array_keys($fields)) as $identifier) {
            if (!is_string($identifier) || preg_match(self::IDENTIFIER, $identifier) !== 1) {
                throw new \InvalidArgumentException('Not a table, column or field name: ' . var_export($identifier, true));
            }
        }

        foreach ($fields as $field => $maxLength) {
            if (!is_int($maxLength) || $maxLength < 1) {
                throw new \InvalidArgumentException('Field "' . $field . '" needs a positive maximum length.');
            }
        }

        if (in_array($ownerColumn, array_keys($fields), true) || isset($fields['language_code'])) {
            throw new \InvalidArgumentException('A localized field cannot share a name with the owner or language column.');
        }
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    /** Do this table's rows have a public address per language? */
    public function hasSlug(): bool
    {
        return $this->has(self::SLUG);
    }

    public function maxLength(string $field): int
    {
        if (!$this->has($field)) {
            throw new \InvalidArgumentException('Table ' . $this->name . ' has no localized field "' . $field . '".');
        }

        return $this->fields[$field];
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        return array_keys($this->fields);
    }
}
