<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Many-to-many relation between `collections` and `products`: which products
 * belong to a collection, and in which order inside that collection.
 *
 * Same junction-table shape as portfolio_item_categories /
 * product_variant_values (composite primary key, no surrogate id) — the
 * composite key is what makes the same product/collection combination
 * impossible to insert twice, at the database level, regardless of which UI
 * or forged request writes it.
 *
 * Both foreign keys are ON DELETE CASCADE, which is exactly the deletion
 * behaviour this feature requires:
 *   - deleting a COLLECTION removes only its rows in THIS table; the
 *     `products` rows themselves are never touched (nothing here cascades
 *     "upwards" into products), so a collection can never destroy catalog
 *     data. See App\Service\CollectionService::delete().
 *   - deleting a PRODUCT removes its rows here too, so a deleted product
 *     can never leave a dangling membership behind.
 * This deliberately differs from portfolio_item_categories, which uses
 * RESTRICT on the category side: there, a category still assigned to items
 * must not be deletable. Here the requirement is the opposite — a
 * collection is a lightweight grouping and must always be deletable,
 * precisely because doing so cannot lose anything but the grouping itself.
 *
 * `sort_order` is per collection (the product's position inside THAT
 * collection), so the same product can sit first in one collection and last
 * in another. The (collection_id, sort_order) index serves the public
 * collection page's ordered read, which is the only hot query here.
 */
final class CreateCollectionProductsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('collection_products', ['id' => false, 'primary_key' => ['collection_id', 'product_id']])
            ->addColumn('collection_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('product_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addIndex(['collection_id', 'sort_order'])
            ->addIndex(['product_id'])
            ->addForeignKey('collection_id', 'collections', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addForeignKey('product_id', 'products', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('collection_products')->drop()->save();
    }
}
