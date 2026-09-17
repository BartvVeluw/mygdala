<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Moves the words of the header menu and the footer out of their fixed
 * Dutch/English columns into the typed translation tables of 20260918100000,
 * and drops those columns (Multilingual 2.0 phase 4 wave A,
 * docs/multilingual/ARCHITECTURE.md).
 *
 *   nav_items.label_nl / label_en        -> nav_item_translations.label      nl / en
 *   footer_columns.title_nl / title_en   -> footer_column_translations.title nl / en
 *   footer_links.label_nl / label_en     -> footer_link_translations.label   nl / en
 *
 * The columns keep the meaning V1 gave them, whatever the site's default
 * language is. A value with words is copied as it is, byte for byte. NULL, ''
 * and a value of only spaces, tabs or line breaks mean "nothing here" to every
 * reader, and get no row. Ids, destinations, presentation, parents, order and
 * visibility are not touched.
 *
 * WHY THE DROP IS IN THE SAME MIGRATION: two copies of the same words that
 * are both still there is how one of them quietly goes stale. The code of
 * this commit reads and writes only the new tables.
 *
 * SAFE TO RUN TWICE. A row that already exists is never overwritten, and once
 * the columns are gone there is nothing left to do.
 *
 * REFUSES RATHER THAN LOSES. A row can only name a language the registry has
 * (a foreign key). If words could not be moved because that language is
 * missing from site_languages, the migration stops before any drop and says
 * so.
 */
final class MoveNavigationAndFooterLabelsIntoTranslationTables extends AbstractMigration
{
    /**
     * [owner table, translation table, owner column, field, the Dutch column,
     * the English column]. Lists rather than a column map, so nothing here
     * reads as a probe of a table called "nl".
     */
    private const MOVES = [
        ['nav_items', 'nav_item_translations', 'nav_item_id', 'label', 'label_nl', 'label_en'],
        ['footer_columns', 'footer_column_translations', 'footer_column_id', 'title', 'title_nl', 'title_en'],
        ['footer_links', 'footer_link_translations', 'footer_link_id', 'label', 'label_nl', 'label_en'],
    ];

    /** The V1 language of each legacy column position above: [4] Dutch, [5] English. */
    private const LANGUAGES = [4 => 'nl', 5 => 'en'];

    public function up(): void
    {
        if (!$this->hasTable('site_languages')
            || !$this->hasTable('nav_item_translations')
            || !$this->hasTable('footer_column_translations')
            || !$this->hasTable('footer_link_translations')) {
            return;
        }

        foreach (self::MOVES as $move) {
            foreach (self::LANGUAGES as $position => $code) {
                $this->copy($move[0], $move[1], $move[2], $move[3], $move[$position], $code);
            }
        }

        foreach (self::MOVES as $move) {
            foreach (self::LANGUAGES as $position => $code) {
                $this->assertNothingIsLeftBehind($move[0], $move[1], $move[2], $move[$position], $code);
            }
        }

        foreach (self::MOVES as $move) {
            if (!$this->hasTable($move[0])) {
                continue;
            }

            $table = $this->table($move[0]);
            $dropped = false;
            foreach (self::LANGUAGES as $position => $code) {
                if ($table->hasColumn($move[$position])) {
                    $table->removeColumn($move[$position]);
                    $dropped = true;
                }
            }
            if ($dropped) {
                $table->update();
            }
        }
    }

    /**
     * Forward-only (db/migrations/CLAUDE.md). The words live in the
     * translation tables now; putting columns back would not put them back.
     */
    public function down(): void
    {
    }

    /** One INSERT … SELECT: every owner with words in that column and no row for the language yet. */
    private function copy(string $ownerTable, string $translationTable, string $ownerColumn, string $field, string $column, string $code): void
    {
        if (!$this->hasTable($ownerTable) || !$this->table($ownerTable)->hasColumn($column)) {
            return;
        }

        $this->execute(
            'INSERT INTO `' . $translationTable . '` (`' . $ownerColumn . '`, language_code, `' . $field . '`, created_at, updated_at)
             SELECT o.id, ' . $this->quote($code) . ', o.`' . $column . '`,
                    COALESCE(o.created_at, NOW()), COALESCE(o.updated_at, NOW())
               FROM `' . $ownerTable . '` o
              WHERE ' . $this->hasWords('o.`' . $column . '`') . '
                AND EXISTS (SELECT 1 FROM site_languages l WHERE l.code = ' . $this->quote($code) . ')
                AND NOT EXISTS (
                    SELECT 1 FROM `' . $translationTable . '` t
                     WHERE t.`' . $ownerColumn . '` = o.id AND t.language_code = ' . $this->quote($code) . '
                )
              ORDER BY o.id'
        );
    }

    private function assertNothingIsLeftBehind(string $ownerTable, string $translationTable, string $ownerColumn, string $column, string $code): void
    {
        if (!$this->hasTable($ownerTable) || !$this->table($ownerTable)->hasColumn($column)) {
            return;
        }

        $row = $this->fetchRow(
            'SELECT COUNT(*) AS c FROM `' . $ownerTable . '` o
              WHERE ' . $this->hasWords('o.`' . $column . '`') . '
                AND NOT EXISTS (
                    SELECT 1 FROM `' . $translationTable . '` t
                     WHERE t.`' . $ownerColumn . '` = o.id AND t.language_code = ' . $this->quote($code) . '
                )'
        );

        if ((int) ($row['c'] ?? 0) > 0) {
            throw new \RuntimeException(sprintf(
                '%d row(s) of %s have words in %s that could not be moved into %s, most likely because '
                . 'site_languages has no row for "%s". Nothing was dropped.',
                (int) $row['c'],
                $ownerTable,
                $column,
                $translationTable,
                $code
            ));
        }
    }

    /**
     * Words are anything other than spaces, tabs, line breaks, NUL and
     * vertical tabs. MySQL's TRIM() only strips spaces, so the other five are
     * turned into spaces first (the same rule as 20260917180000).
     */
    private function hasWords(string $column): string
    {
        $expression = "COALESCE({$column}, '')";
        foreach ([9, 10, 13, 0, 11] as $byte) {
            $expression = "REPLACE({$expression}, CHAR({$byte} USING utf8mb4), ' ')";
        }

        return "TRIM({$expression}) <> ''";
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
