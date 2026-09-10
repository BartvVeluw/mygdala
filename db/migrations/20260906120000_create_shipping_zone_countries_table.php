<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Countries belonging to a shipping zone (App\Repository\ShippingZoneRepository
 * ::findZoneForCountry()). A country belongs to exactly one zone — enforced by
 * the unique index on country_code — so zone lookup by destination country is
 * always unambiguous. See the previous migration for why zones/countries are
 * data, not hardcoded checkout logic.
 */
final class CreateShippingZoneCountriesTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('shipping_zone_countries', ['id' => true]);
        $table
            ->addColumn('shipping_zone_id', 'integer', ['signed' => false])
            ->addColumn('country_code', 'string', ['limit' => 2])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addForeignKey('shipping_zone_id', 'shipping_zones', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['country_code'], ['unique' => true])
            ->create();

        $now = date('Y-m-d H:i:s');
        $zoneIdsByCode = [];
        foreach ($this->fetchAll('SELECT id, code FROM shipping_zones') as $row) {
            $zoneIdsByCode[$row['code']] = (int) $row['id'];
        }

        $table->insert([
            ['shipping_zone_id' => $zoneIdsByCode['nl'], 'country_code' => 'NL', 'created_at' => $now],
            ['shipping_zone_id' => $zoneIdsByCode['be'], 'country_code' => 'BE', 'created_at' => $now],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('shipping_zone_countries')->drop()->save();
    }
}
