<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multilingual 2.0, phase 4 wave B: the site settings that are website text,
 * one row per key per website language (docs/multilingual/ARCHITECTURE.md).
 *
 *   setting_key    a key of the closed catalogue App\Service\LocalizedSiteSettings::KEYS
 *                  (city, footer_description, footer_slogan); ascii_bin
 *   language_code  FK on site_languages.code, ON DELETE RESTRICT, the same type
 *   value          the words; a language without words has no row
 *
 * UNIQUE(setting_key, language_code).
 *
 * WHY ONE SMALL TABLE AND NOT A TYPED TABLE PER SETTING. These are three
 * short, independent strings of the website as a whole: there is no entity to
 * hang a typed row on, and a table per string would be three tables for three
 * values. Why not the generic block store: its owner is a content row, and
 * its integrity rules (owner table, orphans) mean nothing for a setting.
 *
 * WHAT IS NOT HERE. Language-neutral settings stay in site_settings, and so
 * does everything that is not a visitor's words: the CMS interface language,
 * module configuration, company details, switches and media. The key list is
 * code, not data: no request can add one.
 *
 * SCHEMA ONLY. The words arrive with 20260918130000.
 */
final class CreateTheSiteSettingTranslationsTable extends AbstractMigration
{
    private const TABLE = 'site_setting_translations';

    public function up(): void
    {
        if ($this->hasTable(self::TABLE) || !$this->hasTable('site_languages')) {
            return;
        }

        $this->table(self::TABLE, ['id' => true])
            ->addColumn('setting_key', 'string', [
                'limit' => 100,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'a key of App\Service\LocalizedSiteSettings::KEYS',
            ])
            ->addColumn('language_code', 'string', [
                'limit' => 12,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'site_languages.code',
            ])
            ->addColumn('value', 'text', [
                'null' => false,
                'comment' => 'the words; a language without words has no row',
            ])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['setting_key', 'language_code'], ['unique' => true, 'name' => 'uq_site_setting_translations_key_language'])
            ->addIndex(['language_code'], ['name' => 'idx_site_setting_translations_language'])
            ->addForeignKey('language_code', 'site_languages', 'code', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
                'constraint' => 'fk_site_setting_translations_language',
            ])
            ->create();
    }

    /**
     * Forward-only (db/migrations/CLAUDE.md). Once 20260918130000 has run,
     * this table is where these words live.
     */
    public function down(): void
    {
    }
}
