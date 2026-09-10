<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Collapses the existing `orders.fulfilment_status`
 * (db/migrations/20260903200000_add_fulfilment_status_to_orders.php) from a
 * four-state shipping-flavoured workflow (Nieuw / In behandeling / Verzonden
 * / Afgerond) onto the two states the owner actually administers with:
 * "Open" (still needs my attention) and "Afgehandeld" (done). The extra
 * intermediate states were never used — every order in the database is still
 * on the default — and they imply a shipment-tracking workflow this project
 * deliberately does not have (see MAIN.MD "Afhandelingsstatus").
 *
 * Reuses the existing column rather than adding a second status field: this
 * is the same concept, only with fewer values, so there is exactly one
 * admin-side status per order. It stays strictly separate from the
 * Mollie-driven `status`/`mollie_status` columns, which this migration does
 * not touch.
 *
 * `handled_at` records when the order was marked "Afgehandeld", and is
 * cleared again when it is reopened — App\Repository\OrderRepository's
 * markHandled()/markOpen() always write both columns together. It is
 * informational only: `fulfilment_status` alone remains the source of truth
 * for the state, so an order migrated below into "Afgehandeld" (which has no
 * historical timestamp to recover — nothing ever recorded one) is a
 * perfectly valid row with a null `handled_at`, not an inconsistency.
 *
 * Value mapping, so no existing row loses meaning:
 *   Nieuw, In behandeling -> Open        (still on the owner's to-do list)
 *   Verzonden, Afgerond   -> Afgehandeld (the owner had already dealt with it)
 * Anything unrecognised falls back to "Open", the safe direction: it can only
 * put an order back on the working list, never falsely claim one is done.
 */
final class SimplifyFulfilmentStatusToOpenHandled extends AbstractMigration
{
    public function up(): void
    {
        $this->table('orders')
            ->addColumn('handled_at', 'datetime', ['null' => true, 'after' => 'fulfilment_status'])
            // Nullability is kept exactly as it was; only the default changes.
            ->changeColumn('fulfilment_status', 'string', ['limit' => 20, 'null' => true, 'default' => 'Open'])
            ->update();

        $this->execute(
            "UPDATE orders SET fulfilment_status = 'Afgehandeld'
             WHERE fulfilment_status IN ('Verzonden', 'Afgerond')"
        );
        $this->execute(
            "UPDATE orders SET fulfilment_status = 'Open'
             WHERE fulfilment_status IS NULL OR fulfilment_status <> 'Afgehandeld'"
        );
    }

    public function down(): void
    {
        // Reverses the mapping above onto the closest old value. "Open" maps
        // back to "Nieuw" (the old default) rather than trying to recover the
        // distinction with "In behandeling", which this column no longer
        // stores anywhere.
        $this->execute("UPDATE orders SET fulfilment_status = 'Afgerond' WHERE fulfilment_status = 'Afgehandeld'");
        $this->execute("UPDATE orders SET fulfilment_status = 'Nieuw' WHERE fulfilment_status = 'Open'");

        $this->table('orders')
            ->removeColumn('handled_at')
            ->changeColumn('fulfilment_status', 'string', ['limit' => 20, 'null' => true, 'default' => 'Nieuw'])
            ->update();
    }
}
