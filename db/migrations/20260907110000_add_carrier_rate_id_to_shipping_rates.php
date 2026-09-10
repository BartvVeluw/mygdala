<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Links `shipping_rates` (the existing zone/weight/profile rules) to the new
 * `carrier_rates` table (see previous migration) via an optional
 * `carrier_rate_id`. This is the one integration point between the two
 * concepts — see App\Repository\ShippingRateRepository, which now resolves
 * the effective price as `carrier_rates.price` when this column is set, or
 * the existing `shipping_rates.price` column when it's null.
 *
 * Deliberately nullable, ON DELETE SET NULL: a shipping rule with no linked
 * carrier rate keeps behaving exactly as before this change (its own `price`
 * column is authoritative) — this is the "fixed/manual price" option required
 * by MAIN.MD, not a fallback for a broken link. Backfill below links the
 * three existing NL rows to the three carrier rates seeded with the exact
 * same price, so this migration cannot change what a checkout calculates.
 * Belgium is left unlinked (it already has no rates at all — see
 * 20260906130000_create_shipping_rates_table.php).
 */
final class AddCarrierRateIdToShippingRates extends AbstractMigration
{
    public function up(): void
    {
        $this->table('shipping_rates')
            ->addColumn('carrier_rate_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'shipping_profile'])
            ->addForeignKey('carrier_rate_id', 'carrier_rates', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
            ])
            ->addIndex(['carrier_rate_id'])
            ->update();

        // Backfill: link each existing NL shipping_rates row to the carrier
        // rate with the matching rate_code, only when there is exactly one
        // NL row for that weight bracket/profile — see class docblock. If the
        // owner ever hand-edited these seeded rows before this migration runs
        // (e.g. changed the 20g price), the match is still made on
        // zone+profile+max_weight_grams, not on price, so it links correctly
        // regardless; only the *value* differs, which is expected (the admin
        // UI shows both, see "Verzendinstellingen" changes).
        $nlZoneId = $this->fetchRow("SELECT id FROM shipping_zones WHERE code = 'nl'")['id'] ?? null;
        if ($nlZoneId === null) {
            return;
        }

        $links = [
            ['shipping_profile' => 'letter', 'max_weight_grams' => 20, 'rate_code' => 'postnl_nl_letter_20g'],
            ['shipping_profile' => 'letter', 'max_weight_grams' => 50, 'rate_code' => 'postnl_nl_letter_50g'],
            ['shipping_profile' => 'parcel', 'max_weight_grams' => null, 'rate_code' => 'postnl_nl_parcel'],
        ];

        foreach ($links as $link) {
            $weightCondition = $link['max_weight_grams'] === null
                ? 'max_weight_grams IS NULL'
                : 'max_weight_grams = ' . (int) $link['max_weight_grams'];

            // $link's values are fixed literals from the array above (never
            // external input), so plain interpolation here is safe.
            $matches = $this->fetchAll(
                "SELECT id FROM shipping_rates
                 WHERE shipping_zone_id = " . (int) $nlZoneId . "
                   AND shipping_profile = '" . $link['shipping_profile'] . "'
                   AND {$weightCondition}"
            );

            if (count($matches) !== 1) {
                // Ambiguous (owner already added a second matching bracket) or
                // missing — leave unlinked rather than guessing.
                continue;
            }

            $carrierRate = $this->fetchRow(
                "SELECT id FROM carrier_rates WHERE rate_code = '" . $link['rate_code'] . "'"
            );
            if ($carrierRate === false) {
                continue;
            }

            $this->execute(
                'UPDATE shipping_rates SET carrier_rate_id = ' . (int) $carrierRate['id'] . '
                 WHERE id = ' . (int) $matches[0]['id']
            );
        }
    }

    public function down(): void
    {
        $this->table('shipping_rates')
            ->dropForeignKey('carrier_rate_id')
            ->removeColumn('carrier_rate_id')
            ->update();
    }
}
