<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrderItemsTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('order_items');
        $table
            ->addColumn('order_id', 'integer', ['signed' => false])
            ->addColumn('product_id', 'integer', ['signed' => false])
            ->addColumn('quantity', 'integer', ['default' => 1])
            // Price snapshot at time of order, so later product price changes
            // never rewrite the total of an existing order.
            ->addColumn('unit_price', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('order_id', 'orders', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addForeignKey('product_id', 'products', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ])
            ->create();
    }
}
