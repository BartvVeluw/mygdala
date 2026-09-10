<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds per-product shipping settings (App\Service\Shipping\ShippingProfile,
 * App\Service\Shipping\ShippingCalculationService) — see MAIN.MD "Shipping
 * calculation system".
 *
 * Defaults are deliberately the most expensive/safest option, not the
 * cheapest: every existing product (and every product created without
 * touching the new "Verzending" admin section) defaults to
 * shipping_profile=parcel + requires_parcel=1. This guarantees no product
 * can ever silently ship for free/cheap just because its shipping data was
 * never filled in — the owner has to deliberately pick "Briefpost" or
 * "Brievenbuspakket" for a lighter/cheaper profile to apply.
 * shipping_weight_grams defaults to 0, which is harmless for parcel (the
 * seeded NL parcel rate has no weight limit) but would fail safe (block
 * checkout, see ShippingCalculationService) if a product were ever switched
 * to letter/letterbox without also setting a real weight, since 0g will
 * simply match the smallest configured bracket rather than silently being
 * free.
 */
final class AddShippingFieldsToProducts extends AbstractMigration
{
    public function up(): void
    {
        $this->table('products')
            ->addColumn('shipping_profile', 'string', ['limit' => 20, 'default' => 'parcel', 'after' => 'stock'])
            ->addColumn('shipping_weight_grams', 'integer', ['signed' => false, 'default' => 0, 'after' => 'shipping_profile'])
            ->addColumn('requires_parcel', 'boolean', ['default' => true, 'after' => 'shipping_weight_grams'])
            ->update();
    }

    public function down(): void
    {
        $this->table('products')
            ->removeColumn('shipping_profile')
            ->removeColumn('shipping_weight_grams')
            ->removeColumn('requires_parcel')
            ->update();
    }
}
