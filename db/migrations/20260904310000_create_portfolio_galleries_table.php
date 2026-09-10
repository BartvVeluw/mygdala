<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * "Portfolio / gallery item" section (`.gallery-grid` > `.gallery-item`) —
 * currently only portfolio.php's 14-item grid. See
 * docs/CMS_CONTENT_AUDIT.md, proposed type #6, and
 * App\Service\PortfolioGalleryContent for the fallback/state rules built on
 * top of this table. Same parent/child + section_key convention as
 * feature_grids/stat_strips (see those migrations) — kept even for this
 * single current usage so a future gallery (e.g. deriving the homepage
 * "Portfolio teaser" from a "featured" flag on these same items) never needs
 * a schema change, only a new SECTIONS entry.
 *
 * The current markup has no section-level heading around the grid itself
 * (the page's own Page Hero already introduces the page) — only the filter
 * bar (theme/functional, stays hardcoded, see AdminPageRegistry) and the
 * grid. So, like stat_strips, this table only carries visibility, not any
 * section-level content field.
 */
final class CreatePortfolioGalleriesTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('portfolio_galleries', ['id' => true]);
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
                'page_slug' => 'portfolio',
                'section_key' => 'gallery',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('portfolio_galleries')->drop()->save();
    }
}
