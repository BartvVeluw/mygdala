<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Additional gallery photos for a Portfolio item's optional project detail
 * page (CMS-fundament Portfolio-redesign) — a proper child table, same
 * shape/conventions as variant_images (see
 * db/migrations/20260904170000_create_variant_images_table.php): no
 * "image_2/image_3/..." columns, first row in sort_order order is simply the
 * first image shown in the gallery (the item's own image_path on
 * portfolio_gallery_items stays the separate, existing main/hero image —
 * this table is only the *additional* photos).
 *
 * Purely additive: a brand new, empty table. No existing portfolio item
 * loses or changes any data because of this migration.
 */
final class CreatePortfolioItemImagesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('portfolio_item_images')
            ->addColumn('portfolio_item_id', 'integer', ['signed' => false])
            ->addColumn('image_path', 'string', ['limit' => 255])
            ->addColumn('alt_nl', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('alt_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('portfolio_item_id', 'portfolio_gallery_items', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['portfolio_item_id'])
            ->create();
    }

    public function down(): void
    {
        $this->table('portfolio_item_images')->drop()->save();
    }
}
