<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds a full, structured shipping address AND a separate billing address to
 * `orders` itself, instead of the order only ever pointing at whatever
 * address currently happens to sit on `customers` (which is overwritten on
 * every checkout for a repeat email — see CustomerRepository::
 * findOrCreateByEmail()). Orders must keep the exact address they were
 * placed with, and now need two of them (shipping + billing), so the address
 * has to live on the order row, not the mutable customer row.
 *
 * `shipping_street`/`shipping_house_number` etc. replace the old single
 * free-text "straat" (street + number combined) with the split fields the
 * Dutch BAG/PDOK postcode+house-number lookup needs (see
 * App\Service\Address\DutchAddressLookupService) — the same split is used
 * for every country for consistency, even though only NL addresses are
 * looked up/verified against PDOK.
 *
 * `billing_same_as_shipping` defaults to true (including for every existing
 * row — MySQL backfills a new NOT NULL column's default into all existing
 * rows), which correctly reflects that orders placed before this migration
 * only ever had one address. The `billing_*` columns stay null in that case
 * (see App\Repository\OrderRepository::resolveBillingAddress(), which falls
 * back to the shipping address whenever billing_same_as_shipping is true) —
 * deliberately not duplicating the shipping address into the billing columns
 * when they're identical.
 *
 * Purely additive, no backfill of the new shipping_* columns: an order
 * placed before this migration has no order-level shipping address recorded
 * (all shipping_* columns stay null), which correctly reflects that nothing
 * was captured at the order level at the time. OrderRepository::
 * resolveShippingAddress() falls back to the joined customers row (the
 * address that order actually used) whenever shipping_street is null, so
 * existing orders keep displaying/emailing exactly what they always did.
 */
final class AddBillingAndStructuredAddressesToOrders extends AbstractMigration
{
    public function change(): void
    {
        $this->table('orders')
            // Shipping address (order-level snapshot; the customer record
            // stays a separate, mutable "last known contact info" record).
            ->addColumn('shipping_first_name', 'string', ['limit' => 100, 'null' => true, 'after' => 'terms_content_hash'])
            ->addColumn('shipping_last_name', 'string', ['limit' => 100, 'null' => true, 'after' => 'shipping_first_name'])
            ->addColumn('shipping_company', 'string', ['limit' => 150, 'null' => true, 'after' => 'shipping_last_name'])
            ->addColumn('shipping_country', 'string', ['limit' => 2, 'null' => true, 'after' => 'shipping_company'])
            ->addColumn('shipping_postal_code', 'string', ['limit' => 15, 'null' => true, 'after' => 'shipping_country'])
            ->addColumn('shipping_house_number', 'string', ['limit' => 20, 'null' => true, 'after' => 'shipping_postal_code'])
            ->addColumn('shipping_house_number_addition', 'string', ['limit' => 20, 'null' => true, 'after' => 'shipping_house_number'])
            ->addColumn('shipping_street', 'string', ['limit' => 255, 'null' => true, 'after' => 'shipping_house_number_addition'])
            ->addColumn('shipping_city', 'string', ['limit' => 100, 'null' => true, 'after' => 'shipping_street'])
            // Billing address: only populated when it's actually different
            // from shipping (see class docblock above).
            ->addColumn('billing_same_as_shipping', 'boolean', ['default' => true, 'after' => 'shipping_city'])
            ->addColumn('billing_first_name', 'string', ['limit' => 100, 'null' => true, 'after' => 'billing_same_as_shipping'])
            ->addColumn('billing_last_name', 'string', ['limit' => 100, 'null' => true, 'after' => 'billing_first_name'])
            ->addColumn('billing_company', 'string', ['limit' => 150, 'null' => true, 'after' => 'billing_last_name'])
            ->addColumn('billing_country', 'string', ['limit' => 2, 'null' => true, 'after' => 'billing_company'])
            ->addColumn('billing_postal_code', 'string', ['limit' => 15, 'null' => true, 'after' => 'billing_country'])
            ->addColumn('billing_house_number', 'string', ['limit' => 20, 'null' => true, 'after' => 'billing_postal_code'])
            ->addColumn('billing_house_number_addition', 'string', ['limit' => 20, 'null' => true, 'after' => 'billing_house_number'])
            ->addColumn('billing_street', 'string', ['limit' => 255, 'null' => true, 'after' => 'billing_house_number_addition'])
            ->addColumn('billing_city', 'string', ['limit' => 100, 'null' => true, 'after' => 'billing_street'])
            ->update();
    }
}
