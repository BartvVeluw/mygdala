<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Moves the Blog's words out of their fixed Dutch/English columns into the
 * typed translation tables of 20260918180000, and drops those columns
 * (Multilingual 2.0 phase 5 wave B, docs/multilingual/ARCHITECTURE.md).
 *
 *   blog_posts.<field> / <field>_en
 *       for title, excerpt, body, meta_title, meta_description
 *       -> blog_post_translations.<field>       nl / en
 *   blog_categories.<field> / <field>_en  for name, description
 *       -> blog_category_translations.<field>   nl / en
 *   blog_tags.name / name_en
 *       -> blog_tag_translations.name           nl / en
 *
 * Sixteen columns, eight fields. UNLIKE the Portfolio's columns of wave A, the
 * Dutch half here is the BARE column name (`title`, not `title_nl`) and only
 * the English one carries a suffix — the shape the Blog has always had. Both
 * keep the meaning V1 gave them, whatever the site's default language is.
 *
 * A value with words is copied as it is, byte for byte, the rich `body`
 * included: this migration does not sanitize, because the code on both sides
 * of it sanitizes on read (App\Service\Blog\BlogContent::body()). NULL, '' and
 * a value of only spaces, tabs or line breaks mean "nothing here" to every
 * reader, and get no row.
 *
 * NOT TOUCHED, and this is the point: every SLUG. `blog_posts.slug`,
 * `blog_categories.slug` and `blog_tags.slug` keep their exact values, so
 * /blog/<slug>, /blog/categorie/<slug> and /blog/tag/<slug> answer exactly
 * what they answered before, and every stored redirect of a renamed archive
 * (App\Service\Blog\BlogTaxonomy) keeps pointing where it pointed. Neither is
 * the status, the publication date, the author, an image, noindex, is_active
 * or a sort order.
 *
 * WHY THE DROP IS IN THE SAME MIGRATION: two copies of the same words that
 * are both still there is how one of them quietly goes stale. The code of
 * this commit reads and writes only the new tables.
 *
 * SAFE TO RUN TWICE. A row that already exists is never overwritten, an
 * already filled column is never rewritten, and once the columns are gone
 * there is nothing left to do.
 *
 * REFUSES RATHER THAN LOSES. A row can only name a language the registry has
 * (a foreign key). If words could not be moved because that language is
 * missing from site_languages, the migration stops before any drop and says
 * so.
 *
 * RUNS WITH THE MODULE OFF. Nothing here asks whether the Blog is enabled: a
 * switched-off module keeps its content, and its words move with it so that
 * switching it back on shows exactly the same words.
 */
final class MoveBlogWordsIntoTranslationTables extends AbstractMigration
{
    /**
     * [owner table, translation table, owner column, field, the Dutch column,
     * the English column]. Lists rather than a column map, so nothing here
     * reads as a probe of a table called "nl".
     */
    private const MOVES = [
        ['blog_posts', 'blog_post_translations', 'blog_post_id', 'title', 'title', 'title_en'],
        ['blog_posts', 'blog_post_translations', 'blog_post_id', 'excerpt', 'excerpt', 'excerpt_en'],
        ['blog_posts', 'blog_post_translations', 'blog_post_id', 'body', 'body', 'body_en'],
        ['blog_posts', 'blog_post_translations', 'blog_post_id', 'meta_title', 'meta_title', 'meta_title_en'],
        ['blog_posts', 'blog_post_translations', 'blog_post_id', 'meta_description', 'meta_description', 'meta_description_en'],
        ['blog_categories', 'blog_category_translations', 'blog_category_id', 'name', 'name', 'name_en'],
        ['blog_categories', 'blog_category_translations', 'blog_category_id', 'description', 'description', 'description_en'],
        ['blog_tags', 'blog_tag_translations', 'blog_tag_id', 'name', 'name', 'name_en'],
    ];

    /** The V1 language of each legacy column position above: [4] Dutch, [5] English. */
    private const LANGUAGES = [4 => 'nl', 5 => 'en'];

    public function up(): void
    {
        if (!$this->hasTable('site_languages')
            || !$this->hasTable('blog_post_translations')
            || !$this->hasTable('blog_category_translations')
            || !$this->hasTable('blog_tag_translations')) {
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
        foreach (['blog_posts', 'blog_categories', 'blog_tags'] as $ownerTable) {
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
     * One INSERT … SELECT and one UPDATE … JOIN per field per language. A
     * field is rarely the first one of its owner, so the row for that language
     * may already exist: insert the row when it does not, fill the column when
     * it does.
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

        // Only an empty column is filled, so a second run never overwrites
        // words that are already there.
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
