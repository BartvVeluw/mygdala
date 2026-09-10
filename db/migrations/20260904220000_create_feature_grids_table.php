<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Third CMS-editable page-section type, and the first repeater: the
 * "Feature grid" pattern (`.feature-grid` > `.feature-card`) used on
 * index.php (value-proposition cards, no section heading) and over-mij.php
 * ("Mijn stijl" cards, with an eyebrow/H2/lead heading) — see
 * docs/CMS_CONTENT_AUDIT.md, proposed type #3, and MAIN.MD for the exact
 * differences found between the two usages before this schema was chosen.
 *
 * Unlike page_heroes/cta_bands (one fixed row per page, section implied),
 * a page can have more than one feature grid in the future, so rows are
 * keyed by (page_slug, section_key) rather than page_slug alone —
 * section_key is a short stable identifier for *which* grid on that page
 * (e.g. "value-props", "mijn-stijl"), so adding a second grid to an
 * existing page never needs a schema change.
 *
 * Section-level heading fields (eyebrow/title/lead) are nullable because the
 * index.php usage has no heading at all — the frontend template for that
 * page never renders them, and the admin editor hides those inputs for
 * sections with no heading (see App\Service\FeatureGridContent::SECTIONS,
 * has_heading).
 *
 * The cards themselves live in feature_grid_items (see next migration).
 * App\Service\FeatureGridContent is the only thing that should read this
 * table for public rendering; it owns per-section fallback defaults so a
 * missing row/section or an unreachable database never breaks a page.
 */
final class CreateFeatureGridsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('feature_grids', ['id' => true]);
        $table
            ->addColumn('page_slug', 'string', ['limit' => 100])
            ->addColumn('section_key', 'string', ['limit' => 100])
            ->addColumn('eyebrow_nl', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('eyebrow_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('title_nl', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('lead_nl', 'text', ['null' => true])
            ->addColumn('lead_en', 'text', ['null' => true])
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

        // Seed the two currently-existing grids so the public site keeps
        // rendering identical output the moment this migration runs. The
        // homepage grid has no heading in the current markup, so its
        // heading columns are seeded null on purpose (see class docblock).
        $now = date('Y-m-d H:i:s');
        $table->insert([
            [
                'page_slug' => 'index',
                'section_key' => 'value-props',
                'eyebrow_nl' => null,
                'eyebrow_en' => null,
                'title_nl' => null,
                'title_en' => null,
                'lead_nl' => null,
                'lead_en' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'page_slug' => 'over-mij',
                'section_key' => 'mijn-stijl',
                'eyebrow_nl' => 'Mijn stijl',
                'eyebrow_en' => 'My style',
                'title_nl' => 'Warm, rustig en persoonlijk',
                'title_en' => 'Warm, calm and personal',
                'lead_nl' => 'Ik houd van ontwerpen die mooi zijn in hun eenvoud, maar toch karakter hebben — geen massawerk, maar producten die passen bij mijn eigen stijl én bij die van jou.',
                'lead_en' => "I love designs that are beautiful in their simplicity but still have character — not mass production, but products that suit my own style and yours.",
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('feature_grids')->drop()->save();
    }
}
