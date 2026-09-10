<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Central, carrier-managed shipping prices (App\Repository\CarrierRateRepository,
 * App\Service\Shipping\PostNl\*) — separates "how much does PostNL currently
 * charge for X" from the existing zone/weight/profile shipping RULES in
 * `shipping_rates` (see 20260906130000_create_shipping_rates_table.php). A
 * `shipping_rates` row optionally links to one row here via the new
 * `carrier_rate_id` column added in the next migration; when linked, the
 * live price here is what's charged, never a value hand-typed onto the rule
 * itself. See MAIN.MD "Centraal beheerde PostNL-tarieven".
 *
 * One row = one stable, human-chosen `rate_code` (e.g. `postnl_nl_letter_20g`)
 * that the PostNL sync (or an admin) can update the price of, independently
 * of which shipping rule(s) reference it.
 *
 * `mode` ('automatic'|'manual') is the manual-override mechanism required by
 * MAIN.MD: 'automatic' rows are freely overwritten by a successful sync;
 * 'manual' rows are never overwritten by sync (the admin's typed price always
 * wins) — sync still records what it *saw* in `pending_price`/
 * `pending_detected_at` so the admin can see PostNL's current published price
 * without it silently taking effect. The same pending_* pair is reused when
 * an *automatic* row's sync-detected price looks implausible (see
 * `needs_review`) — the sync never applies a price it doesn't trust, it only
 * ever proposes one for a human to accept via the admin UI.
 *
 * `last_checked_at` (every sync attempt that found this code, regardless of
 * outcome) is deliberately separate from `updated_at` (only touched when
 * `price` itself actually changes, manually or automatically) — the admin UI
 * needs both independently ("last updated" vs. "last checked").
 */
final class CreateCarrierRatesTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('carrier_rates', ['id' => true]);
        $table
            ->addColumn('provider', 'string', ['limit' => 30])
            ->addColumn('rate_code', 'string', ['limit' => 60])
            ->addColumn('label', 'string', ['limit' => 150])
            ->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('currency', 'string', ['limit' => 3, 'default' => 'EUR'])
            ->addColumn('mode', 'string', ['limit' => 10, 'default' => 'automatic'])
            ->addColumn('pending_price', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => true])
            ->addColumn('pending_detected_at', 'datetime', ['null' => true])
            ->addColumn('needs_review', 'boolean', ['default' => false])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('last_checked_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['rate_code'], ['unique' => true])
            ->create();

        $now = date('Y-m-d H:i:s');

        // Seed with exactly today's live NL prices (see the seeded rows in
        // 20260906130000_create_shipping_rates_table.php) — the follow-up
        // migration links the existing shipping_rates rows to these, so the
        // computed checkout price is byte-for-byte unchanged by this change.
        // Only rate codes an actual shipping rule needs today are created —
        // no speculative letterbox/350g/Belgium codes without a rule to use
        // them (see MAIN.MD, "Open punt": Belgian prices are still admin-only).
        $table->insert([
            [
                'provider' => 'postnl', 'rate_code' => 'postnl_nl_letter_20g',
                'label' => 'PostNL Nederland briefpost t/m 20 g', 'price' => '1.40', 'currency' => 'EUR',
                'mode' => 'automatic', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'provider' => 'postnl', 'rate_code' => 'postnl_nl_letter_50g',
                'label' => 'PostNL Nederland briefpost t/m 50 g', 'price' => '2.80', 'currency' => 'EUR',
                'mode' => 'automatic', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'provider' => 'postnl', 'rate_code' => 'postnl_nl_parcel',
                'label' => 'PostNL Nederland pakket', 'price' => '7.45', 'currency' => 'EUR',
                'mode' => 'automatic', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('carrier_rates')->drop()->save();
    }
}
