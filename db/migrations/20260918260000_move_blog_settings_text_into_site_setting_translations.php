<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Moves the Blog's two WORD settings out of their fixed Dutch/English keys in
 * `blog_settings` into `site_setting_translations` (20260918120000), and
 * removes those keys (Multilingual 2.0 phase 5, closing entry,
 * docs/multilingual/ARCHITECTURE.md, BLOG.md).
 *
 *   blog_title / blog_title_en  -> blog_title  nl / en
 *   blog_intro / blog_intro_en  -> blog_intro  nl / en
 *
 * They were the last localized settings left on Dutch/English storage: four
 * fixed keys where a third website language had nowhere to go. The rows keep
 * the meaning V1 gave them, whatever the site's default language is, and the
 * key an editor reads keeps its name — `blog_title` is `blog_title`, now as a
 * row per language.
 *
 * ONE PHYSICAL TABLE, TWO CATALOGUES. The words land beside Core's own
 * localized settings, but not IN Core's catalogue:
 * App\Service\Blog\BlogLocalizedSettings owns these two keys and
 * App\Service\LocalizedSiteSettings does not name them, so Core still does not
 * know that a blog exists (MODULES.md).
 *
 * NOT MOVED, on purpose: blog_posts_per_page and the four switches
 * (blog_show_author, blog_show_date, blog_related_posts, blog_rss_enabled).
 * They read the same in every language and stay in `blog_settings`, which is
 * the module's own storage and keeps its table.
 *
 * NOT ABOUT THE MODULE'S STATE. This runs whether the Blog is switched on or
 * off, like every other migration: a module's rows are not a module's
 * presence, and switching the Blog back on must show the same texts.
 *
 * A value with words is copied as it is, byte for byte; NULL, '' and a value
 * of only spaces, tabs or line breaks mean "nothing here" to every reader
 * (App\Service\Blog\BlogSettings read a stored '' as the code default) and
 * get no row.
 *
 * SAFE TO RUN TWICE: a row that already exists is never overwritten, and once
 * the old keys are gone there is nothing left to do.
 *
 * REFUSES RATHER THAN LOSES: if words could not be moved because their
 * language is missing from site_languages, it stops before removing anything.
 */
final class MoveBlogSettingsTextIntoSiteSettingTranslations extends AbstractMigration
{
    /** [the new key, the Dutch key, the English key] */
    private const MOVES = [
        ['blog_title', 'blog_title', 'blog_title_en'],
        ['blog_intro', 'blog_intro', 'blog_intro_en'],
    ];

    /** The V1 language of each legacy key position above. */
    private const LANGUAGES = [1 => 'nl', 2 => 'en'];

    public function up(): void
    {
        if (!$this->hasTable('blog_settings') || !$this->hasTable('site_setting_translations') || !$this->hasTable('site_languages')) {
            return;
        }

        foreach (self::MOVES as $move) {
            foreach (self::LANGUAGES as $position => $code) {
                $this->execute(
                    'INSERT INTO site_setting_translations (setting_key, language_code, value, created_at, updated_at)
                     SELECT ' . $this->quote($move[0]) . ', ' . $this->quote($code) . ', b.setting_value,
                            COALESCE(b.created_at, NOW()), COALESCE(b.updated_at, NOW())
                       FROM blog_settings b
                      WHERE b.setting_key = ' . $this->quote($move[$position]) . '
                        AND ' . $this->hasWords('b.setting_value') . '
                        AND EXISTS (SELECT 1 FROM site_languages l WHERE l.code = ' . $this->quote($code) . ')
                        AND NOT EXISTS (
                            SELECT 1 FROM site_setting_translations t
                             WHERE t.setting_key = ' . $this->quote($move[0]) . ' AND t.language_code = ' . $this->quote($code) . '
                        )'
                );
            }
        }

        foreach (self::MOVES as $move) {
            foreach (self::LANGUAGES as $position => $code) {
                $row = $this->fetchRow(
                    'SELECT COUNT(*) AS c FROM blog_settings b
                      WHERE b.setting_key = ' . $this->quote($move[$position]) . '
                        AND ' . $this->hasWords('b.setting_value') . '
                        AND NOT EXISTS (
                            SELECT 1 FROM site_setting_translations t
                             WHERE t.setting_key = ' . $this->quote($move[0]) . ' AND t.language_code = ' . $this->quote($code) . '
                        )'
                );

                if ((int) ($row['c'] ?? 0) > 0) {
                    throw new \RuntimeException(sprintf(
                        'The Blog setting %s has words that could not be moved into site_setting_translations, most likely '
                        . 'because site_languages has no row for "%s". Nothing was removed.',
                        $move[$position],
                        $code
                    ));
                }
            }
        }

        $legacy = [];
        foreach (self::MOVES as $move) {
            $legacy[] = $move[1];
            $legacy[] = $move[2];
        }

        $this->execute(
            'DELETE FROM blog_settings WHERE setting_key IN ('
            . implode(', ', array_map(fn (string $key): string => $this->quote($key), $legacy))
            . ')'
        );
    }

    /**
     * Forward-only (db/migrations/CLAUDE.md). The words live in
     * site_setting_translations now.
     */
    public function down(): void
    {
    }

    /** Words are anything other than spaces, tabs, line breaks, NUL and vertical tabs (the rule of 20260917180000). */
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
