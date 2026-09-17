<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0 phase 3B, wave C (docs/multilingual/ARCHITECTURE.md): moves
 * the words of the blocks with several child tables, or with child rows of
 * their own child rows, out of their fixed Dutch/English columns into
 * `block_translations` (20260917160000), and drops those columns. After this
 * migration no content block keeps a word in a language column.
 *
 *   text_image_splits            eyebrow, title, button_label
 *   text_image_split_paragraphs  content
 *   text_image_split_images      alt
 *   detail_sections              nav_label, title, lead, body (rich text), main_image_alt,
 *                                closing_note, cta_label
 *   detail_section_points        title, body
 *   detail_section_images        alt
 *   card_carousels               eyebrow, title, lead
 *   carousel_cards               title, body, image_alt, link_label
 *   carousel_card_tags           label  (a child row of a card: three levels deep)
 *
 * EVERY ROW OWNS ITS OWN WORDS, however deep: a tag's are stored against
 * `carousel_card_tags` and the tag's own id, not against its card or its
 * carousel. BlockDefinition::childTables() tells the application which table
 * hangs under which, so a deleted card still takes its tags' words along.
 *
 * ONE PAIR HAS NO SUFFIX. A Detailsectie's rich text was `content_html` for
 * Dutch and `content_html_en` for English, the same naming the Tekstblok had
 * (20260917170000). Both become the rich field `body`. Rich text is copied
 * byte for byte: it was sanitized when it was saved, and is sanitized again
 * whenever it is read.
 *
 * WHAT MOVES, and to which language. The columns keep the meaning V1 gave
 * them, whatever the site's default language is: the Dutch column becomes the
 * nl row, the English column the en row.
 *
 * A field gets a row only when it holds words. NULL, '' and a value of nothing
 * but spaces, tabs or line breaks all mean "nothing here" to every reader, so
 * none of them gets a row, and the reader falls back as before. A value with
 * words is copied as it is, byte for byte; an alt text with quotes or markup
 * stays exactly that text.
 *
 * NOTHING ELSE CHANGES. Ids, parents, sort order, is_active, anchors, layout,
 * image positions, URLs, media ids and image paths stay where they are.
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
final class MoveTextImageDetailAndCarouselWordsIntoBlockTranslations extends AbstractMigration
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
        'text_image_splits' => [
            'eyebrow' => ['eyebrow_nl', 'eyebrow_en'],
            'title' => ['title_nl', 'title_en'],
            'button_label' => ['button_label_nl', 'button_label_en'],
        ],
        'text_image_split_paragraphs' => [
            'content' => ['content_nl', 'content_en'],
        ],
        'text_image_split_images' => [
            'alt' => ['alt_nl', 'alt_en'],
        ],
        'detail_sections' => [
            'nav_label' => ['nav_label_nl', 'nav_label_en'],
            'title' => ['title_nl', 'title_en'],
            'lead' => ['lead_nl', 'lead_en'],
            'body' => ['content_html', 'content_html_en'],
            'main_image_alt' => ['main_image_alt_nl', 'main_image_alt_en'],
            'closing_note' => ['closing_note_nl', 'closing_note_en'],
            'cta_label' => ['cta_label_nl', 'cta_label_en'],
        ],
        'detail_section_points' => [
            'title' => ['title_nl', 'title_en'],
            'body' => ['body_nl', 'body_en'],
        ],
        'detail_section_images' => [
            'alt' => ['alt_nl', 'alt_en'],
        ],
        'card_carousels' => [
            'eyebrow' => ['eyebrow_nl', 'eyebrow_en'],
            'title' => ['title_nl', 'title_en'],
            'lead' => ['lead_nl', 'lead_en'],
        ],
        'carousel_cards' => [
            'title' => ['title_nl', 'title_en'],
            'body' => ['body_nl', 'body_en'],
            'image_alt' => ['image_alt_nl', 'image_alt_en'],
            'link_label' => ['link_label_nl', 'link_label_en'],
        ],
        'carousel_card_tags' => [
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
