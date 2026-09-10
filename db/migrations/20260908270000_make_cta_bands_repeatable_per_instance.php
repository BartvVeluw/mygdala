<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phase 2 of the content-block refactor (docs/content-blocks/ROADMAP.md):
 * the CTA band becomes ONE standard reusable block type whose every
 * instance owns its own content.
 *
 * Until now `cta_bands` was keyed by `page_slug` alone (see
 * 20260904210000_create_cta_bands_table.php), because each of the four
 * original pages carried exactly one CTA band. That schema silently caps a
 * page at one CTA band forever: a second instance on the same page would
 * upsert straight over the first one's content. Adding `section_key` — the
 * same (page_slug, section_key) addressing every repeater block type in this
 * project already uses (feature_grids, faq_sections, rich_text_sections,
 * marquee_sections, ...) — is what makes two CTA bands on one page two
 * independent blocks instead of two views of the same row.
 *
 * Content preservation: every existing row keeps its content untouched and
 * simply gains section_key = 'main' (App\Service\CtaBandContent's
 * MIGRATED_SECTION_KEY), and the page_sections rows pointing at them get the
 * same key so App\Service\SectionRegistry::render() finds them. Instances
 * created afterwards get a random custom-xxxxxxxx key like every other
 * repeater type. No public page changes.
 *
 * Idempotent (skips once `section_key` exists) and forward-only. MySQL/
 * Vimexx-compatible: a plain ALTER TABLE plus an index swap and one UPDATE,
 * no CTEs, no window functions.
 */
final class MakeCtaBandsRepeatablePerInstance extends AbstractMigration
{
    /** Must stay in sync with App\Service\CtaBandContent::MIGRATED_SECTION_KEY. */
    private const MIGRATED_SECTION_KEY = 'main';

    public function up(): void
    {
        $table = $this->table('cta_bands');

        if ($table->hasColumn('section_key')) {
            // Already repeatable.
            return;
        }

        // Existing rows all become the page's 'main' CTA band; the default
        // is what fills them in during the ALTER, so no separate backfill
        // UPDATE is needed for cta_bands itself.
        $table
            ->addColumn('section_key', 'string', [
                'limit' => 100,
                'null' => false,
                'default' => self::MIGRATED_SECTION_KEY,
                'after' => 'page_slug',
            ])
            ->update();

        // The old uniqueness rule ("one CTA band per page") is exactly what
        // has to go; the new one keeps two instances on one page apart.
        if ($this->table('cta_bands')->hasIndexByName('page_slug')) {
            $this->table('cta_bands')->removeIndexByName('page_slug')->update();
        }

        $this->table('cta_bands')
            ->addIndex(['page_slug', 'section_key'], ['unique' => true])
            ->update();

        // page_sections stores the key the renderer looks content up with.
        // Existing cta_band attachments have section_key NULL (the type was
        // not repeatable), so point them at the row they have always meant.
        $this->execute(
            "UPDATE page_sections
                SET section_key = '" . self::MIGRATED_SECTION_KEY . "', updated_at = NOW()
              WHERE section_type = 'cta_band'
                AND (section_key IS NULL OR section_key = '')"
        );
    }

    public function down(): void
    {
        $table = $this->table('cta_bands');

        if (!$table->hasColumn('section_key')) {
            return;
        }

        $table->removeColumn('section_key')->update();
    }
}
