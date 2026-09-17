<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0 phase 3B, wave A (docs/multilingual/ARCHITECTURE.md): moves
 * the words of the block types that have no child rows out of their fixed
 * Dutch/English columns into `block_translations` (20260917160000), and drops
 * those columns. The same move as 20260917170000 did for the three proof
 * blocks, for four more block tables:
 *
 *   page_heroes            Paginakop: eyebrow, title, lead
 *   form_blocks            Formulier: title, intro
 *   contact_form_sections  Offerte-/contactformulier: title
 *   item_galleries         Galerij and Projecten (one shared table):
 *                          eyebrow, title, lead, footer_note, button_label
 *
 * WHAT MOVES, and to which language. The columns keep the meaning V1 gave
 * them, whatever the site's default language is: `<field>_nl` becomes the nl
 * row of `<field>`, `<field>_en` the en row. The owner is the block's own row
 * (its id is page_sections.section_id).
 *
 * A field gets a row only when it holds words. NULL, '' and a value of nothing
 * but spaces, tabs or line breaks all mean "nothing here" to every reader, so
 * none of them gets a row, and the reader falls back as before. A value with
 * words is copied as it is, byte for byte.
 *
 * ONE PAIR IS DROPPED WITHOUT MOVING: page_heroes.breadcrumb_label_nl/en. The
 * breadcrumb is the page's own navigation and its label the page's own title
 * since 20260916120000; nothing has read those columns since, and nothing
 * writes them but an INSERT that had to name a NOT NULL column. They hold no
 * words a visitor can see, so there is nothing to move.
 *
 * NOTHING ELSE ABOUT A BLOCK CHANGES. Its id, page, section key, is_active,
 * URLs, image, form and gallery settings stay where they are. Forms-domain
 * words (a form's own labels and messages) and Portfolio items are not block
 * words and are not touched.
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
 * the drop and says which table and language, instead of dropping them.
 */
final class MovePageHeroFormAndGalleryWordsIntoBlockTranslations extends AbstractMigration
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
        'page_heroes' => [
            'eyebrow' => ['eyebrow_nl', 'eyebrow_en'],
            'title' => ['title_nl', 'title_en'],
            'lead' => ['lead_nl', 'lead_en'],
        ],
        'form_blocks' => [
            'title' => ['title_nl', 'title_en'],
            'intro' => ['intro_nl', 'intro_en'],
        ],
        'contact_form_sections' => [
            'title' => ['title_nl', 'title_en'],
        ],
        'item_galleries' => [
            'eyebrow' => ['eyebrow_nl', 'eyebrow_en'],
            'title' => ['title_nl', 'title_en'],
            'lead' => ['lead_nl', 'lead_en'],
            'footer_note' => ['footer_note_nl', 'footer_note_en'],
            'button_label' => ['button_label_nl', 'button_label_en'],
        ],
    ];

    /** owner table => legacy columns that are dropped without being moved (see the docblock). */
    private const UNREAD_COLUMNS = [
        'page_heroes' => ['breadcrumb_label_nl', 'breadcrumb_label_en'],
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

        foreach (self::UNREAD_COLUMNS as $ownerTable => $columns) {
            if (!$this->hasTable($ownerTable)) {
                continue;
            }

            $table = $this->table($ownerTable);
            $present = array_values(array_filter($columns, static fn (string $column): bool => $table->hasColumn($column)));

            if ($present !== []) {
                foreach ($present as $column) {
                    $table->removeColumn($column);
                }
                $table->update();
            }
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
