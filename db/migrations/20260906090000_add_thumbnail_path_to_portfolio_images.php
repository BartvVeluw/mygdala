<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds an optional `thumbnail_path` column to portfolio_gallery_items and
 * portfolio_item_images (Portfolio image-optimization step). A small CMS
 * preview (~480px long edge, see App\Service\ImageOptimizer) generated
 * alongside every NEW optimized upload — see App\Service\
 * PortfolioImageProcessor — so admin overview cards/grids never have to
 * load the full 2560px optimized image just to show a 200-400px thumbnail.
 *
 * Nullable and NOT backfilled: existing images are never retroactively
 * modified or re-processed by this change (per the approved scope). Every
 * row that predates this migration simply has thumbnail_path = NULL, and
 * the admin templates fall back to the full image_path wherever a
 * thumbnail is missing — identical to how a pre-existing item already
 * rendered before this feature existed.
 */
final class AddThumbnailPathToPortfolioImages extends AbstractMigration
{
    public function up(): void
    {
        $this->table('portfolio_gallery_items')
            ->addColumn('thumbnail_path', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->update();

        $this->table('portfolio_item_images')
            ->addColumn('thumbnail_path', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->update();
    }

    public function down(): void
    {
        $this->table('portfolio_gallery_items')
            ->removeColumn('thumbnail_path')
            ->update();

        $this->table('portfolio_item_images')
            ->removeColumn('thumbnail_path')
            ->update();
    }
}
