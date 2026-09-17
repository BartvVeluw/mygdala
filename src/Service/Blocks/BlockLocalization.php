<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Database;
use App\Repository\BlockTranslationRepository;
use App\Service\Language\ContentLanguages;
use App\Service\Language\LanguageCode;
use App\Service\Language\LanguageRegistry;
use App\Service\Language\LocalizedValue;
use App\Service\Language\SiteLanguages;
use App\Service\RichTextSanitizer;

/**
 * THE way into a content block's words in any website language (Multilingual
 * 2.0 phase 3, docs/multilingual/ARCHITECTURE.md). Nothing else reads or
 * writes `block_translations`: not a *Content class, not a partial, not an
 * endpoint. The block-side twin of App\Service\PageLocalization, and it
 * follows the same rules.
 *
 * WHOSE WORDS. An owner is a row in a block's content table, named by that
 * table and its id ('cta_bands', 12). Which tables and which fields exist is
 * read from the closed block registry, BlockDefinition::translatableFields();
 * a table or field no registered block declares is refused on write and
 * reads as nothing. `owner_table` therefore never comes from a request.
 *
 * THE FALLBACK, per field, stated once:
 *
 *     the asked-for language  ->  the default language  ->  ''
 *
 * value() is that rule, for a visitor. raw() is the stored words with no
 * fallback, for an editor. name() is the one admin-only step beyond it.
 * Rich-text fields come out of value() as RichTextSanitizer output, whatever
 * is stored.
 *
 * READS NEVER THROW: a lookup that fails is logged and reads as "no words".
 * Writes do throw, because an editor must hear that a save did not happen.
 *
 * ONE QUERY PER PAGE. preloadSections() loads the words of every block on a
 * page at once (SectionRegistry::renderPage() calls it); a block read that
 * was not preloaded costs one query for that block, never one per language
 * or per field.
 *
 * INTEGRITY without a foreign key on the owner (see the migration
 * 20260917160000): save() refuses an owner row that does not exist,
 * deleteOwner() runs inside SectionRegistry::delete()'s transaction, and
 * orphans() / purgeOrphans() find and remove whatever slipped past, for
 * scripts/block-translation-orphans.php.
 */
final class BlockLocalization
{
    /** @var array<string, array<int, array<string, array<string, string>>>> table => id => language => field => words */
    private static array $cache = [];

    /** @var array<string, string> sanitized rich text per table|id|field|language, so one body is purified once per request */
    private static array $sanitized = [];

    /** @var array<string, array<string, TranslatableField>>|null owner table => field key => field */
    private static ?array $registry = null;

    // ------------------------------------------------------------ the registry

    /**
     * The fields one owner table declares, keyed by field key; [] for a table
     * no registered block declares.
     *
     * @return array<string, TranslatableField>
     */
    public static function fields(string $ownerTable): array
    {
        return self::registry()[$ownerTable] ?? [];
    }

    /** @return list<string> every owner table a registered block declares */
    public static function ownerTables(): array
    {
        return array_keys(self::registry());
    }

    /** Forget the registry; BlockDefinitions::reset() calls this when modules change. */
    public static function forgetRegistry(): void
    {
        self::$registry = null;
    }

    // ------------------------------------------------------------ reading

    /**
     * Every language one owner has words in.
     *
     * @return array<string, array<string, string>> language => field => words
     */
    public static function translations(string $ownerTable, int $ownerId): array
    {
        if ($ownerId < 1 || self::fields($ownerTable) === []) {
            return [];
        }

        if (!array_key_exists($ownerId, self::$cache[$ownerTable] ?? [])) {
            self::load([$ownerTable => [$ownerId]]);
        }

        return self::$cache[$ownerTable][$ownerId] ?? [];
    }

    /**
     * The words stored for one field in one language, with NO fallback: what
     * an editor sees. '' is "not written in this language".
     */
    public static function raw(string $ownerTable, int $ownerId, string $field, string $languageCode): string
    {
        self::field($ownerTable, $field);

        $words = self::translations($ownerTable, $ownerId)[$languageCode][$field] ?? '';

        return trim($words) === '' ? '' : $words;
    }

    /**
     * The words a visitor gets for one field in one language: that
     * language's own, else the default language's, else ''. Rich text is
     * sanitized on the way out.
     */
    public static function value(string $ownerTable, int $ownerId, string $field, string $languageCode): string
    {
        $spec = self::field($ownerTable, $field);

        $language = $languageCode;
        $words = self::raw($ownerTable, $ownerId, $field, $language);

        if ($words === '') {
            $language = self::defaultLanguage();
            $words = $language === $languageCode ? '' : self::raw($ownerTable, $ownerId, $field, $language);
        }

        if ($words === '' || !$spec->isRich()) {
            return $words;
        }

        $key = $ownerTable . '|' . $ownerId . '|' . $field . '|' . $language;

        return self::$sanitized[$key] ??= (string) RichTextSanitizer::sanitize($words);
    }

    /**
     * What the CMS calls this field of an owner, in its lists and labels: the
     * words in the default language. The one step beyond value(), and
     * admin-only: when the default language has none, the first language
     * that has some, in the registry's order.
     */
    public static function name(string $ownerTable, int $ownerId, string $field): string
    {
        $name = self::value($ownerTable, $ownerId, $field, self::defaultLanguage());
        if ($name !== '') {
            return $name;
        }

        $translations = self::translations($ownerTable, $ownerId);
        $order = array_map(static fn ($language): string => $language->code, SiteLanguages::all());

        foreach (array_unique(array_merge($order, array_keys($translations))) as $code) {
            if (self::raw($ownerTable, $ownerId, $field, (string) $code) !== '') {
                return self::value($ownerTable, $ownerId, $field, (string) $code);
            }
        }

        return '';
    }

    /**
     * TEMPORARY OUTPUT ADAPTER for the V1 public language switch, until the
     * frontend flip (docs/multilingual/ARCHITECTURE.md), and the block-side
     * twin of PageLocalization::bilingual().
     *
     * The public partials still print a field as a `data-nl`/`data-en` pair
     * and let assets/js/core.js swap it. This builds that pair from
     * `block_translations`, each half already resolved by value(), so the
     * words shown first, the switch and the fallback all follow the one rule
     * above. A partial prints the result through App\Service\Language\SiteText
     * and never learns where the words are stored or which language is the
     * default. The two codes come from the closed V1 registry, not from here.
     */
    public static function bilingual(string $ownerTable, int $ownerId, string $field): LocalizedValue
    {
        $values = [];
        foreach (LanguageRegistry::codes() as $code) {
            $values[$code] = self::value($ownerTable, $ownerId, $field, $code);
        }

        return LocalizedValue::of($values);
    }

    /**
     * Load the words of many owners in one query. Owners already loaded, and
     * tables no block declares, are left alone.
     *
     * @param array<string, list<int>> $ownerIds owner table => row ids
     */
    public static function preload(array $ownerIds): void
    {
        $missing = [];

        foreach ($ownerIds as $table => $ids) {
            if (self::fields((string) $table) === []) {
                continue;
            }

            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id > 0 && !array_key_exists($id, self::$cache[$table] ?? [])) {
                    $missing[(string) $table][] = $id;
                }
            }
        }

        if ($missing !== []) {
            self::load($missing);
        }
    }

    /**
     * preload() for the blocks of one page, straight from its page_sections
     * rows: the content row of every block whose definition declares
     * translatable fields for its content table.
     *
     * @param list<array<string, mixed>> $pageSections
     */
    public static function preloadSections(array $pageSections): void
    {
        $ownerIds = [];

        foreach ($pageSections as $pageSection) {
            $definition = BlockDefinitions::get((string) ($pageSection['section_type'] ?? ''));
            $table = $definition?->contentTable();

            if ($table !== null && self::fields($table) !== []) {
                $ownerIds[$table][] = (int) ($pageSection['section_id'] ?? 0);
            }
        }

        self::preload($ownerIds);
    }

    /**
     * The website's default language: the language every field falls back
     * to. When the registry cannot answer, the V1 adapter's answer.
     */
    public static function defaultLanguage(): string
    {
        try {
            return SiteLanguages::defaultCode();
        } catch (\RuntimeException) {
            return ContentLanguages::primary();
        }
    }

    // ------------------------------------------------------------ writing

    /**
     * What is wrong with one language's submitted words for one owner table,
     * per field: TranslatableField::MISSING (only in the default language) or
     * TranslatableField::TOO_LONG. Fields not submitted count as empty.
     *
     * @param array<string, string|null> $values
     * @return array<string, string> field key => problem
     */
    public static function problems(string $ownerTable, string $languageCode, array $values): array
    {
        $inDefaultLanguage = $languageCode === self::defaultLanguage();
        $problems = [];

        foreach (self::fields($ownerTable) as $key => $field) {
            $problem = $field->problem($values[$key] ?? null, $inDefaultLanguage);
            if ($problem !== null) {
                $problems[$key] = $problem;
            }
        }

        return $problems;
    }

    /**
     * The CMS message keys for problems(), one per kind of problem, in the
     * order an editor should fix them.
     *
     * @param array<string, string> $problems from problems()
     * @return list<string> App\Service\Language\AdminTranslator keys
     */
    public static function messageKeys(array $problems): array
    {
        $keys = [];

        if (in_array(TranslatableField::MISSING, $problems, true)) {
            $keys[] = 'validation.veld_verplicht';
        }

        if (in_array(TranslatableField::TOO_LONG, $problems, true)) {
            $keys[] = 'validation.text_too_long';
        }

        return $keys;
    }

    /**
     * Store one language's words for one owner: every declared field, as
     * given. A field left out or without words has no row afterwards, and the
     * other languages are not touched.
     *
     * Takes part in a transaction already open on the shared connection, and
     * opens its own otherwise.
     *
     * @param array<string, string|null> $values field key => words
     *
     * @throws \InvalidArgumentException for an undeclared table or field, an
     *         owner row that does not exist, a language that is not
     *         registered, or words longer than the field allows
     */
    public static function save(string $ownerTable, int $ownerId, string $languageCode, array $values): void
    {
        $fields = self::fields($ownerTable);
        $code = LanguageCode::normalise($languageCode);

        if ($fields === [] || $ownerId < 1 || $code === null || !SiteLanguages::exists($code)) {
            throw new \InvalidArgumentException('Block words can only be stored for a declared block table in a registered website language.');
        }

        foreach (array_keys($values) as $key) {
            self::field($ownerTable, (string) $key);
        }

        if (in_array(TranslatableField::TOO_LONG, self::problems($ownerTable, '', $values), true)) {
            throw new \InvalidArgumentException('A block field in "' . $ownerTable . '" is longer than its declared maximum.');
        }

        $stored = [];
        foreach ($fields as $key => $field) {
            $words = $field->normalise($values[$key] ?? null);
            if ($words !== '') {
                $stored[$key] = $words;
            }
        }

        $db = Database::connection();
        $repository = new BlockTranslationRepository($db);

        if (!$repository->ownerExists($ownerTable, $ownerId)) {
            throw new \InvalidArgumentException('Block words need an existing owner row: ' . $ownerTable . ' #' . $ownerId . ' does not exist.');
        }

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $repository->replaceLanguage($ownerTable, $ownerId, $code, $stored);

            if ($ownTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        } finally {
            self::forget($ownerTable, $ownerId);
        }
    }

    /**
     * Remove every language of one owner. SectionRegistry::delete() calls
     * this for every block it deletes, inside its transaction and for every
     * content table, declared fields or not, so no block can leave words
     * behind. Throws, so that transaction rolls back.
     */
    public static function deleteOwner(string $ownerTable, int $ownerId): void
    {
        if ($ownerId < 1) {
            return;
        }

        try {
            (new BlockTranslationRepository())->deleteOwner($ownerTable, $ownerId);
        } finally {
            self::forget($ownerTable, $ownerId);
        }
    }

    // ------------------------------------------------------------ integrity

    /**
     * Everything in `block_translations` that no longer belongs anywhere:
     *
     *  - missing_owner: words of a declared table whose owner row is gone;
     *  - undeclared_field: words in a field the table does not declare (any more);
     *  - unregistered_table: words of a table no registered block declares,
     *    which is also what a switched-off module's block looks like.
     *
     * Only the first kind is ever purged. The other two are data that
     * outlived its code, and the rule for that is: report, never throw away.
     *
     * @return array{missing_owner: list<array{owner_table: string, owner_id: int, rows: int}>, undeclared_field: list<array{owner_table: string, field: string, rows: int}>, unregistered_table: list<array{owner_table: string, rows: int}>}
     */
    public static function orphans(): array
    {
        $repository = new BlockTranslationRepository();
        $report = ['missing_owner' => [], 'undeclared_field' => [], 'unregistered_table' => []];

        foreach (self::registry() as $table => $fields) {
            foreach ($repository->findMissingOwners($table) as $row) {
                $report['missing_owner'][] = ['owner_table' => $table] + $row;
            }

            foreach ($repository->findUndeclaredFields($table, array_keys($fields)) as $row) {
                $report['undeclared_field'][] = ['owner_table' => $table] + $row;
            }
        }

        $report['unregistered_table'] = $repository->findUnregisteredOwnerTables(self::ownerTables());

        return $report;
    }

    /** Delete the missing_owner rows of orphans(); idempotent. Returns the rows removed. */
    public static function purgeOrphans(): int
    {
        $repository = new BlockTranslationRepository();
        $removed = 0;

        foreach (self::ownerTables() as $table) {
            $removed += $repository->deleteMissingOwners($table);
        }

        self::clearCache();

        return $removed;
    }

    // ------------------------------------------------------------ caches and seams

    /** Drop the per-request caches. Every block *Content::clearCache() of a converted block calls this. */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$sanitized = [];
    }

    /**
     * Test seam: pretend one owner's stored words are exactly $translations,
     * without a database. Reset with clearCache() in tearDown().
     *
     * @param array<string, array<string, string>> $translations language => field => words
     */
    public static function overrideForTests(string $ownerTable, int $ownerId, array $translations): void
    {
        self::forget($ownerTable, $ownerId);
        self::$cache[$ownerTable][$ownerId] = $translations;
    }

    /**
     * Test seam: pretend the block registry declares exactly these fields.
     * null goes back to the real registry.
     *
     * @param array<string, list<TranslatableField>>|null $fields owner table => fields
     */
    public static function overrideRegistryForTests(?array $fields): void
    {
        self::$registry = $fields === null ? null : self::index($fields);
        self::clearCache();
    }

    /** @return array<string, array<string, TranslatableField>> */
    private static function registry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        $declared = [];
        foreach (BlockDefinitions::all() as $definition) {
            // A table two block types share (item_gallery, project_cards) is
            // declared by both; the first declaration is used, and
            // BlockTranslatableFieldsContractTest proves the two agree.
            $declared += $definition->translatableFields();
        }

        return self::$registry = self::index($declared);
    }

    /**
     * @param array<string, list<TranslatableField>> $declared
     * @return array<string, array<string, TranslatableField>>
     */
    private static function index(array $declared): array
    {
        $registry = [];
        foreach ($declared as $table => $fields) {
            foreach ($fields as $field) {
                $registry[(string) $table][$field->key] = $field;
            }
        }

        return $registry;
    }

    private static function field(string $ownerTable, string $key): TranslatableField
    {
        return self::fields($ownerTable)[$key]
            ?? throw new \InvalidArgumentException('Block table "' . $ownerTable . '" declares no translatable field "' . $key . '".');
    }

    /** @param array<string, list<int>> $ownerIds */
    private static function load(array $ownerIds): void
    {
        try {
            $rows = (new BlockTranslationRepository())->findForOwners($ownerIds);
        } catch (\Throwable $e) {
            error_log('[BlockLocalization] block words could not be read: ' . $e->getMessage());
            $rows = [];
        }

        foreach ($ownerIds as $table => $ids) {
            foreach ($ids as $id) {
                self::$cache[$table][(int) $id] = $rows[$table][(int) $id] ?? [];
            }
        }
    }

    private static function forget(string $ownerTable, int $ownerId): void
    {
        unset(self::$cache[$ownerTable][$ownerId]);

        $prefix = $ownerTable . '|' . $ownerId . '|';
        foreach (array_keys(self::$sanitized) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$sanitized[$key]);
            }
        }
    }
}
