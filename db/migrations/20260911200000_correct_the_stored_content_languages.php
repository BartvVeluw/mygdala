<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Repairs `site_settings.enabled_content_languages`, which
 * 20260910140000_add_multilingual_language_settings could leave saying `nl`
 * on a site that does publish English (MULTILINGUAL.md).
 *
 * WHAT WENT WRONG. That migration decided per database whether to write the
 * bilingual value, by probing a short list of tables for a non-empty `_en`
 * column. Two of the nine names in that list are not tables of this schema:
 *
 *     navigation_items   the table is `nav_items`
 *     homepage_heroes    the table is `homepage_hero`
 *
 * The probe loop skips a name `hasTable()` does not know, so both were
 * silently passed over. On an installation whose only English content lives
 * in the menu or in the homepage hero -- which includes every site built
 * from the generic bootstrap, because that is exactly where the bootstrap
 * puts its English -- the remaining seven probes all answered "no English"
 * and the migration stored `nl`.
 *
 * WHY THIS IS A REWRITE AND NOT A FIXED PROBE. The probe answered a question
 * the product no longer asks. When it was written, this row decided whether
 * a visitor got a language switch and whether an editor got English fields.
 * That setting was the mistake the V1 correction removed:
 * App\Service\Language\ContentLanguages::enabled() now answers from the
 * closed LanguageRegistry, and this row decides nothing. What the row is FOR
 * now is being truthful about the languages this site publishes, and
 * App\Service\Language\ContentLanguages::normalise() -- the one writer an
 * owner's own save goes through -- always writes the full set for that
 * reason.
 *
 * So the correct value does not depend on what content a database happens to
 * hold: this product is Dutch plus English, always. Rewriting the row to the
 * full set is both the smaller repair and the one that cannot be wrong
 * again, because it asks nothing about the schema. It also stops a site from
 * holding a value that its own settings screen would replace the first time
 * anybody saved anything there.
 *
 * 20260910140000 IS LEFT EXACTLY AS IT RAN. It has already been applied to
 * real installations, so its recorded history stays honest and this
 * migration corrects the state it produced. Running both in order, on a
 * fresh database or on one caught up later, ends in the same place.
 *
 * ORDER MATTERS, VALUES DO NOT DISAPPEAR. The primary language is written
 * first, because "first" is what the public switch, the editor's default and
 * the fallback rule all mean by it. `primary_content_language` itself is
 * read and never written here: this migration has no opinion about which
 * language a site is written in, only about the set it publishes.
 *
 * FORWARD-ONLY AND NON-DESTRUCTIVE. No `_nl` or `_en` column is touched, no
 * content is rewritten, no row is deleted, and nothing a visitor or an
 * editor sees changes -- the value being corrected is one nothing branches
 * on. Idempotent: running it twice writes the same value.
 */
final class CorrectTheStoredContentLanguages extends AbstractMigration
{
    private const SETTING_PRIMARY = 'primary_content_language';
    private const SETTING_ENABLED = 'enabled_content_languages';

    /**
     * The content languages of Multilingual V1, in registry order.
     *
     * Written out rather than read from App\Service\Language\LanguageRegistry
     * on purpose. A migration is a record of what happened on a date; if a
     * later version registers a third language, this one must still produce
     * the pair it produced today, and the settings screen -- not a migration
     * that already ran -- is what takes a site to a wider set.
     */
    private const CONTENT_LANGUAGES = ['nl', 'en'];

    public function up(): void
    {
        if (!$this->hasTable('site_settings')) {
            return;
        }

        $value = implode(',', $this->contentLanguagesPrimaryFirst());

        // Upsert against the unique index on setting_key: a database that
        // never received the row -- one where 20260910140000 found no
        // `pages` table and returned early -- gets it, and one that holds the
        // wrong value has it corrected. Unlike the INSERT IGNORE of the
        // migration this repairs, an existing row must NOT win here: being
        // wrong is the reason this migration exists.
        $this->execute(sprintf(
            'INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at)'
            . " VALUES ('%s', '%s', NOW(), NOW())"
            . ' ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()',
            self::SETTING_ENABLED,
            $value
        ));
    }

    /**
     * Deliberately empty, like the other settings migrations in this project.
     * There is nothing to roll back to: the value this replaces was
     * incorrect, and restoring it would only put the defect back.
     */
    public function down(): void
    {
    }

    /**
     * The full set this build publishes, the site's own language first.
     *
     * A stored primary this build does not know -- a row from a newer
     * version, or a hand-edited one -- falls back to the first registered
     * language rather than being trusted, the same safe direction
     * App\Service\Language\ContentLanguages::primary() takes.
     *
     * @return string[]
     */
    private function contentLanguagesPrimaryFirst(): array
    {
        $primary = self::CONTENT_LANGUAGES[0];

        $row = $this->fetchRow(sprintf(
            "SELECT setting_value FROM site_settings WHERE setting_key = '%s'",
            self::SETTING_PRIMARY
        ));

        $stored = is_array($row) ? trim((string) ($row['setting_value'] ?? '')) : '';
        if (in_array($stored, self::CONTENT_LANGUAGES, true)) {
            $primary = $stored;
        }

        return array_merge(
            [$primary],
            array_values(array_filter(
                self::CONTENT_LANGUAGES,
                static fn (string $code): bool => $code !== $primary
            ))
        );
    }
}
