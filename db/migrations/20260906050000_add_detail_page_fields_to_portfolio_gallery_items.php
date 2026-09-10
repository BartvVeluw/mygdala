<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds the optional "project detail page" fields to portfolio_gallery_items
 * (CMS-fundament Portfolio-redesign). Every existing item defaults to
 * has_detail_page = false / slug = NULL / no intro/description text, so
 * nothing about an existing item's public rendering changes until the owner
 * explicitly opts an item into a detail page via the admin editor — see
 * App\Service\PortfolioGalleryContent and MAIN.MD.
 *
 * `slug` is nullable (only items with has_detail_page = true need one) and
 * uniquely indexed — MySQL/InnoDB unique indexes allow any number of NULL
 * rows, so items without a detail page never collide with each other.
 * Uniqueness against non-null values is still enforced server-side in
 * api/admin/update-portfolio-item.php before it ever reaches this index.
 *
 * The intro/description fields are separate fields (not reused from
 * subtitle_nl/en) per the approved design: the detail page reuses the
 * existing image/title/subtitle/alt text, but its own longer-form copy is
 * additive, optional content that doesn't exist for items without a detail
 * page.
 */
final class AddDetailPageFieldsToPortfolioGalleryItems extends AbstractMigration
{
    public function up(): void
    {
        $this->table('portfolio_gallery_items')
            ->addColumn('has_detail_page', 'boolean', ['default' => false])
            ->addColumn('slug', 'string', ['limit' => 170, 'null' => true, 'default' => null])
            ->addColumn('intro_nl', 'text', ['null' => true, 'default' => null])
            ->addColumn('intro_en', 'text', ['null' => true, 'default' => null])
            ->addColumn('description_nl', 'text', ['null' => true, 'default' => null])
            ->addColumn('description_en', 'text', ['null' => true, 'default' => null])
            ->addIndex(['slug'], ['unique' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('portfolio_gallery_items')
            ->removeIndex(['slug'])
            ->removeColumn('has_detail_page')
            ->removeColumn('slug')
            ->removeColumn('intro_nl')
            ->removeColumn('intro_en')
            ->removeColumn('description_nl')
            ->removeColumn('description_en')
            ->update();
    }
}
