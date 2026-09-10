<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Customer-facing right-of-withdrawal ("herroepingsrecht") request, see
 * herroeping.php (public form), api/withdrawal-request.php (submission) and
 * admin/withdrawal-requests.php / withdrawal-request.php (review).
 *
 * Deliberately whole-order, not per-order-item: `orders`/`order_items` (see
 * db/migrations/20260903120200_create_orders_table.php and
 * .../20260903120300_create_order_items_table.php) carry no field that says
 * whether a given line was personalised/made-to-order — there is no
 * "is_customizable" column on products, no "requires_parcel"-style flag for
 * customization, and no free-text personalization field captured anywhere in
 * the cart/checkout flow (verified 2026-09-06, see MAIN.MD). Since the data
 * model cannot safely tell a bespoke keychain apart from an off-the-shelf
 * one, this table intentionally does NOT attempt to auto-approve or
 * auto-reject a request based on product data — every request is created
 * with status 'nieuw' and reviewed manually by the shop owner (who does know
 * which items were custom), exactly like contact_requests' simple
 * "nieuw"/"gelezen" review model. Inventing an automatic exclusion here
 * without that data would risk silently granting or blocking a legal right
 * incorrectly; see MAIN.MD for what a real fix (e.g. a
 * products.is_customizable flag) would need.
 *
 * `order_id` is RESTRICT (never CASCADE-deleted) — same reasoning as
 * order_items.product_id: an order is never actually deleted by this
 * application, so this only matters as a safety net.
 *
 * `customer_email` is a snapshot of orders/customers.email at submission
 * time (not just joined live), so the request keeps a stable record of which
 * address made the claim even if the customer record is ever edited later.
 */
final class CreateWithdrawalRequestsTable extends AbstractMigration
{
    public const STATUSES = ['nieuw', 'in_behandeling', 'afgehandeld'];

    public function change(): void
    {
        $table = $this->table('withdrawal_requests');
        $table
            ->addColumn('order_id', 'integer', ['signed' => false])
            ->addColumn('customer_email', 'string', ['limit' => 254])
            ->addColumn('reason', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'nieuw'])
            ->addColumn('admin_note', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('order_id', 'orders', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ])
            ->addIndex(['order_id'])
            ->create();
    }
}
