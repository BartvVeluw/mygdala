<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddFulfilmentStatusToOrders extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('orders');
        $table
            // Separate from `status` (our own payment status, driven by Mollie) and
            // `mollie_status` (Mollie's raw status) — this tracks the admin's own
            // fulfilment workflow for paid orders and must never be touched by the
            // Mollie payment-sync code.
            ->addColumn('fulfilment_status', 'string', ['limit' => 20, 'default' => 'Nieuw', 'after' => 'mollie_status'])
            ->update();
    }
}
