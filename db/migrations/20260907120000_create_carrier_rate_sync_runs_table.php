<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Audit trail for carrier-rate sync attempts (App\Repository\
 * CarrierRateSyncRunRepository, App\Service\Shipping\PostNl\PostNlRateSyncService)
 * — one row per run, whether triggered by the daily/weekly cron
 * (scripts/sync-postnl-rates.php) or the admin "PostNL-tarieven nu bijwerken"
 * button. Purely observational: nothing here is read by the shipping
 * calculator, only by the admin "Carrier-tarieven" screen (last run
 * status/details) — see MAIN.MD.
 *
 * `details_json` holds the structured per-rate-code outcome (changed /
 * unchanged / pending-manual / flagged-for-review / missing) so the admin UI
 * can render the same "Changed / Unchanged / Warnings" breakdown right after
 * a manual run without re-running the sync.
 */
final class CreateCarrierRateSyncRunsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('carrier_rate_sync_runs', ['id' => true])
            ->addColumn('provider', 'string', ['limit' => 30])
            ->addColumn('status', 'string', ['limit' => 20])
            ->addColumn('message', 'text', ['null' => true])
            ->addColumn('details_json', 'text', ['null' => true])
            ->addColumn('triggered_by', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('ran_at', 'datetime')
            ->addIndex(['provider', 'ran_at'])
            ->create();
    }

    public function down(): void
    {
        $this->table('carrier_rate_sync_runs')->drop()->save();
    }
}
