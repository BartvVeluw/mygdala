<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Fourth CMS-editable page-section type, and the second repeater (after
 * Feature grid): the "FAQ list" pattern (`.faq-list` > `.faq-item` /
 * `<details>`) currently used once, on diensten.php — see
 * docs/CMS_CONTENT_AUDIT.md, proposed type #9.
 *
 * Same parent/child shape and conventions as feature_grids/feature_grid_items
 * (see those migrations): rows are keyed by (page_slug, section_key) rather
 * than page_slug alone, since a page could have more than one FAQ section in
 * the future — section_key is a short stable identifier for *which* FAQ list
 * on that page (currently just "faq" on diensten.php).
 *
 * The current diensten.php FAQ section only has an eyebrow + H2 heading (no
 * lead paragraph in the markup) — so unlike feature_grids, there is no
 * lead_nl/lead_en column here: this schema only stores fields the existing
 * frontend actually renders. The questions/answers themselves live in
 * faq_items (see next migration). App\Service\FaqContent is the only thing
 * that should read this table for public rendering; it owns per-section
 * fallback defaults so a missing row/section or an unreachable database
 * never breaks a page.
 */
final class CreateFaqSectionsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('faq_sections', ['id' => true]);
        $table
            ->addColumn('page_slug', 'string', ['limit' => 100])
            ->addColumn('section_key', 'string', ['limit' => 100])
            ->addColumn('eyebrow_nl', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('eyebrow_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('title_nl', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['page_slug', 'section_key'], ['unique' => true])
            ->create();

        if (InstallState::isFreshInstall($this)) {
            // Everything below is Van Veluw Laserdesign's own page
            // content, lifted out of the templates it used to be
            // hardcoded in. An installation with no history to preserve
            // gets the empty table and builds its own pages.
            // See src/Install/InstallState.php.
            return;
        }

        // Seed the one currently-existing FAQ section so the public site
        // keeps rendering identical output the moment this migration runs.
        $now = date('Y-m-d H:i:s');
        $table->insert([
            [
                'page_slug' => 'diensten',
                'section_key' => 'faq',
                'eyebrow_nl' => 'Veelgestelde vragen',
                'eyebrow_en' => 'Frequently asked questions',
                'title_nl' => 'Nog vragen?',
                'title_en' => 'Any questions?',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('faq_sections')->drop()->save();
    }
}
