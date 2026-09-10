<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * "Stat strip" section (`.stat-strip` > `.stat`) — currently only the
 * index.php Capability band (`bg-forest` section right after the Services
 * carousel). See docs/CMS_CONTENT_AUDIT.md, proposed type #10, and
 * App\Service\StatStripContent for the fallback/state rules built on top of
 * this table. Same parent/child + section_key convention as
 * feature_grids/feature_grid_items (see those migrations).
 *
 * The current markup has no section-level heading (no eyebrow/H2/lead) — it
 * is a bare `<div class="stat-strip">` of stat items directly inside a
 * `bg-forest` band — so this table only carries visibility, not any
 * section-level content field. If a future usage genuinely needs a heading,
 * that is a new nullable column then, not something to speculatively add now.
 */
final class CreateStatStripsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('stat_strips', ['id' => true]);
        $table
            ->addColumn('page_slug', 'string', ['limit' => 100])
            ->addColumn('section_key', 'string', ['limit' => 100])
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

        $now = date('Y-m-d H:i:s');
        $table->insert([
            [
                'page_slug' => 'index',
                'section_key' => 'capability-band',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('stat_strips')->drop()->save();
    }
}
