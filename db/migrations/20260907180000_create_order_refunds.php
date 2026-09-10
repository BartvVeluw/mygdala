<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Minimal local refund tracking, synced from Mollie (see
 * App\Service\OrderPaymentSync::sync(), which fetches $payment->refunds() and
 * $payment->getAmountRefunded() whenever Mollie reports the payment
 * hasRefunds()). Mollie remains the source of truth for refund
 * processing/payouts — this only preserves enough locally to answer "was
 * this order fully/partially refunded" without looking in Mollie, per
 * MAIN.MD "Refunds".
 *
 * `orders.refunded_amount` is a cached total — always set directly from
 * Mollie's own `payment.amountRefunded` (never computed by summing our own
 * `order_refunds` copy, which could drift if a sync is ever missed) — for
 * fast admin/CSV display without a join. `order_refunds` is the per-refund
 * audit trail: one row per Mollie refund id, upserted on
 * (mollie_refund_id), so duplicate webhook delivery just re-applies the same
 * row rather than duplicating it.
 *
 * The original `orders.total` is never modified by a refund, so the original
 * sale amount and the refunded amount stay distinguishable (see MAIN.MD
 * "Refunds": historical order amounts must not simply be overwritten).
 */
final class CreateOrderRefunds extends AbstractMigration
{
    public function up(): void
    {
        $this->table('orders')
            ->addColumn('refunded_amount', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0.00, 'after' => 'total'])
            ->update();

        $table = $this->table('order_refunds', ['id' => true]);
        $table
            ->addColumn('order_id', 'integer', ['signed' => false])
            ->addColumn('mollie_refund_id', 'string', ['limit' => 50])
            ->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('status', 'string', ['limit' => 20])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('order_id', 'orders', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['mollie_refund_id'], ['unique' => true])
            ->addIndex(['order_id'])
            ->create();
    }

    public function down(): void
    {
        $this->table('order_refunds')->drop()->save();
        $this->table('orders')->removeColumn('refunded_amount')->update();
    }
}
