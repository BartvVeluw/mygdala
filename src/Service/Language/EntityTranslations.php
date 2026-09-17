<?php

declare(strict_types=1);

namespace App\Service\Language;

use App\Repository\EntityTranslationRepository;

/**
 * The words of ONE typed translation table, in every website language
 * (Multilingual 2.0 phase 4, docs/multilingual/ARCHITECTURE.md): the
 * per-table half of what App\Service\PageLocalization is for pages.
 *
 * It knows no domain. A domain holds one instance per table it owns and
 * gives it a typed face: App\Service\NavigationLocalization (menu items),
 * App\Service\FooterLocalization (columns and links),
 * App\Service\Forms\FormLocalization (forms, fields and options). Nothing
 * else reads or writes those tables.
 *
 * THE FALLBACK is App\Service\Language\LanguageFallback's: the asked-for
 * language, the default language, ''. value() is that rule for a visitor,
 * raw() the stored words with no fallback for an editor, and name() the one
 * admin-only step beyond it.
 *
 * READS NEVER THROW: a lookup that fails is logged and reads as "no words",
 * so a public request degrades rather than dies. Writes do throw, because an
 * editor must hear that a save did not happen.
 *
 * One query per owner, or one for a whole list (preload()).
 */
final class EntityTranslations
{
    /** @var array<int, array<string, array<string, string>>> owner id => language code => field => words (non-empty only) */
    private array $cache = [];

    public function __construct(private readonly TranslationTable $table)
    {
    }

    public function table(): TranslationTable
    {
        return $this->table;
    }

    /**
     * Every language this owner has words in.
     *
     * @return array<string, array<string, string>> language code => field => words; empty fields are absent
     */
    public function words(int $ownerId): array
    {
        if ($ownerId < 1) {
            return [];
        }

        if (!array_key_exists($ownerId, $this->cache)) {
            $this->load([$ownerId]);
        }

        return $this->cache[$ownerId] ?? [];
    }

    /** Has this owner any words in this language? */
    public function has(int $ownerId, string $languageCode): bool
    {
        return ($this->words($ownerId)[$languageCode] ?? []) !== [];
    }

    /**
     * The words stored for one field in one language, with NO fallback: what
     * an editor sees. '' is "not written in this language".
     */
    public function raw(int $ownerId, string $field, string $languageCode): string
    {
        $this->assertField($field);

        return $this->words($ownerId)[$languageCode][$field] ?? '';
    }

    /** The words a visitor gets for one field in one language, with the fallback. */
    public function value(int $ownerId, string $field, string $languageCode): string
    {
        return LanguageFallback::resolve($this->column($ownerId, $field), $languageCode);
    }

    /** What the CMS calls this owner by one of its fields (LanguageFallback::name()). */
    public function name(int $ownerId, string $field): string
    {
        return LanguageFallback::name($this->column($ownerId, $field));
    }

    /** The temporary V1 `data-nl`/`data-en` pair of one field (LanguageFallback::bilingual()). */
    public function bilingual(int $ownerId, string $field): LocalizedValue
    {
        return LanguageFallback::bilingual($this->column($ownerId, $field));
    }

    /**
     * Load the words of many owners in one query, for a screen or a menu that
     * is about to print every one of them. Owners already loaded are left
     * alone.
     *
     * @param list<int> $ownerIds
     */
    public function preload(array $ownerIds): void
    {
        $missing = array_values(array_filter(
            array_unique(array_map('intval', $ownerIds)),
            fn (int $id): bool => $id > 0 && !array_key_exists($id, $this->cache)
        ));

        if ($missing !== []) {
            $this->load($missing);
        }
    }

    /**
     * What is wrong with one language's input, by field: 'missing' for a
     * required field that is empty IN THE DEFAULT LANGUAGE (a translation is
     * optional by definition, because it falls back), 'too_long' for a value
     * over the declared length.
     *
     * @param array<string, string|null> $values field => input
     * @param list<string>               $required the fields the default language must have
     * @return array<string, string> field => 'missing'|'too_long'
     */
    public function problems(string $languageCode, array $values, array $required = []): array
    {
        $problems = [];
        $isDefault = $languageCode === LanguageFallback::defaultLanguage();

        foreach ($this->table->fields as $field => $maxLength) {
            $value = trim((string) ($values[$field] ?? ''));

            if ($isDefault && $value === '' && in_array($field, $required, true)) {
                $problems[$field] = 'missing';
            } elseif (mb_strlen($value) > $maxLength) {
                $problems[$field] = 'too_long';
            }
        }

        return $problems;
    }

    /**
     * Store one language's words for one owner.
     *
     * The fields given are written; a declared field that is NOT given keeps
     * what is stored for this language, so a form that does not show a field
     * cannot empty it. Every language other than this one stays exactly as it
     * is. Values are trimmed, an empty field is stored as NULL, and a language
     * left with no words at all has no row: "not translated" and "translated
     * as nothing" are one state.
     *
     * Takes part in a transaction already open on the shared connection.
     *
     * @param array<string, string|null> $values
     *
     * @throws \InvalidArgumentException for an unregistered language, an undeclared field or a value over its length
     */
    public function save(int $ownerId, string $languageCode, array $values): void
    {
        $code = LanguageCode::normalise($languageCode);

        if ($ownerId < 1 || $code === null || !SiteLanguages::exists($code)) {
            throw new \InvalidArgumentException('Words can only be stored for an existing row in a registered website language.');
        }

        foreach (array_keys($values) as $field) {
            $this->assertField((string) $field);
        }

        $repository = new EntityTranslationRepository($this->table);
        $stored = $repository->findForOwners([$ownerId])[$ownerId][$code] ?? [];

        $row = [];
        foreach ($this->table->fields as $field => $maxLength) {
            $given = array_key_exists($field, $values);
            $value = trim((string) ($given ? $values[$field] : ($stored[$field] ?? null)));

            // Only what is being written is measured: a stored value of a
            // field this save does not touch is kept as it is.
            if ($given && mb_strlen($value) > $maxLength) {
                throw new \InvalidArgumentException('Field "' . $field . '" of ' . $this->table->name . ' is longer than ' . $maxLength . ' characters.');
            }

            $row[$field] = $value === '' ? null : $value;
        }

        if (array_filter($row, static fn (?string $value): bool => $value !== null) === []) {
            $repository->delete($ownerId, $code);
        } else {
            $repository->save($ownerId, $code, $row);
        }

        unset($this->cache[$ownerId]);
    }

    /** Forget one owner's words, after its row was deleted or rewritten outside save(). */
    public function forget(int $ownerId): void
    {
        unset($this->cache[$ownerId]);
    }

    /** Drop the per-request cache. */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Test seam: pretend this owner's words are exactly $words, without a
     * database. Reset with clearCache().
     *
     * @param array<string, array<string, string|null>> $words language code => field => words
     */
    public function overrideForTests(int $ownerId, array $words): void
    {
        $this->cache[$ownerId] = self::clean($words);
    }

    /** @return array<string, string> language code => words of one field */
    private function column(int $ownerId, string $field): array
    {
        $this->assertField($field);

        $column = [];
        foreach ($this->words($ownerId) as $code => $fields) {
            if (($fields[$field] ?? '') !== '') {
                $column[$code] = $fields[$field];
            }
        }

        return $column;
    }

    /** @param list<int> $ownerIds */
    private function load(array $ownerIds): void
    {
        try {
            $rows = (new EntityTranslationRepository($this->table))->findForOwners($ownerIds);
        } catch (\Throwable $e) {
            error_log('[EntityTranslations] ' . $this->table->name . ' could not be read: ' . $e->getMessage());
            $rows = [];
        }

        foreach ($ownerIds as $ownerId) {
            $this->cache[$ownerId] = self::clean($rows[$ownerId] ?? []);
        }
    }

    /**
     * @param array<string, array<string, string|null>> $rows
     * @return array<string, array<string, string>>
     */
    private static function clean(array $rows): array
    {
        $words = [];
        foreach ($rows as $code => $fields) {
            foreach ($fields as $field => $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $words[(string) $code][(string) $field] = $value;
                }
            }
        }

        return $words;
    }

    private function assertField(string $field): void
    {
        if (!$this->table->has($field)) {
            throw new \InvalidArgumentException('Table ' . $this->table->name . ' has no localized field "' . $field . '".');
        }
    }
}
