<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Moves the Portfolio module's words out of their fixed Dutch/English columns
 * into the typed translation tables of 20260918160000, and drops those
 * columns (Multilingual 2.0 phase 5 wave A,
 * docs/multilingual/ARCHITECTURE.md).
 *
 *   portfolio_categories.name_nl / name_en
 *       -> portfolio_category_translations.name              nl / en
 *   portfolio_gallery_items.<field>_nl / <field>_en
 *       for title, subtitle, alt, intro, description
 *       -> portfolio_item_translations.<field>                nl / en
 *   portfolio_item_images.alt_nl / alt_en
 *       -> portfolio_item_image_translations.alt              nl / en
 *
 * Fourteen columns, seven fields. The columns keep the meaning V1 gave them,
 * whatever the site's default language is. A value with words is copied as it
 * is, byte for byte, including the rich text of `intro` and `description`:
 * this migration does not sanitize, because the code on both sides of it
 * sanitizes on read (App\Service\PortfolioGalleryContent). NULL, '' and a
 * value of only spaces, tabs or line breaks mean "nothing here" to every
 * reader, and get no row. Ids, slugs, image paths, categories, the linked
 * page, is_active, is_featured and every sort order are not touched.
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
 *
 * RUNS WITH THE MODULE OFF. Nothing here asks whether the Portfolio is
 * enabled: a switched-off module keeps its content, and its words move with
 * it so that switching it back on shows exactly the same words.
 */
final class MovePortfolioWordsIntoTranslationTables extends AbstractMigration
{
    /**
     * [owner table, translation table, owner column, field, the Dutch column,
     * the English column]. Lists rather than a column map, so nothing here
     * reads as a probe of a table called "nl".
     */
    private const MOVES = [
        ['portfolio_categories', 'portfolio_category_translations', 'portfolio_category_id', 'name', 'name_nl', 'name_en'],
        ['portfolio_gallery_items', 'portfolio_item_translations', 'portfolio_item_id', 'title', 'title_nl', 'title_en'],
        ['portfolio_gallery_items', 'portfolio_item_translations', 'portfolio_item_id', 'subtitle', 'subtitle_nl', 'subtitle_en'],
        ['portfolio_gallery_items', 'portfolio_item_translations', 'portfolio_item_id', 'alt', 'alt_nl', 'alt_en'],
        ['portfolio_gallery_items', 'portfolio_item_translations', 'portfolio_item_id', 'intro', 'intro_nl', 'intro_en'],
        ['portfolio_gallery_items', 'portfolio_item_translations', 'portfolio_item_id', 'description', 'description_nl', 'description_en'],
        ['portfolio_item_images', 'portfolio_item_image_translations', 'portfolio_item_image_id', 'alt', 'alt_nl', 'alt_en'],
    ];

    /** The V1 language of each legacy column position above: [4] Dutch, [5] English. */
    private const LANGUAGES = [4 => 'nl', 5 => 'en'];

    public function up(): void
    {
        if (!$this->hasTable('site_languages')
            || !$this->hasTable('portfolio_category_translations')
            || !$this->hasTable('portfolio_item_translations')
            || !$this->hasTable('portfolio_item_image_translations')) {
            return;
        }

        foreach (self::MOVES as $move) {
            foreach (self::LANGUAGES as $position => $code) {
                $this->copy($move[0], $move[1], $move[2], $move[3], $move[$position], $code);
            }
        }

        foreach (self::MOVES as $move) {
            foreach (self::LANGUAGES as $position => $code) {
                $this->assertNothingIsLeftBehind($move[0], $move[1], $move[2], $move[3], $move[$position], $code);
            }
        }

        // Grouped per owner table so each one is altered once, not per field.
        foreach (['portfolio_categories', 'portfolio_gallery_items', 'portfolio_item_images'] as $ownerTable) {
            $this->dropLegacyColumns($ownerTable);
        }
    }

    /**
     * Forward-only (db/migrations/CLAUDE.md). The words live in the
     * translation tables now; putting columns back would not put them back.
     */
    public function down(): void
    {
    }

    /**
     * One UPDATE … JOIN or INSERT … SELECT per field per language. A field is
     * the second one of its owner, so the row for that language may already
     * exist: fill the column when it does, insert the row when it does not.
     */
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

        // The row already existed because an earlier field of the same owner
        // and language made it. Only an empty column is filled, so a second
        // run never overwrites words that are already there.
        $this->execute(
            'UPDATE `' . $translationTable . '` t
               JOIN `' . $ownerTable . '` o ON o.id = t.`' . $ownerColumn . '`
                SET t.`' . $field . '` = o.`' . $column . '`
              WHERE t.language_code = ' . $this->quote($code) . '
                AND t.`' . $field . '` IS NULL
                AND ' . $this->hasWords('o.`' . $column . '`')
        );
    }

    private function assertNothingIsLeftBehind(string $ownerTable, string $translationTable, string $ownerColumn, string $field, string $column, string $code): void
    {
        if (!$this->hasTable($ownerTable) || !$this->table($ownerTable)->hasColumn($column)) {
            return;
        }

        $row = $this->fetchRow(
            'SELECT COUNT(*) AS c FROM `' . $ownerTable . '` o
              WHERE ' . $this->hasWords('o.`' . $column . '`') . '
                AND NOT EXISTS (
                    SELECT 1 FROM `' . $translationTable . '` t
                     WHERE t.`' . $ownerColumn . '` = o.id
                       AND t.language_code = ' . $this->quote($code) . '
                       AND t.`' . $field . '` IS NOT NULL
                )'
        );

        if ((int) ($row['c'] ?? 0) > 0) {
            throw new \RuntimeException(sprintf(
                '%d row(s) of %s have words in %s that could not be moved into %s.%s, most likely because '
                . 'site_languages has no row for "%s". Nothing was dropped.',
                (int) $row['c'],
                $ownerTable,
                $column,
                $translationTable,
                $field,
                $code
            ));
        }
    }

    private function dropLegacyColumns(string $ownerTable): void
    {
        if (!$this->hasTable($ownerTable)) {
            return;
        }

        $table = $this->table($ownerTable);
        $dropped = false;

        foreach (self::MOVES as $move) {
            if ($move[0] !== $ownerTable) {
                continue;
            }
            foreach (self::LANGUAGES as $position => $code) {
                if ($table->hasColumn($move[$position])) {
                    $table->removeColumn($move[$position]);
                    $dropped = true;
                }
            }
        }

        if ($dropped) {
            $table->update();
        }
    }

    /**
     * Words are anything other than spaces, tabs, line breaks, NUL and
     * vertical tabs. MySQL's TRIM() only strips spaces, so the other five are
     * turned into spaces first (the same rule as 20260918110000).
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
