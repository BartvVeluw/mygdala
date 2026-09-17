<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0 phase 3B, wave B (docs/multilingual/ARCHITECTURE.md): moves
 * the words of the homepage hero and of the ordinary repeaters out of their
 * fixed Dutch/English columns into `block_translations` (20260917160000), and
 * drops those columns. The same move as 20260917170000 and 20260917180000,
 * now with child rows:
 *
 *   homepage_hero          eyebrow, title, title_highlight, lead, primary_label,
 *                          secondary_label, image_alt, badge_title, badge_text
 *   homepage_hero_stats    primary_text, secondary_text
 *   feature_grids          eyebrow, title, lead
 *   feature_grid_items     title, body
 *   faq_sections           eyebrow, title
 *   faq_items              question, answer
 *   stat_strip_items       primary_text, secondary_text  (stat_strips has no words)
 *   step_list_sections     eyebrow, title
 *   step_list_items        title, body
 *   marquee_items          label                         (marquee_sections has no words)
 *
 * A CHILD ROW IS AN OWNER LIKE ANY OTHER. An item's words are stored against
 * its own table and its own id (`owner_table = 'faq_items'`, `owner_id` = the
 * item's id), never as "item 3 of section 12": the id does not change when an
 * item is moved, so its words stay with it. BlockDefinition::childTables()
 * tells the application which child table hangs under which block table.
 *
 * WHAT MOVES, and to which language. The columns keep the meaning V1 gave
 * them, whatever the site's default language is: `<field>_nl` becomes the nl
 * row of `<field>`, `<field>_en` the en row.
 *
 * A field gets a row only when it holds words. NULL, '' and a value of nothing
 * but spaces, tabs or line breaks all mean "nothing here" to every reader, so
 * none of them gets a row, and the reader falls back as before. A value with
 * words is copied as it is, byte for byte.
 *
 * NOTHING ELSE CHANGES. Ids, parents, sort order, is_active, icons, URLs,
 * media, layout and sizes stay where they are.
 *
 * WHY THE DROP IS IN THE SAME MIGRATION. Two copies of a block's words that are
 * both still there is how the second one quietly goes stale; the code in this
 * commit reads and writes only block_translations.
 *
 * SAFE TO RUN TWICE. A row that already exists is never overwritten, and once
 * the columns are gone there is nothing left to do.
 *
 * REFUSES RATHER THAN LOSES. A row can only name a language the registry has
 * (a foreign key). If words could not be moved, the migration stops before
 * that table's drop and says which table and language, instead of dropping
 * them; tables handled before it keep their move, and running the migration
 * again once the language exists finishes the rest.
 */
final class MoveHomepageHeroAndRepeaterWordsIntoBlockTranslations extends AbstractMigration
{
    /** The languages of the legacy column pairs, in the order LEGACY_COLUMNS lists them. */
    private const LANGUAGES = ['nl', 'en'];

    /**
     * owner table => field => [Dutch column, English column]. The only place
     * the old column names are still written down. Lists rather than
     * language-keyed maps, so no column name reads as a table probe
     * (Tests\Install\MigrationTableNamesTest).
     */
    private const LEGACY_COLUMNS = [
        'homepage_hero' => [
            'eyebrow' => ['eyebrow_nl', 'eyebrow_en'],
            'title' => ['title_nl', 'title_en'],
            'title_highlight' => ['title_highlight_nl', 'title_highlight_en'],
            'lead' => ['lead_nl', 'lead_en'],
            'primary_label' => ['primary_label_nl', 'primary_label_en'],
            'secondary_label' => ['secondary_label_nl', 'secondary_label_en'],
            'image_alt' => ['image_alt_nl', 'image_alt_en'],
            'badge_title' => ['badge_title_nl', 'badge_title_en'],
            'badge_text' => ['badge_text_nl', 'badge_text_en'],
        ],
        'homepage_hero_stats' => [
            'primary_text' => ['primary_text_nl', 'primary_text_en'],
            'secondary_text' => ['secondary_text_nl', 'secondary_text_en'],
        ],
        'feature_grids' => [
            'eyebrow' => ['eyebrow_nl', 'eyebrow_en'],
            'title' => ['title_nl', 'title_en'],
            'lead' => ['lead_nl', 'lead_en'],
        ],
        'feature_grid_items' => [
            'title' => ['title_nl', 'title_en'],
            'body' => ['body_nl', 'body_en'],
        ],
        'faq_sections' => [
            'eyebrow' => ['eyebrow_nl', 'eyebrow_en'],
            'title' => ['title_nl', 'title_en'],
        ],
        'faq_items' => [
            'question' => ['question_nl', 'question_en'],
            'answer' => ['answer_nl', 'answer_en'],
        ],
        'stat_strip_items' => [
            'primary_text' => ['primary_text_nl', 'primary_text_en'],
            'secondary_text' => ['secondary_text_nl', 'secondary_text_en'],
        ],
        'step_list_sections' => [
            'eyebrow' => ['eyebrow_nl', 'eyebrow_en'],
            'title' => ['title_nl', 'title_en'],
        ],
        'step_list_items' => [
            'title' => ['title_nl', 'title_en'],
            'body' => ['body_nl', 'body_en'],
        ],
        'marquee_items' => [
            'label' => ['label_nl', 'label_en'],
        ],
    ];

    public function up(): void
    {
        if (!$this->hasTable('block_translations') || !$this->hasTable('site_languages')) {
            return;
        }

        foreach (self::LEGACY_COLUMNS as $ownerTable => $fields) {
            if (!$this->hasTable($ownerTable)) {
                continue;
            }

            $legacy = $this->legacyColumnsStillThere($ownerTable, $fields);
            if ($legacy === []) {
                continue;
            }

            foreach ($fields as $field => $columns) {
                foreach (array_combine(self::LANGUAGES, $columns) as $code => $column) {
                    $this->copyField($ownerTable, $field, $code, $column);
                }
            }

            $this->assertNothingIsLeftBehind($ownerTable, $fields);

            $table = $this->table($ownerTable);
            foreach ($legacy as $column) {
                $table->removeColumn($column);
            }
            $table->update();
        }
    }

    /**
     * Forward-only (db/migrations/CLAUDE.md). The words live in
     * block_translations now; putting columns back would not put them back.
     */
    public function down(): void
    {
    }

    /**
     * @param array<string, list<string>> $fields
     * @return list<string>
     */
    private function legacyColumnsStillThere(string $ownerTable, array $fields): array
    {
        $table = $this->table($ownerTable);
        $present = [];

        foreach ($fields as $columns) {
            foreach ($columns as $column) {
                if ($table->hasColumn($column)) {
                    $present[] = $column;
                }
            }
        }

        return $present;
    }

    /**
     * One INSERT … SELECT per field per language: every row with words in
     * that column that has no row for this field and language yet.
     */
    private function copyField(string $ownerTable, string $field, string $code, string $column): void
    {
        if (!$this->table($ownerTable)->hasColumn($column)) {
            return;
        }

        $this->execute(
            'INSERT INTO block_translations
                (owner_table, owner_id, language_code, field, value, created_at, updated_at)
             SELECT ' . $this->quote($ownerTable) . ', o.id, ' . $this->quote($code) . ', ' . $this->quote($field) . ', o.`' . $column . '`,
                    COALESCE(o.created_at, NOW()), COALESCE(o.updated_at, NOW())
               FROM `' . $ownerTable . '` o
              WHERE ' . $this->hasWords('o.`' . $column . '`') . '
                AND EXISTS (SELECT 1 FROM site_languages l WHERE l.code = ' . $this->quote($code) . ')
                AND NOT EXISTS (
                    SELECT 1 FROM block_translations t
                     WHERE t.owner_table = ' . $this->quote($ownerTable) . '
                       AND t.owner_id = o.id
                       AND t.language_code = ' . $this->quote($code) . '
                       AND t.field = ' . $this->quote($field) . '
                )
              ORDER BY o.id'
        );
    }

    /**
     * Every row whose words in a legacy column did not arrive. Checked before
     * anything is dropped.
     *
     * @param array<string, list<string>> $fields
     */
    private function assertNothingIsLeftBehind(string $ownerTable, array $fields): void
    {
        $table = $this->table($ownerTable);

        foreach ($fields as $field => $columns) {
            foreach (array_combine(self::LANGUAGES, $columns) as $code => $column) {
                if (!$table->hasColumn($column)) {
                    continue;
                }

                $row = $this->fetchRow(
                    'SELECT COUNT(*) AS c FROM `' . $ownerTable . '` o
                      WHERE ' . $this->hasWords('o.`' . $column . '`') . '
                        AND NOT EXISTS (
                            SELECT 1 FROM block_translations t
                             WHERE t.owner_table = ' . $this->quote($ownerTable) . '
                               AND t.owner_id = o.id
                               AND t.language_code = ' . $this->quote($code) . '
                               AND t.field = ' . $this->quote($field) . '
                        )'
                );

                if ((int) ($row['c'] ?? 0) > 0) {
                    throw new \RuntimeException(sprintf(
                        '%d row(s) of %s have words in %s that could not be moved into block_translations, '
                        . 'most likely because site_languages has no row for "%s". Nothing was dropped.',
                        (int) $row['c'],
                        $ownerTable,
                        $column,
                        $code
                    ));
                }
            }
        }
    }

    /**
     * Words, in the sense PHP's trim() gives every reader: something other
     * than spaces, tabs, line breaks, NUL and vertical tabs. MySQL's TRIM()
     * only strips spaces, so the other five are turned into spaces first.
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
