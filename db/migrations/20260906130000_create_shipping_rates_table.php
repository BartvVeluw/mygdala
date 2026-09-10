<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Admin-configurable shipping price rules (App\Repository\ShippingRateRepository,
 * App\Service\Shipping\ShippingCalculationService). One row = one weight
 * bracket for one shipping profile within one zone.
 *
 * Weight matching is "up to": a rate matches a cart's total shipping weight
 * when (min_weight_grams is null or weight >= min) and (max_weight_grams is
 * null or weight <= max); when several enabled rates match, the one with the
 * smallest max_weight_grams (nulls = unlimited, sorts last) wins. This lets
 * the Netherlands letter rates below be expressed purely as "up to 20g" /
 * "up to 50g" without needing an explicit min on the second row, and lets a
 * flat-rate profile (parcel) be a single row with both bounds null.
 *
 * Seed data covers exactly the scenario from MAIN.MD ("Initial Netherlands
 * rules"): NL letter up to 20g/50g, and a flat NL parcel rate. Belgium
 * deliberately gets no seeded rates — its zone/country mapping exists (see
 * the previous migrations) but its actual prices are left for the owner to
 * fill in via the admin "Verzendinstellingen" page, per MAIN.MD ("It is okay
 * if the exact Belgian prices are initially admin-configurable rather than
 * baked into code"). Until they do, a Belgian order simply has no matching
 * rate and checkout blocks with the standard "no shipping method available"
 * message — never a silent free/guessed price.
 */
final class CreateShippingRatesTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('shipping_rates', ['id' => true]);
        $table
            ->addColumn('shipping_zone_id', 'integer', ['signed' => false])
            ->addColumn('shipping_profile', 'string', ['limit' => 20])
            ->addColumn('min_weight_grams', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('max_weight_grams', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('enabled', 'boolean', ['default' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('shipping_zone_id', 'shipping_zones', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['shipping_zone_id', 'shipping_profile'])
            ->create();

        $now = date('Y-m-d H:i:s');
        $nlZoneId = (int) $this->fetchRow("SELECT id FROM shipping_zones WHERE code = 'nl'")['id'];

        $table->insert([
            [
                'shipping_zone_id' => $nlZoneId, 'shipping_profile' => 'letter',
                'min_weight_grams' => null, 'max_weight_grams' => 20,
                'price' => '1.40', 'enabled' => true, 'sort_order' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'shipping_zone_id' => $nlZoneId, 'shipping_profile' => 'letter',
                'min_weight_grams' => null, 'max_weight_grams' => 50,
                'price' => '2.80', 'enabled' => true, 'sort_order' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'shipping_zone_id' => $nlZoneId, 'shipping_profile' => 'parcel',
                'min_weight_grams' => null, 'max_weight_grams' => null,
                'price' => '7.45', 'enabled' => true, 'sort_order' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('shipping_rates')->drop()->save();
    }
}
