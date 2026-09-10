<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Makes a catalog product deletable without ever damaging a historical order.
 *
 * Until now `order_items.product_id` and `order_items.variant_id` both carried
 * ON DELETE RESTRICT (see 20260903120300_create_order_items_table.php and
 * 20260904161000_add_variant_to_order_items.php), which made "delete this
 * product" impossible the moment the product had been ordered once — the admin
 * product list showed "Verwijderen n.v.t." for exactly that reason. Both become
 * ON DELETE SET NULL here, so deleting a catalog product detaches the order line
 * from the (now gone) product instead of blocking, or — far worse — cascading.
 *
 * This is only safe because order_items already carries its own authoritative
 * snapshot of everything an order needs to stay readable forever:
 * `product_name`/`product_name_en` (20260907170000), `variant_label`
 * (20260904161000), `unit_price` and `quantity` (20260903120300). Those columns
 * are untouched by this migration and by the deletion itself, so an order keeps
 * showing the right title, options, quantity and historical price after the
 * product is gone. `products` is now purely a live-catalog link used for
 * decoration (the thumbnail), never for commercial data.
 *
 * Safety properties of this migration:
 *   - no order, order_item, invoice or amount is deleted or rewritten;
 *   - the only UPDATE is a backfill that *fills in missing* snapshot titles,
 *     and only where they are NULL — never overwriting a recorded value;
 *   - it is purely a constraint change otherwise, so re-running the schema
 *     against existing data cannot change what any historical order displays.
 *
 * Both columns were already nullable in the shipped schema (Phinx defaults
 * `null` to true and neither migration passed `['null' => false]`), so no
 * column definition has to change — only the two foreign keys.
 */
final class RelaxOrderItemProductForeignKeys extends AbstractMigration
{
    public function up(): void
    {
        // Guarantee every existing order line can survive losing its product
        // link *before* that becomes possible. Rows created since
        // 20260907170000 always populate these at insert time; this only
        // catches anything that predates it or was inserted by hand.
        $this->execute(
            'UPDATE order_items oi
             JOIN products p ON p.id = oi.product_id
             SET oi.product_name = p.name
             WHERE oi.product_name IS NULL'
        );

        $this->execute(
            'UPDATE order_items oi
             JOIN products p ON p.id = oi.product_id
             SET oi.product_name_en = p.name_en
             WHERE oi.product_name_en IS NULL AND p.name_en IS NOT NULL'
        );

        $table = $this->table('order_items');

        $table
            ->dropForeignKey('product_id')
            ->dropForeignKey('variant_id')
            ->update();

        $table
            ->addForeignKey('product_id', 'products', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
            ])
            ->addForeignKey('variant_id', 'product_variants', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
            ])
            ->update();
    }

    /**
     * Restores the original RESTRICT rules. Only possible while no order_item
     * has actually been detached yet — a NULL product_id cannot be turned back
     * into a valid reference, because the product it pointed at no longer
     * exists. Rolling back after a product deletion would therefore have to
     * invent data or delete order history; it refuses instead.
     */
    public function down(): void
    {
        $detached = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS c FROM order_items WHERE product_id IS NULL'
        )['c'] ?? 0);

        if ($detached > 0) {
            throw new \RuntimeException(
                'Kan deze migratie niet terugdraaien: er zijn ' . $detached . ' order_items zonder product_id '
                . '(het product is definitief verwijderd). Terugdraaien zou orderhistorie moeten weggooien of verzinnen.'
            );
        }

        $table = $this->table('order_items');

        $table
            ->dropForeignKey('product_id')
            ->dropForeignKey('variant_id')
            ->update();

        $table
            ->addForeignKey('product_id', 'products', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ])
            ->addForeignKey('variant_id', 'product_variants', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ])
            ->update();
    }
}
