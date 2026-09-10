<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds a product-title snapshot to order_items: `product_name`/`product_name_en`
 * capture the product's name at the moment the order was placed, so renaming a
 * product later never retroactively changes what a historical order/admin
 * page/confirmation email displays (see App\Repository\OrderRepository::
 * findItems(), which previously joined live `products.name` — MAIN.MD "Order
 * snapshot architecture"). Same idea as the existing `unit_price`/
 * `variant_label` snapshots on this table.
 *
 * Backfill: every existing order_items row's product_id is RESTRICT-protected
 * (see db/migrations/20260903120300_create_order_items_table.php), so the
 * referenced product still exists for every row — backfilled from the
 * product's *current* name, which is the best available reconstruction for
 * orders placed before this migration. If that product was renamed since the
 * order was placed, the true historical name was never recorded and cannot be
 * recovered — a documented limitation, not invented data.
 *
 * New order_items rows (see OrderRepository::addItems(), now called with the
 * product name already loaded during checkout) always populate both columns
 * directly at insert time, so no backfill gap exists going forward.
 */
final class AddProductSnapshotToOrderItems extends AbstractMigration
{
    public function up(): void
    {
        $this->table('order_items')
            ->addColumn('product_name', 'string', ['limit' => 255, 'null' => true, 'after' => 'variant_label'])
            ->addColumn('product_name_en', 'string', ['limit' => 255, 'null' => true, 'after' => 'product_name'])
            ->update();

        $this->execute(
            'UPDATE order_items oi
             JOIN products p ON p.id = oi.product_id
             SET oi.product_name = p.name, oi.product_name_en = p.name_en
             WHERE oi.product_name IS NULL'
        );
    }

    public function down(): void
    {
        $this->table('order_items')
            ->removeColumn('product_name')
            ->removeColumn('product_name_en')
            ->update();
    }
}
