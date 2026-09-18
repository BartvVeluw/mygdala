<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Moves the Shop's visitor-facing words out of their fixed Dutch/English
 * columns into the typed translation tables of 20260918200000, moves the one
 * global related-products heading into `site_setting_translations`, and drops
 * what it emptied (Multilingual 2.0 phase 5 wave C,
 * docs/multilingual/ARCHITECTURE.md).
 *
 *   products.<field> / <field>_en
 *       for name, description, meta_title, meta_description
 *       -> product_translations.<field>              nl / en
 *   collections.<field> / <field>_en
 *       for name, description, meta_title, meta_description
 *       -> collection_translations.<field>           nl / en
 *   collections.related_heading_nl / related_heading_en
 *       -> collection_translations.related_heading   nl / en
 *   site_settings.related_products_heading_nl / _en
 *       -> site_setting_translations 'related_products_heading'  nl / en
 *
 * Nineteen columns and two setting rows. As in the Blog, the Dutch half of a
 * product's and a collection's own fields is the BARE column name and only
 * the English one carries a suffix; `related_heading` is the one pair with
 * `_nl` on both sides. All keep the meaning V1 gave them, whatever the site's
 * default language is.
 *
 * A value with words is copied as it is, byte for byte, the rich
 * `description` included: this migration does not sanitize, because the code
 * on both sides of it sanitizes (App\Service\DescriptionSanitizer on the way
 * in, again on the way out). NULL, '' and a value of only spaces, tabs or
 * line breaks mean "nothing here" to every reader, and get no row.
 *
 * NOT TOUCHED, and this is the point of the wave: everything that is
 * identity, money or stock. Product ids, slugs, prices, stock, shipping
 * settings, `active`, `in_shop`, `in_personalization_catalog`, image paths,
 * variants and their labels, collection ids, slugs, images, `is_active`,
 * `show_related_products` and every sort order keep their exact values. So do
 * `collection_products` and every other relation. A language switch was never
 * able to change a price and still cannot.
 *
 * AND NOT AN ORDER. `order_items.product_name_en` is a SNAPSHOT of what a
 * product was called when it was bought, not a translation of what it is
 * called now; it is moved by 20260918230000, into a store of its own, with a
 * rule of its own. Nothing here reads or writes `order_items`.
 *
 * THE RELATED-PRODUCTS HEADING KEEPS ITS PRECEDENCE. Before: the collection's
 * own Dutch heading, else the global Dutch setting; and for English the
 * collection's English heading, else the collection's Dutch one, else the
 * global English setting, else the Dutch answer. After: the collection's
 * words with the ordinary fallback (asked-for language, default language),
 * and only then the global setting with that same fallback. For a
 * Dutch-default site those two are the same chain, value for value — see
 * App\Service\RelatedProductsContent and
 * Tests\Service\RelatedProductsContentTest.
 *
 * WHY THE DROP IS IN THE SAME MIGRATION: two copies of the same words that
 * are both still there is how one of them quietly goes stale. The code of
 * this commit reads and writes only the new tables.
 *
 * SAFE TO RUN TWICE. A row that already exists is never overwritten, an
 * already filled column is never rewritten, and once the columns are gone
 * there is nothing left to do.
 *
 * REFUSES RATHER THAN LOSES. If words could not be moved because their
 * language is missing from site_languages, the migration stops before any
 * drop and says so.
 *
 * RUNS WITH THE MODULE OFF, like every other wave of this phase.
 */
final class MoveShopWordsIntoTranslationTables extends AbstractMigration
{
    /**
     * [owner table, translation table, owner column, field, the Dutch column,
     * the English column]. Lists rather than a column map, so nothing here
     * reads as a probe of a table called "nl".
     */
    private const MOVES = [
        ['products', 'product_translations', 'product_id', 'name', 'name', 'name_en'],
        ['products', 'product_translations', 'product_id', 'description', 'description', 'description_en'],
        ['products', 'product_translations', 'product_id', 'meta_title', 'meta_title', 'meta_title_en'],
        ['products', 'product_translations', 'product_id', 'meta_description', 'meta_description', 'meta_description_en'],
        ['collections', 'collection_translations', 'collection_id', 'name', 'name', 'name_en'],
        ['collections', 'collection_translations', 'collection_id', 'description', 'description', 'description_en'],
        ['collections', 'collection_translations', 'collection_id', 'meta_title', 'meta_title', 'meta_title_en'],
        ['collections', 'collection_translations', 'collection_id', 'meta_description', 'meta_description', 'meta_description_en'],
        ['collections', 'collection_translations', 'collection_id', 'related_heading', 'related_heading_nl', 'related_heading_en'],
    ];

    /** [the new setting key, the Dutch row, the English row] */
    private const SETTING_MOVES = [
        ['related_products_heading', 'related_products_heading_nl', 'related_products_heading_en'],
    ];

    /** The V1 language of each legacy position above: [4]/[1] Dutch, [5]/[2] English. */
    private const LANGUAGES = [4 => 'nl', 5 => 'en'];
    private const SETTING_LANGUAGES = [1 => 'nl', 2 => 'en'];

    public function up(): void
    {
        if (!$this->hasTable('site_languages')
            || !$this->hasTable('product_translations')
            || !$this->hasTable('collection_translations')
            || !$this->hasTable('site_setting_translations')) {
            return;
        }

        foreach (self::MOVES as $move) {
            foreach (self::LANGUAGES as $position => $code) {
                $this->copy($move[0], $move[1], $move[2], $move[3], $move[$position], $code);
            }
        }

        foreach (self::SETTING_MOVES as $move) {
            foreach (self::SETTING_LANGUAGES as $position => $code) {
                $this->copySetting($move[0], $move[$position], $code);
            }
        }

        foreach (self::MOVES as $move) {
            foreach (self::LANGUAGES as $position => $code) {
                $this->assertNothingIsLeftBehind($move[0], $move[1], $move[2], $move[3], $move[$position], $code);
            }
        }

        foreach (self::SETTING_MOVES as $move) {
            foreach (self::SETTING_LANGUAGES as $position => $code) {
                $this->assertNoSettingIsLeftBehind($move[0], $move[$position], $code);
            }
        }

        // Grouped per owner table so each one is altered once, not per field.
        foreach (['products', 'collections'] as $ownerTable) {
            $this->dropLegacyColumns($ownerTable);
        }

        $legacyKeys = [];
        foreach (self::SETTING_MOVES as $move) {
            $legacyKeys[] = $move[1];
            $legacyKeys[] = $move[2];
        }

        $this->execute(
            'DELETE FROM site_settings WHERE setting_key IN ('
            . implode(', ', array_map(fn (string $key): string => $this->quote($key), $legacyKeys))
            . ')'
        );
    }

    /**
     * Forward-only (db/migrations/CLAUDE.md). The words live in the
     * translation tables now; putting columns back would not put them back.
     */
    public function down(): void
    {
    }

    /**
     * One INSERT … SELECT and one UPDATE … JOIN per field per language, the
     * pattern of 20260918170000: insert the row for a language that has none
     * yet, fill a still-empty column when the row already exists.
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

        $this->execute(
            'UPDATE `' . $translationTable . '` t
               JOIN `' . $ownerTable . '` o ON o.id = t.`' . $ownerColumn . '`
                SET t.`' . $field . '` = o.`' . $column . '`
              WHERE t.language_code = ' . $this->quote($code) . '
                AND t.`' . $field . '` IS NULL
                AND ' . $this->hasWords('o.`' . $column . '`')
        );
    }

    private function copySetting(string $key, string $legacyKey, string $code): void
    {
        $this->execute(
            'INSERT INTO site_setting_translations (setting_key, language_code, value, created_at, updated_at)
             SELECT ' . $this->quote($key) . ', ' . $this->quote($code) . ', s.setting_value,
                    COALESCE(s.created_at, NOW()), COALESCE(s.updated_at, NOW())
               FROM site_settings s
              WHERE s.setting_key = ' . $this->quote($legacyKey) . '
                AND ' . $this->hasWords('s.setting_value') . '
                AND EXISTS (SELECT 1 FROM site_languages l WHERE l.code = ' . $this->quote($code) . ')
                AND NOT EXISTS (
                    SELECT 1 FROM site_setting_translations t
                     WHERE t.setting_key = ' . $this->quote($key) . ' AND t.language_code = ' . $this->quote($code) . '
                )'
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

    private function assertNoSettingIsLeftBehind(string $key, string $legacyKey, string $code): void
    {
        $row = $this->fetchRow(
            'SELECT COUNT(*) AS c FROM site_settings s
              WHERE s.setting_key = ' . $this->quote($legacyKey) . '
                AND ' . $this->hasWords('s.setting_value') . '
                AND NOT EXISTS (
                    SELECT 1 FROM site_setting_translations t
                     WHERE t.setting_key = ' . $this->quote($key) . ' AND t.language_code = ' . $this->quote($code) . '
                )'
        );

        if ((int) ($row['c'] ?? 0) > 0) {
            throw new \RuntimeException(sprintf(
                'The site setting %s has words that could not be moved into site_setting_translations, most likely '
                . 'because site_languages has no row for "%s". Nothing was dropped or removed.',
                $legacyKey,
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
     * turned into spaces first (the same rule as 20260918170000).
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
