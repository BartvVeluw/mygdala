<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddShippingAndConfirmationToOrders extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('orders');
        $table
            // Shipping cost at time of order — checkout.php already computes this (see
            // SHIPPING_COSTS) but never stored it, so it couldn't be shown again later
            // (e.g. in confirmation emails) without re-deriving it from the shipping method,
            // which isn't stored either. Storing it directly avoids that.
            ->addColumn('shipping_cost', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0.00, 'after' => 'total'])
            // Set once the paid-order confirmation emails (customer + shop) have been sent.
            // Used as the idempotency guard so repeated webhook calls / status syncs for the
            // same order never send the confirmation twice.
            ->addColumn('confirmation_sent_at', 'datetime', ['null' => true, 'after' => 'mollie_status'])
            ->update();
    }
}
