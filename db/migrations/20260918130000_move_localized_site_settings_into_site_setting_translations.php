<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Moves the site settings that are website text out of their fixed
 * Dutch/English rows in `site_settings` into `site_setting_translations`
 * (20260918120000), and removes those rows (Multilingual 2.0 phase 4 wave B,
 * docs/multilingual/ARCHITECTURE.md).
 *
 *   city_nl / city_en                              -> city                nl / en
 *   footer_description_nl / footer_description_en  -> footer_description  nl / en
 *   footer_slogan_nl / footer_slogan_en            -> footer_slogan       nl / en
 *
 * The rows keep the meaning V1 gave them, whatever the site's default
 * language is. A value with words is copied as it is, byte for byte; NULL, ''
 * and a value of only spaces, tabs or line breaks mean "nothing here" to
 * every reader (App\Service\SiteSettings::all() treats a stored '' as the
 * default, which was '' for all six) and get no row.
 *
 * NOT MOVED, on purpose:
 *   - footer_slogan_enabled, footer_copyright_template, the company details
 *     and every switch: the same in every language, they stay in site_settings;
 *   - related_products_heading_nl/en: a Shop setting with per-collection
 *     overrides in `collections`, moved with the Shop in phase 5.
 *
 * ALSO REMOVED: the eight header_cta_* rows. They are the header's former
 * single button, copied into nav_items once by 20260916230000 (which runs
 * before this migration on every upgrade), and nothing has read or written
 * them since Navigation phase A. header_cta_label_nl/en was their only
 * Dutch/English pair.
 *
 * SAFE TO RUN TWICE: a row that already exists is never overwritten, and once
 * the old rows are gone there is nothing left to do.
 *
 * REFUSES RATHER THAN LOSES: if words could not be moved because their
 * language is missing from site_languages, it stops before removing anything.
 */
final class MoveLocalizedSiteSettingsIntoSiteSettingTranslations extends AbstractMigration
{
    /** [the new key, the Dutch row, the English row] */
    private const MOVES = [
        ['city', 'city_nl', 'city_en'],
        ['footer_description', 'footer_description_nl', 'footer_description_en'],
        ['footer_slogan', 'footer_slogan_nl', 'footer_slogan_en'],
    ];

    /** The V1 language of each legacy row position above. */
    private const LANGUAGES = [1 => 'nl', 2 => 'en'];

    private const LEGACY_HEADER_BUTTON_KEYS = [
        'header_cta_enabled',
        'header_cta_label_nl',
        'header_cta_label_en',
        'header_cta_link_type',
        'header_cta_target_page_id',
        'header_cta_target_route',
        'header_cta_external_url',
        'header_cta_open_in_new_tab',
    ];

    public function up(): void
    {
        if (!$this->hasTable('site_settings') || !$this->hasTable('site_setting_translations') || !$this->hasTable('site_languages')) {
            return;
        }

        foreach (self::MOVES as $move) {
            foreach (self::LANGUAGES as $position => $code) {
                $this->execute(
                    'INSERT INTO site_setting_translations (setting_key, language_code, value, created_at, updated_at)
                     SELECT ' . $this->quote($move[0]) . ', ' . $this->quote($code) . ', s.setting_value,
                            COALESCE(s.created_at, NOW()), COALESCE(s.updated_at, NOW())
                       FROM site_settings s
                      WHERE s.setting_key = ' . $this->quote($move[$position]) . '
                        AND ' . $this->hasWords('s.setting_value') . '
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
                    'SELECT COUNT(*) AS c FROM site_settings s
                      WHERE s.setting_key = ' . $this->quote($move[$position]) . '
                        AND ' . $this->hasWords('s.setting_value') . '
                        AND NOT EXISTS (
                            SELECT 1 FROM site_setting_translations t
                             WHERE t.setting_key = ' . $this->quote($move[0]) . ' AND t.language_code = ' . $this->quote($code) . '
                        )'
                );

                if ((int) ($row['c'] ?? 0) > 0) {
                    throw new \RuntimeException(sprintf(
                        'The site setting %s has words that could not be moved into site_setting_translations, most likely '
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
            'DELETE FROM site_settings WHERE setting_key IN ('
            . implode(', ', array_map(fn (string $key): string => $this->quote($key), array_merge($legacy, self::LEGACY_HEADER_BUTTON_KEYS)))
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
