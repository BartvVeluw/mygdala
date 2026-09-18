<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Moves the Personalisatie module's visitor-facing words out of their fixed
 * Dutch/English columns into the typed translation tables of 20260918240000,
 * and drops what it emptied (Multilingual 2.0 phase 5 wave D,
 * docs/multilingual/ARCHITECTURE.md, MODULES.md "Personalisatie").
 *
 *   product_personalization_settings.instructions / _en
 *       -> product_personalization_translations.instructions       nl / en
 *   product_personalization_views.label / _en
 *       -> product_personalization_view_translations.label         nl / en
 *   product_personalization_zones.label / _en
 *   product_personalization_zones.instructions / _en
 *   product_personalization_zones.placeholder / _en
 *       -> product_personalization_zone_translations.<field>       nl / en
 *
 * Ten columns for five fields. As in the Blog and the Shop, the Dutch half is
 * the BARE column name and only the English one carries a suffix. Both keep
 * the meaning V1 gave them, whatever the site's default language is.
 *
 * A value with words is copied as it is, byte for byte. None of these five is
 * rich text — an instruction, a label and a placeholder are printed escaped —
 * so this migration neither sanitizes nor needs to. NULL, '' and a value of
 * only spaces, tabs or line breaks mean "nothing here" to every reader, and
 * get no row.
 *
 * NOT TOUCHED, and this is the point of the wave: the CONFIGURATION. Every
 * `view_key` and `zone_key` keeps its exact value — they are what an order
 * line and the browser refer to a zone by — and so do the geometry
 * (`area_x`, `area_y`, `area_width`, `area_height`), `allow_text`,
 * `allow_image`, `is_enabled`, `is_required`, `allow_rotation`,
 * `max_text_length`, `surcharge`, `default_font`, `allowed_fonts`,
 * `personalization_mode`, `preview_image_path`, every `sort_order`, every id
 * and every link between a setting, a view and a zone. A language switch
 * could never change what may be engraved, where, or what it costs, and after
 * this migration it still cannot.
 *
 * AND NOT AN ORDER. What a zone was called when somebody bought it is in that
 * line's own `config_snapshot_json` (version 3) and stays exactly as it is,
 * `label`/`label_en` keys included. Nothing here reads or writes
 * `order_item_personalizations`.
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
final class MovePersonalizationWordsIntoTranslationTables extends AbstractMigration
{
    /**
     * [owner table, translation table, owner column, field, the Dutch column,
     * the English column]. Lists rather than a column map, so nothing here
     * reads as a probe of a table called "nl".
     */
    private const MOVES = [
        ['product_personalization_settings', 'product_personalization_translations', 'settings_id', 'instructions', 'instructions', 'instructions_en'],
        ['product_personalization_views', 'product_personalization_view_translations', 'view_id', 'label', 'label', 'label_en'],
        ['product_personalization_zones', 'product_personalization_zone_translations', 'zone_id', 'label', 'label', 'label_en'],
        ['product_personalization_zones', 'product_personalization_zone_translations', 'zone_id', 'instructions', 'instructions', 'instructions_en'],
        ['product_personalization_zones', 'product_personalization_zone_translations', 'zone_id', 'placeholder', 'placeholder', 'placeholder_en'],
    ];

    /** The V1 language of each legacy position above: [4] Dutch, [5] English. */
    private const LANGUAGES = [4 => 'nl', 5 => 'en'];

    public function up(): void
    {
        if (!$this->hasTable('site_languages')
            || !$this->hasTable('product_personalization_translations')
            || !$this->hasTable('product_personalization_view_translations')
            || !$this->hasTable('product_personalization_zone_translations')) {
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
        foreach ([
            'product_personalization_settings',
            'product_personalization_views',
            'product_personalization_zones',
        ] as $ownerTable) {
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
