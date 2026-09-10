<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * CMS-managed Portfolio categories (Portfolio-redesign, step 2), replacing
 * the fixed App\Service\PortfolioGalleryContent::CATEGORY_KEYS list
 * ('hout'/'metaal'/'zakelijk') as the source of truth for which categories
 * exist. See db/migrations/*_create_portfolio_item_categories_table.php for
 * the many-to-many relation to portfolio_gallery_items — this table only
 * defines the categories themselves.
 *
 * `slug` is generated once at creation (same algorithm/convention as
 * products' slug — see api/admin/_portfolio_validation.php) and never
 * regenerated when name_nl changes: it is the stable identifier used in the
 * admin overview's ?cat= filter and the public filter bar's data-filter
 * values, so renaming a category (e.g. "Hout" -> "Massief hout") must not
 * change existing URLs/relationships. Seeded here with the exact 3 slugs the
 * old fixed CATEGORY_KEYS already used, so every existing data-filter/
 * data-category reference in the current markup keeps matching unchanged —
 * see the next migration's backfill.
 *
 * name_en is nullable — like every other bilingual field in this project
 * (e.g. portfolio_gallery_items.title_en), it falls back to name_nl when
 * blank (see App\Service\PortfolioGalleryContent).
 *
 * This is specifically a Portfolio taxonomy, deliberately not shared with
 * webshop product categorization (no such concept exists for products
 * today) — see MAIN.MD.
 */
final class CreatePortfolioCategoriesTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('portfolio_categories', ['id' => true]);
        $table
            ->addColumn('name_nl', 'string', ['limit' => 100])
            ->addColumn('name_en', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('slug', 'string', ['limit' => 100])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['slug'], ['unique' => true])
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
            ['name_nl' => 'Hout', 'name_en' => 'Wood', 'slug' => 'hout', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['name_nl' => 'Metaal', 'name_en' => 'Metal', 'slug' => 'metaal', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name_nl' => 'Zakelijk', 'name_en' => 'Business', 'slug' => 'zakelijk', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('portfolio_categories')->drop()->save();
    }
}
