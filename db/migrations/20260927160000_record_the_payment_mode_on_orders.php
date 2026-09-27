<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Mollie Setup 2.0: every order remembers whether its payment was a test
 * payment or a real one (`orders.payment_mode`, App\Repository\OrderRepository):
 *
 *   'test'  the payment was started with a test key: no real money. The
 *           order is marked TEST, counts for no revenue and gets no invoice
 *           number from the real sequence
 *   'live'  the payment was started with a live key: a real sale
 *   NULL    unknown — every order from before this column. Which key paid
 *           an old order cannot be reconstructed, so those orders keep
 *           behaving exactly as they did: real sales, real invoices, and the
 *           lookup that tries the active key and then the other one
 *
 * Written once, when the payment is created (api/checkout.php), and never
 * derived later from the shop's current mode or key: a test order stays a
 * test order when the shop goes live five minutes after it.
 *
 * ADD-ONLY. One nullable column and nothing written: no backfill, no order,
 * payment id, status, total or invoice is touched. No fresh-install guard: a
 * new installation and an upgraded one end on the same table
 * (db/migrations/CLAUDE.md). Idempotent: the column is checked first.
 */
final class RecordThePaymentModeOnOrders extends AbstractMigration
{
    public function up(): void
    {
        if ($this->table('orders')->hasColumn('payment_mode')) {
            return;
        }

        $this->table('orders')
            ->addColumn('payment_mode', 'string', [
                'limit' => 4,
                'null' => true,
                'default' => null,
                'after' => 'mollie_status',
                'comment' => "'test' or 'live', set when the payment is created; NULL = an order from before it was recorded",
            ])
            ->update();
    }

    public function down(): void
    {
        if ($this->table('orders')->hasColumn('payment_mode')) {
            $this->table('orders')->removeColumn('payment_mode')->update();
        }
    }
}
