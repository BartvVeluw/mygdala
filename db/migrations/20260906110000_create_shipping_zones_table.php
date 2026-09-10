<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Shipping zones (App\Repository\ShippingZoneRepository) — a named group of
 * destination countries with its own shipping rates. `code` is the stable
 * identifier used internally (e.g. rate lookups, App\Service\Shipping\
 * ShippingCalculationService) — never the raw country code, so checkout
 * logic never hardcodes "NL"/"BE" (see MAIN.MD, "Shipping zones").
 *
 * Only Netherlands + Belgium are seeded for now (the two zones this project
 * currently needs) — adding a new country/zone later is a data change only
 * (a new zone row + shipping_zone_countries row + rates), no checkout code
 * changes.
 */
final class CreateShippingZonesTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('shipping_zones', ['id' => true]);
        $table
            ->addColumn('code', 'string', ['limit' => 20])
            ->addColumn('name', 'string', ['limit' => 100])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['code'], ['unique' => true])
            ->create();

        $now = date('Y-m-d H:i:s');
        $table->insert([
            ['code' => 'nl', 'name' => 'Nederland', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'be', 'name' => 'België', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('shipping_zones')->drop()->save();
    }
}
