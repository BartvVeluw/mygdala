<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Stores the already server-validated shipping method key ("afhalen" /
 * "verzenden") directly on the order, instead of deriving it from
 * shipping_cost (see adminShippingMethodLabel() in admin/_labels.php,
 * which relied on the two shipping methods always having distinct fixed
 * prices — fragile if prices ever change). shipping_cost is kept as-is,
 * unrelated concern (the amount actually charged).
 */
final class AddShippingMethodToOrders extends AbstractMigration
{
    public function up(): void
    {
        $this->table('orders')
            ->addColumn('shipping_method', 'string', ['limit' => 20, 'null' => true, 'after' => 'shipping_cost'])
            ->update();

        // Backfill existing orders from the same afhalen(=0)/verzenden(>0) cost
        // rule the admin pages used before this column existed, so historical
        // orders show a sensible method too instead of staying null.
        $this->execute(
            "UPDATE orders SET shipping_method = IF(shipping_cost > 0, 'verzenden', 'afhalen') WHERE shipping_method IS NULL"
        );
    }

    public function down(): void
    {
        $this->table('orders')->removeColumn('shipping_method')->update();
    }
}
