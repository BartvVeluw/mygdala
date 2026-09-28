<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The Uitgelicht product block (`featured_product`,
 * App\Service\Blocks\FeaturedProductBlock): one product of the Shop in a
 * promotional layout on an ordinary page (CONTENT-BLOCKS.md, "Uitgelicht
 * product"). One row per instance, and only the block's own choices: the
 * product itself — its name, price, pictures, variants, stock, questions and
 * specifications — stays in the Shop's tables and is read live at every
 * render, so nothing of it is copied here.
 *
 *   product_id          the product on show; NULL while none is chosen yet,
 *                       which renders nothing. ON DELETE SET NULL: a product
 *                       that is deleted leaves an empty block behind, never a
 *                       block that stops the delete (order_items does the
 *                       same since 20260908120000)
 *   show_name           0/1, 1 by default
 *   show_price          0/1, 1 by default; never shows a price the product's
 *                       own purchase mode hides (App\Service\PurchaseMode)
 *   show_description    0/1, 1 by default: the product's own description
 *   show_specifications 0/1, 0 by default: the product's own specifications
 *   image_mode          'gallery' (the start) or 'main' —
 *                       App\Service\FeaturedProductContent::IMAGE_MODES
 *   image_position      'left' (the start) or 'right' — ::IMAGE_POSITIONS
 *   image_size          'small', 'medium' (the start) or 'large' —
 *                       ::IMAGE_SIZES; the widths are in
 *                       assets/css/shop/featured-product.css
 *   content_align       'left' (the start), 'center' or 'right' — ::ALIGNMENTS
 *   ordering            'direct' (the start) or 'view' — ::ORDERINGS: whether
 *                       THIS block offers the cart. It can only take the cart
 *                       away; what the product itself allows always decides
 *   show_product_link   0/1, 1 by default: the button to the product page
 *
 * The block's words — an optional intro and the button's own label — are in
 * block_translations (FeaturedProductBlock::translatableFields()), never a
 * column here.
 *
 * A new table with nothing to take over: no backfill, no fresh-install guard,
 * idempotent (db/migrations/CLAUDE.md).
 */
final class CreateFeaturedProductsTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('featured_products')) {
            return;
        }

        $this->table('featured_products', ['id' => true])
            ->addColumn('page_slug', 'string', ['limit' => 100])
            ->addColumn('section_key', 'string', ['limit' => 100])
            ->addColumn('product_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'products.id; NULL while none is chosen'])
            ->addColumn('show_name', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('show_price', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('show_description', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('show_specifications', 'boolean', ['null' => false, 'default' => 0])
            ->addColumn('image_mode', 'string', ['limit' => 10, 'null' => false, 'default' => 'gallery', 'comment' => 'App\Service\FeaturedProductContent::IMAGE_MODES'])
            ->addColumn('image_position', 'string', ['limit' => 10, 'null' => false, 'default' => 'left', 'comment' => 'App\Service\FeaturedProductContent::IMAGE_POSITIONS'])
            ->addColumn('image_size', 'string', ['limit' => 10, 'null' => false, 'default' => 'medium', 'comment' => 'App\Service\FeaturedProductContent::IMAGE_SIZES'])
            ->addColumn('content_align', 'string', ['limit' => 10, 'null' => false, 'default' => 'left', 'comment' => 'App\Service\FeaturedProductContent::ALIGNMENTS'])
            ->addColumn('ordering', 'string', ['limit' => 10, 'null' => false, 'default' => 'direct', 'comment' => 'App\Service\FeaturedProductContent::ORDERINGS'])
            ->addColumn('show_product_link', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['page_slug', 'section_key'], ['unique' => true])
            ->addForeignKey('product_id', 'products', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('featured_products')) {
            $this->table('featured_products')->drop()->save();
        }
    }
}
