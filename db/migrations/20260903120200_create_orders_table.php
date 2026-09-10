<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrdersTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('orders');
        $table
            ->addColumn('customer_id', 'integer', ['signed' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
            // Mollie linkage: payment id + Mollie's own raw status, kept separate
            // from our own order `status` so we can always see exactly what Mollie last reported.
            ->addColumn('mollie_payment_id', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('mollie_status', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('total', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('currency', 'string', ['limit' => 3, 'default' => 'EUR'])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('customer_id', 'customers', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ])
            ->addIndex(['mollie_payment_id'], ['unique' => true])
            ->create();
    }
}
