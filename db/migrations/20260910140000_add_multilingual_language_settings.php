<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multilingual V1 — storage for the three language questions this CMS keeps
 * apart (MULTILINGUAL.md):
 *
 *   1. which language the CMS INTERFACE runs in   -> admin_users.interface_language
 *   2. which language the WEBSITE is written in   -> site_settings.primary_content_language
 *   3. which extra language it is translated into -> site_settings.enabled_content_languages
 *
 * FORWARD-ONLY AND ADDITIVE. Not one `_nl` or `_en` column is touched,
 * renamed or removed, and no content is rewritten. Every existing
 * installation keeps storing exactly what it stored; all that changes is
 * that the application now has somewhere to record what those columns MEAN.
 *
 * WHY EXISTING INSTALLATIONS GET AN EXPLICIT ROW rather than the code
 * default. A database that already carries English content is a bilingual
 * site, and the editors of that site must keep seeing their English fields
 * after this upgrade. The code default is a single Dutch language (which is
 * what a FRESH install should get, and what removes the duplicate fields
 * that made editing confusing). Those two answers are different, so this
 * migration decides per database instead of letting one default serve both:
 * it looks for real English content and, if it finds any, writes the
 * bilingual configuration as actual rows.
 *
 * The same shape as 20260909210000 (branding) and 20260910110000 (business
 * details): pin the current behaviour as real rows BEFORE a generic default
 * takes over, so nothing visible changes for a site that already exists.
 *
 * The interface language column is nullable with no default on purpose. NULL
 * means "this person has not chosen", which App\Service\Language\AdminLocale
 * answers with the site's own fallback — so an existing administrator is not
 * silently assigned a preference they never expressed.
 */
final class AddMultilingualLanguageSettings extends AbstractMigration
{
    /**
     * Where real English website content would be, if this installation has
     * any. Table => column. Deliberately a short, representative list rather
     * than every bilingual column in the schema: the question is "has this
     * site ever been edited in English", and one non-empty value anywhere
     * answers it. A longer list would only make the check slower and more
     * fragile against tables a future version renames.
     */
    private const ENGLISH_CONTENT_PROBES = [
        'pages' => 'meta_title_en',
        'navigation_items' => 'label_en',
        'footer_links' => 'label_en',
        'cta_bands' => 'title_en',
        'text_image_splits' => 'title_en',
        'detail_sections' => 'title_en',
        'faq_sections' => 'title_en',
        'page_heroes' => 'title_en',
        'homepage_heroes' => 'title_en',
    ];

    public function up(): void
    {
        $this->addInterfaceLanguageColumn();
        $this->pinContentLanguagesForExistingSites();
    }

    public function down(): void
    {
        if ($this->hasTable('admin_users') && $this->table('admin_users')->hasColumn('interface_language')) {
            $this->table('admin_users')->removeColumn('interface_language')->update();
        }

        $this->execute(
            "DELETE FROM site_settings WHERE setting_key IN ('primary_content_language', 'enabled_content_languages')"
        );
    }

    private function addInterfaceLanguageColumn(): void
    {
        if (!$this->hasTable('admin_users')) {
            return;
        }

        $table = $this->table('admin_users');
        if ($table->hasColumn('interface_language')) {
            return;
        }

        $table
            ->addColumn('interface_language', 'string', [
                'limit' => 10,
                'null' => true,
                'default' => null,
                'comment' => 'CMS interface language for this account; NULL = not chosen',
                'after' => 'email',
            ])
            ->update();
    }

    /**
     * Write the two content-language rows, but ONLY on a database that has
     * something to preserve. A fresh install writes nothing and therefore
     * runs on the generic code default — one language, no duplicate fields.
     */
    private function pinContentLanguagesForExistingSites(): void
    {
        if (!$this->hasTable('site_settings')) {
            return;
        }

        // A database being built from nothing by this very migration run has
        // no editors and no content. INSTALL-BOOTSTRAP.md draws that line;
        // here the cheap version of the same question is enough, because an
        // empty pages table cannot carry English content either way.
        if (!$this->hasTable('pages')) {
            return;
        }

        $enabled = $this->hasEnglishContent() ? 'nl,en' : 'nl';

        // INSERT IGNORE, like every settings migration in this project: a row
        // an owner has already written always wins over what a migration
        // thinks it should be.
        $this->execute(
            "INSERT IGNORE INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES "
            . "('primary_content_language', 'nl', NOW(), NOW()), "
            . "('enabled_content_languages', '" . $enabled . "', NOW(), NOW())"
        );
    }

    private function hasEnglishContent(): bool
    {
        foreach (self::ENGLISH_CONTENT_PROBES as $table => $column) {
            if (!$this->hasTable($table)) {
                continue;
            }

            if (!$this->table($table)->hasColumn($column)) {
                continue;
            }

            $row = $this->fetchRow(
                'SELECT COUNT(*) AS total FROM `' . $table . '` '
                . "WHERE `" . $column . "` IS NOT NULL AND TRIM(`" . $column . "`) <> ''"
            );

            if ((int) ($row['total'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
