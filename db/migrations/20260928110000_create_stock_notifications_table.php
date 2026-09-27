<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Shop Product & Ordering 2.0, terug op voorraad: one row per request "mail
 * me when this is available again" (App\Repository\StockNotificationRepository,
 * App\Service\Inventory\StockNotifications, MODULES.md "Terug op voorraad").
 *
 *   product_id      the product (a notification goes with it when it is deleted)
 *   variant_id      the exact variant asked about, or NULL for a product
 *                   without variants (goes with the variant)
 *   unit_key        'p<product id>' or 'v<variant id>': the sellable unit,
 *                   App\Service\Inventory\StockUnit::key()
 *   email           the address to write to, lower-cased; nothing else about
 *                   the visitor is kept, and no account is needed
 *   language_code   the website language the visitor asked in: the mail is
 *                   written in it
 *   status          'active' until the mail went out, then 'sent'
 *   active_marker   1 while active, NULL once sent: with the unique key below
 *                   it allows ONE active request per unit and address, and
 *                   any number of sent ones (NULLs never collide)
 *   attempts, last_error_at   a mail that failed stays active, for a retry
 *   claimed_at      set while one sender is writing this mail, so two
 *                   senders at once never write it twice
 *   notified_at     when the mail went out
 *
 * A NEW TABLE, nothing else. No existing row is touched, no fresh-install
 * guard (a new installation and an upgraded one end on the same schema) and
 * idempotent: the table is checked first.
 */
final class CreateStockNotificationsTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('stock_notifications')) {
            return;
        }

        $this->table('stock_notifications', ['id' => true])
            ->addColumn('product_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('variant_id', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('unit_key', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('email', 'string', ['limit' => 254, 'null' => false])
            ->addColumn('language_code', 'string', [
                'limit' => 12,
                'null' => false,
                'encoding' => 'ascii',
                'collation' => 'ascii_bin',
                'comment' => 'site_languages.code at the time of asking; no foreign key, so a language can still be removed (the mail then uses the default)',
            ])
            ->addColumn('status', 'string', ['limit' => 10, 'null' => false, 'default' => 'active'])
            ->addColumn('active_marker', 'boolean', ['null' => true, 'default' => true])
            ->addColumn('attempts', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('last_error_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('claimed_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('notified_at', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['unit_key', 'email', 'active_marker'], ['unique' => true, 'name' => 'uq_stock_notifications_active'])
            ->addIndex(['unit_key', 'status'], ['name' => 'idx_stock_notifications_unit_status'])
            ->addForeignKey('product_id', 'products', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION', 'constraint' => 'fk_stock_notifications_product'])
            ->addForeignKey('variant_id', 'product_variants', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION', 'constraint' => 'fk_stock_notifications_variant'])
            ->create();
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}
