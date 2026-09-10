<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Tenth CMS-editable page-section type, and the fourth repeater-with-heading
 * (after Feature grid / FAQ / Stat strip): the "Step list" pattern
 * (`.process` > `.process-step`) currently used once, on index.php's
 * "Werkwijze" section — see docs/CMS_CONTENT_AUDIT.md, proposed type #5.
 *
 * Same parent/child shape and conventions as faq_sections/faq_items (see
 * those migrations): rows are keyed by (page_slug, section_key) rather than
 * page_slug alone, in case a page ever needs more than one step list.
 *
 * The current Werkwijze section only has an eyebrow + H2 heading (no lead
 * paragraph in the markup) — same shape as faq_sections, so no lead_nl/
 * lead_en column here either. The steps themselves live in step_list_items
 * (see next migration). App\Service\StepListContent is the only thing that
 * should read this table for public rendering; it owns per-section fallback
 * defaults so a missing row/section or an unreachable database never breaks
 * a page. Step numbers ("1", "2", ...) are NOT stored anywhere — the
 * frontend derives them purely from item order via a CSS counter
 * (`.process{ counter-reset: step; }` in assets/css/style.css).
 */
final class CreateStepListSectionsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('step_list_sections', ['id' => true]);
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

        // Seed the one currently-existing step list section so the public
        // site keeps rendering identical output the moment this migration runs.
        $now = date('Y-m-d H:i:s');
        $table->insert([
            [
                'page_slug' => 'index',
                'section_key' => 'werkwijze',
                'eyebrow_nl' => 'Werkwijze',
                'eyebrow_en' => 'Process',
                'title_nl' => 'Van idee naar eindproduct',
                'title_en' => 'From idea to finished piece',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('step_list_sections')->drop()->save();
    }
}
