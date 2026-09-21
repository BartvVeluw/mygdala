<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Database;
use App\Repository\BlockTranslationRepository;
use App\Service\Language\ContentLanguages;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\RichTextSanitizer;
use App\Service\Routing\RequestLanguage;

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
 * CHILD ROWS (phase 3B). A repeater's item, a card, a tag or an image owns
 * its own words exactly like a block row does: its table is the owner table
 * and its own id the owner id. BlockDefinition::childTables() says which
 * table hangs under which, so this class can find a block's child rows by
 * their parent: to load their words with the block's, and to remove their
 * words before the rows go.
 *
 * ONE QUERY PER PAGE. preloadSections() loads the words of every block on a
 * page at once, child rows included (SectionRegistry::renderPage() calls it);
 * a block read that was not preloaded costs one query for that block, never
 * one per language, per field or per item.
 *
 * INTEGRITY without a foreign key on the owner (see the migration
 * 20260917160000): save() refuses an owner row that does not exist,
 * deleteOwner() removes the words of an owner and of every child row under it
 * and runs inside the transaction of the delete it belongs to
 * (SectionRegistry::delete(), and each child delete endpoint), and orphans() /
 * purgeOrphans() find and remove whatever slipped past, for
 * scripts/block-translation-orphans.php.
 */
final class BlockLocalization
{
    /** @var array<string, array<int, array<string, array<string, string>>>> table => id => language => field => words */
    private static array $cache = [];

    /** @var array<string, array<int, true>> block rows whose child rows' words are loaded too */
    private static array $loadedTrees = [];

    /** @var array<string, string> sanitized rich text per table|id|field|language, so one body is purified once per request */
    private static array $sanitized = [];

    /** @var array<string, array<string, TranslatableField>>|null owner table => field key => field */
    private static ?array $registry = null;

    /** @var array<string, array<string, string>> parent table => child table => the child's column holding the parent id */
    private static array $children = [];

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
        self::$children = [];
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
     * The words a visitor of THIS request gets for one field: value() in the
     * request's language (App\Service\Routing\RequestLanguage). What a
     * *Content class hands its partial, so a partial prints one string and
     * never learns where the words are stored, which language is the default
     * or what an empty translation falls back to.
     */
    public static function text(string $ownerTable, int $ownerId, string $field): string
    {
        return self::value($ownerTable, $ownerId, $field, RequestLanguage::current());
    }

    /**
     * text() for a value that may come from more than one plain field of the
     * same owner, in order of preference: a quicknav label that is the
     * section's own short label, else its title.
     *
     * The first field with words IN THE REQUEST'S LANGUAGE wins; only when
     * none has any does it fall back to the default language, the same way.
     * So an English visitor gets the English title before the Dutch short
     * label: the fallback runs across languages last, never across fields
     * first.
     *
     * @param list<string> $fields declared plain fields, most preferred first
     */
    public static function first(string $ownerTable, int $ownerId, array $fields): string
    {
        foreach ($fields as $field) {
            if (self::field($ownerTable, $field)->isRich()) {
                throw new \InvalidArgumentException('first() combines plain fields only; "' . $field . '" is rich text.');
            }
        }

        $first = static function (string $code) use ($ownerTable, $ownerId, $fields): string {
            foreach ($fields as $field) {
                $words = self::raw($ownerTable, $ownerId, $field, $code);
                if ($words !== '') {
                    return $words;
                }
            }

            return '';
        };

        $words = $first(RequestLanguage::current());

        return $words !== '' ? $words : $first(self::defaultLanguage());
    }

    /**
     * text() for every field one owner table declares, keyed by field: what a
     * *Content class hands its partial. Owner id 0 gives every field empty,
     * the shape of a block with nothing to show.
     *
     * @return array<string, string> field key => words in the request's language
     */
    public static function words(string $ownerTable, int $ownerId): array
    {
        $words = [];
        foreach (array_keys(self::fields($ownerTable)) as $field) {
            $words[$field] = $ownerId > 0 ? self::text($ownerTable, $ownerId, $field) : '';
        }

        return $words;
    }

    /**
     * Does this owner have words for one field in the DEFAULT language? The
     * question a *Content class asks before it shows an optional part — a
     * secondary button, a badge — because the default language decides
     * whether a part is there at all, whatever language is being read
     * (docs/multilingual/ARCHITECTURE.md, "De standaardtaal beslist").
     */
    public static function hasDefaultWords(string $ownerTable, int $ownerId, string $field): bool
    {
        return $ownerId > 0 && self::raw($ownerTable, $ownerId, $field, self::defaultLanguage()) !== '';
    }

    /**
     * Does this owner have words, in the default language, for every field
     * its table declares required? What a *Content class asks of each child
     * row before it hands the row to a partial: the default language decides
     * whether an item is there at all, exactly as it decides for a block, so
     * a question that exists only as a translation shows nothing.
     */
    public static function hasRequiredWords(string $ownerTable, int $ownerId): bool
    {
        $default = self::defaultLanguage();

        foreach (self::fields($ownerTable) as $key => $field) {
            if ($field->required && self::raw($ownerTable, $ownerId, $key, $default) === '') {
                return false;
            }
        }

        return true;
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
     * preloadBlocks() for the blocks of one page, straight from its
     * page_sections rows.
     *
     * @param list<array<string, mixed>> $pageSections
     */
    public static function preloadSections(array $pageSections): void
    {
        $blockIds = [];

        foreach ($pageSections as $pageSection) {
            $definition = BlockDefinitions::get((string) ($pageSection['section_type'] ?? ''));
            $table = $definition?->contentTable();

            if ($table !== null) {
                $blockIds[$table][] = (int) ($pageSection['section_id'] ?? 0);
            }
        }

        self::preloadBlocks($blockIds);
    }

    /**
     * Load the words of whole blocks in ONE query: the words of their own
     * rows, and of every row in the child tables their definitions declare,
     * however deep. Afterwards every child row of those blocks counts as
     * loaded, also the ones without a single word, so reading an item never
     * costs a query of its own. Blocks already loaded are left alone.
     *
     * @param array<string, list<int>> $blockIds content table => block row ids
     */
    public static function preloadBlocks(array $blockIds): void
    {
        $owners = [];
        $children = [];
        $trees = [];

        foreach ($blockIds as $table => $ids) {
            $table = (string) $table;
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

            if (self::fields($table) !== []) {
                foreach ($ids as $id) {
                    if (!array_key_exists($id, self::$cache[$table] ?? [])) {
                        $owners[$table][] = $id;
                    }
                }
            }

            $unloaded = array_values(array_filter($ids, static fn (int $id): bool => !isset(self::$loadedTrees[$table][$id])));
            foreach (self::descendants($table) as $descendant) {
                if ($unloaded !== [] && self::fields($descendant['table']) !== []) {
                    $children[] = ['chain' => $descendant['chain'], 'root_ids' => $unloaded];
                    $trees[$table] = $unloaded;
                }
            }
        }

        if ($owners === [] && $children === []) {
            return;
        }

        try {
            $rows = (new BlockTranslationRepository())->findForOwnerTrees($owners, $children);
        } catch (\Throwable $e) {
            error_log('[BlockLocalization] block words could not be read: ' . $e->getMessage());
            $rows = [];
        }

        foreach ($owners as $table => $ids) {
            foreach ($ids as $id) {
                self::$cache[$table][$id] = $rows[$table][$id] ?? [];
            }
        }

        foreach ($children as $child) {
            $table = $child['chain'][0][0];
            foreach ($rows[$table] ?? [] as $id => $words) {
                self::$cache[$table][$id] = $words;
            }
        }

        foreach ($trees as $table => $ids) {
            foreach ($ids as $id) {
                self::$loadedTrees[$table][$id] = true;
            }
        }
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
     * Remove every language of one owner AND of every child row under it,
     * however deep (BlockDefinition::childTables()): a block with its items,
     * or a card with its tags.
     *
     * Call it BEFORE the rows are deleted, in the same transaction: the child
     * ids are looked up through their parent here, and once the database's
     * ON DELETE CASCADE has removed the child rows there is nothing left to
     * find them by. SectionRegistry::delete() calls this for every block it
     * deletes, for every content table, declared fields or not, and every
     * endpoint that deletes a single child row calls it for that row. Throws,
     * so that transaction rolls back.
     */
    public static function deleteOwner(string $ownerTable, int $ownerId): void
    {
        if ($ownerId < 1) {
            return;
        }

        $repository = new BlockTranslationRepository();
        $owners = [$ownerTable => [$ownerId]];

        try {
            // Parents come before their children in descendants(), so the
            // ids a child table is looked up by are always known already.
            foreach (self::descendants($ownerTable) as $descendant) {
                [$child, $column] = $descendant['chain'][0];
                $parent = $descendant['chain'][1][0] ?? $ownerTable;

                $owners[$child] = $repository->findChildOwnerIds($child, $column, $owners[$parent] ?? []);
            }

            $repository->deleteOwners($owners);
        } finally {
            foreach ($owners as $table => $ids) {
                foreach ($ids as $id) {
                    self::forget($table, $id);
                }
            }
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
        self::$loadedTrees = [];
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
     * Test seam: pretend the block registry declares exactly these fields and
     * child tables. null goes back to the real registry.
     *
     * @param array<string, list<TranslatableField>>|null $fields owner table => fields
     * @param array<string, array{parent: string, column: string}> $childTables as BlockDefinition::childTables()
     */
    public static function overrideRegistryForTests(?array $fields, array $childTables = []): void
    {
        self::$registry = $fields === null ? null : self::index($fields);
        self::$children = $fields === null ? [] : self::indexChildren($childTables);
        self::clearCache();
    }

    /** @return array<string, array<string, TranslatableField>> */
    private static function registry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        $declared = [];
        $childTables = [];
        foreach (BlockDefinitions::all() as $definition) {
            // A table two block types share (item_gallery, project_cards) is
            // declared by both; the first declaration is used, and
            // BlockDefinitionContractTest::testBlocksThatShareATableDeclareTheSameFields
            // proves the two agree.
            $declared += $definition->translatableFields();
            $childTables += $definition->childTables();
        }

        self::$children = self::indexChildren($childTables);

        return self::$registry = self::index($declared);
    }

    /**
     * @param array<string, array{parent: string, column: string}> $childTables
     * @return array<string, array<string, string>> parent table => child table => column
     */
    private static function indexChildren(array $childTables): array
    {
        $children = [];
        foreach ($childTables as $child => $link) {
            $children[(string) $link['parent']][(string) $child] = (string) $link['column'];
        }

        return $children;
    }

    /**
     * Every table under one owner table, parents before their children, each
     * with its chain of [table, column holding the parent's id] up to that
     * owner table.
     *
     * @return list<array{table: string, chain: list<array{0: string, 1: string}>}>
     */
    private static function descendants(string $ownerTable): array
    {
        self::registry();

        $found = [];
        $seen = [$ownerTable => true];
        $queue = [[$ownerTable, []]];

        while ($queue !== []) {
            [$parent, $chain] = array_shift($queue);

            foreach (self::$children[$parent] ?? [] as $child => $column) {
                if (isset($seen[$child])) {
                    continue;
                }

                $seen[$child] = true;
                $childChain = array_merge([[$child, $column]], $chain);
                $found[] = ['table' => $child, 'chain' => $childChain];
                $queue[] = [$child, $childChain];
            }
        }

        return $found;
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
