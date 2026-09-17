<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Moves every page's text out of the fixed Dutch/English columns on `pages`
 * into `page_translations` (20260917140000), and drops those columns
 * (Multilingual 2.0 phase 2, docs/multilingual/ARCHITECTURE.md).
 *
 * WHAT MOVES, and to which language. The columns keep the meaning V1 gave
 * them, whatever the site's default language is (App\Service\Language\
 * LocalizedValue::ofDutchEnglish()):
 *
 *   title, meta_title, meta_description           -> the `nl` row
 *   title_en, meta_title_en, meta_description_en  -> the `en` row
 *
 * A language gets a row only when at least one of its three fields holds
 * words. NULL, '' and whitespace all mean "nothing here" to every reader, so
 * they all become NULL, and a language with nothing at all gets no row —
 * which App\Service\PageLocalization reads exactly as before: the default
 * language's words. A value with words is copied as it is, byte for byte.
 *
 * NOTHING ELSE ABOUT A PAGE CHANGES. Its id, content_key, slug, status,
 * route and settings stay where they are; only the six text columns go.
 *
 * WHY THE DROP IS IN THE SAME MIGRATION. Two copies of a page's text that are
 * both still there is how the second one quietly goes stale (phase 0B: old
 * columns fall per domain, in the migration of the phase that converts the
 * domain). The code in this commit reads and writes only the new table.
 *
 * SAFE TO RUN TWICE. A row that already exists is never overwritten, and once
 * the columns are gone there is nothing left to do.
 *
 * REFUSES RATHER THAN LOSES. A row can only name a language the registry has
 * (a foreign key). If words in a column could not be moved because that
 * language is missing from site_languages, the migration stops before the
 * drop and says so, instead of dropping them.
 */
final class MovePageTextIntoPageTranslations extends AbstractMigration
{
    /** The page_translations text columns, in the order of LEGACY_COLUMNS. */
    private const TARGETS = ['title', 'meta_title', 'meta_description'];

    /**
     * language code => the legacy columns that hold its title, meta title and
     * meta description, in the order of TARGETS. The only place the old column
     * names are still written down.
     */
    private const LEGACY_COLUMNS = [
        'nl' => ['title', 'meta_title', 'meta_description'],
        'en' => ['title_en', 'meta_title_en', 'meta_description_en'],
    ];

    public function up(): void
    {
        if (!$this->hasTable('pages') || !$this->hasTable('page_translations') || !$this->hasTable('site_languages')) {
            return;
        }

        $legacy = $this->legacyColumnsStillThere();
        if ($legacy === []) {
            return;
        }

        foreach (self::LEGACY_COLUMNS as $code => $columns) {
            $this->copyLanguage($code, $columns);
        }

        $this->assertNothingIsLeftBehind();

        $table = $this->table('pages');
        foreach ($legacy as $column) {
            $table->removeColumn($column);
        }
        $table->update();
    }

    /**
     * Forward-only (db/migrations/CLAUDE.md). The text lives in
     * page_translations now; putting columns back would not put it back.
     */
    public function down(): void
    {
    }

    /** @return list<string> the legacy text columns `pages` still has */
    private function legacyColumnsStillThere(): array
    {
        $table = $this->table('pages');
        $present = [];

        foreach (self::LEGACY_COLUMNS as $columns) {
            foreach ($columns as $column) {
                if ($table->hasColumn($column)) {
                    $present[] = $column;
                }
            }
        }

        return $present;
    }

    /**
     * One INSERT … SELECT per language: every page with words in at least one
     * of that language's columns, that has no row for the language yet.
     *
     * @param list<string> $columns the legacy columns, in the order of TARGETS
     */
    private function copyLanguage(string $code, array $columns): void
    {
        $table = $this->table('pages');
        $select = [];
        $hasWords = [];

        foreach (array_combine(self::TARGETS, $columns) as $target => $source) {
            if (!$table->hasColumn($source)) {
                $select[$target] = 'NULL';
                continue;
            }

            $select[$target] = $this->wordsOrNull('p.`' . $source . '`');
            $hasWords[] = $this->hasWords('p.`' . $source . '`');
        }

        if ($hasWords === []) {
            return;
        }

        $this->execute(
            'INSERT INTO page_translations
                (page_id, language_code, title, meta_title, meta_description, created_at, updated_at)
             SELECT p.id, ' . $this->quote($code) . ', '
                . $select['title'] . ', ' . $select['meta_title'] . ', ' . $select['meta_description'] . ',
                    COALESCE(p.created_at, NOW()), COALESCE(p.updated_at, NOW())
               FROM pages p
              WHERE (' . implode(' OR ', $hasWords) . ')
                AND EXISTS (SELECT 1 FROM site_languages l WHERE l.code = ' . $this->quote($code) . ')
                AND NOT EXISTS (
                    SELECT 1 FROM page_translations t
                     WHERE t.page_id = p.id AND t.language_code = ' . $this->quote($code) . '
                )
              ORDER BY p.id'
        );
    }

    /**
     * Every page whose words in a legacy column did not arrive: no row for
     * that language at all. Checked before anything is dropped.
     */
    private function assertNothingIsLeftBehind(): void
    {
        $table = $this->table('pages');

        foreach (self::LEGACY_COLUMNS as $code => $columns) {
            $hasWords = [];
            foreach ($columns as $source) {
                if ($table->hasColumn($source)) {
                    $hasWords[] = $this->hasWords('p.`' . $source . '`');
                }
            }

            if ($hasWords === []) {
                continue;
            }

            $row = $this->fetchRow(
                'SELECT COUNT(*) AS c FROM pages p
                  WHERE (' . implode(' OR ', $hasWords) . ')
                    AND NOT EXISTS (
                        SELECT 1 FROM page_translations t
                         WHERE t.page_id = p.id AND t.language_code = ' . $this->quote($code) . '
                    )'
            );

            if ((int) ($row['c'] ?? 0) > 0) {
                throw new \RuntimeException(sprintf(
                    '%d page(s) have text in language "%s" that could not be moved into page_translations, '
                    . 'most likely because site_languages has no row for "%s". Nothing was dropped.',
                    (int) $row['c'],
                    $code,
                    $code
                ));
            }
        }
    }

    private function hasWords(string $column): string
    {
        return "TRIM(COALESCE({$column}, '')) <> ''";
    }

    private function wordsOrNull(string $column): string
    {
        return "CASE WHEN TRIM(COALESCE({$column}, '')) = '' THEN NULL ELSE {$column} END";
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
