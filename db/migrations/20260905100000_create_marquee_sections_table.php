<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Eleventh CMS-editable page-section type: the homepage "Marquee"
 * (materialenband) — see docs/CMS_CONTENT_AUDIT.md, "Content trapped in JS".
 * Until now MARQUEE_ITEMS lived as a hardcoded array in assets/js/main.js,
 * invisible to any content model; this migration (and the next one, for the
 * items) moves that content into the database following the same parent/
 * child + section_key convention as stat_strips/stat_strip_items.
 *
 * The current marquee has no heading of its own (a bare continuous scrolling
 * strip), so — same as stat_strips — this table only carries visibility,
 * not any section-level content field.
 */
final class CreateMarqueeSectionsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('marquee_sections', ['id' => true]);
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
                'section_key' => 'materialenband',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('marquee_sections')->drop()->save();
    }
}
