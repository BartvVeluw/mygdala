<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Gives every order its public order number as a stored fact:
 * `orders.order_number`, written once and never derived again.
 *
 * WHY. Until now the number was rebuilt from the order's id, its creation
 * year and the CURRENT `order_number_prefix` every time something showed it.
 * Once that prefix became a setting (20260913100000 and the settings screen),
 * an owner who changed it renamed every existing order in the admin, the
 * export, a resent e-mail and a regenerated invoice, while the customer's
 * inbox, their bank statement and Mollie kept the number they were actually
 * given. From here on App\Repository\OrderRepository::create() writes the
 * number inside the transaction that creates the order, and every reader
 * takes the stored value.
 *
 * SCHEMA, UNGUARDED. A nullable VARCHAR(32) with a UNIQUE index, on every
 * kind of installation: a fresh-install guard skips data, never schema
 * (db/migrations/CLAUDE.md). Nullable because an order's id only exists after
 * its INSERT; create() fills the column before its transaction commits. 32
 * holds the longest number the format makes: a 10-character prefix, the
 * year, a 10-digit id and two separators.
 *
 * BACKFILL: THE NUMBER EACH ORDER WAS SHOWN. Every existing order gets the
 * string the code produced for it right before this migration:
 *
 *   prefix  the stored `order_number_prefix`, letters and digits only, or
 *           "ORD" when there is none or nothing usable is left in it;
 *   year    the year of `created_at`;
 *   number  the order id, zero-padded to six digits and never cut shorter.
 *
 * Why the stored prefix is the historical one:
 *
 *   - Every installation that issued numbers before the prefix became a
 *     setting issued "VLD-", which was hardcoded, and 20260913100000 pinned
 *     "VLD" for exactly those installations. Phinx runs pending migrations in
 *     ascending version order within one run, so that pin has always run by
 *     the time this does. Tests\Install\OrderNumberSnapshotMigrationTest
 *     proves it on the outcome.
 *   - An installation built from zero took its first orders under the
 *     generic default and stores no prefix, so it gets "ORD".
 *   - An owner who chose a prefix before their first order issued every
 *     number with it.
 *
 * The one history this cannot tell apart is an owner who changed the prefix
 * AFTER orders existed but before this migration ran: the orders from before
 * that change were issued under the old prefix, and are stored under the new
 * one — the number every derived screen was already showing them. That needs
 * the settings screen without this migration, and the two ship together.
 *
 * The format is written out here instead of asked of OrderRepository on
 * purpose: a migration records what the code of its date produced, and must
 * keep producing that even when the formatter changes later.
 *
 * AN ORDER WITHOUT A CREATION MOMENT STOPS THE MIGRATION. `created_at` is
 * nullable in the schema, and an order without one has no year this
 * migration can know: the code rebuilt it with the current year, a different
 * number every January. Rather than invent a number, the migration refuses
 * before it changes anything, the schema included, and names the orders, so
 * that a deliberate data decision can be made first. The application itself
 * always writes `created_at`.
 *
 * Forward-only. Idempotent: the column and the index are only added when
 * missing, and only rows whose `order_number` is still NULL are written, so
 * a second run changes nothing, not even after the setting has changed.
 */
final class SnapshotTheOrderNumberOnEveryOrder extends AbstractMigration
{
    /** App\Service\SiteSettings' generic default on the date this was written. */
    private const GENERIC_PREFIX = 'ORD';

    /** How many order ids the refusal names before it stops listing them. */
    private const IDS_NAMED = 50;

    public function up(): void
    {
        if (!$this->hasTable('orders')) {
            return;
        }

        $hasColumn = $this->table('orders')->hasColumn('order_number');

        $this->refuseOrdersWithoutACreationMoment($hasColumn);

        if (!$hasColumn) {
            $this->table('orders')
                ->addColumn('order_number', 'string', ['limit' => 32, 'null' => true, 'after' => 'id'])
                ->update();
        }

        if (!$this->table('orders')->hasIndex('order_number')) {
            $this->table('orders')
                ->addIndex(['order_number'], ['unique' => true])
                ->update();
        }

        $prefix = $this->historicalPrefix();

        foreach ($this->fetchAll('SELECT id, created_at FROM orders WHERE order_number IS NULL ORDER BY id') as $order) {
            $this->execute(
                'UPDATE orders SET order_number = ? WHERE id = ? AND order_number IS NULL',
                [self::orderNumber($prefix, (int) $order['id'], (string) $order['created_at']), (int) $order['id']]
            );
        }
    }

    /**
     * Nothing to undo: dropping the column would put every order back on a
     * number rebuilt from whatever the prefix setting says that day.
     */
    public function down(): void
    {
    }

    /**
     * Stops before the first change when an order that still needs a number
     * has no `created_at`. Only those rows count: one that already carries a
     * number, given to it by a deliberate decision, is left alone.
     */
    private function refuseOrdersWithoutACreationMoment(bool $hasColumn): void
    {
        $rows = $this->fetchAll(
            'SELECT id FROM orders WHERE created_at IS NULL'
            . ($hasColumn ? ' AND order_number IS NULL' : '')
            . ' ORDER BY id'
        );

        if ($rows === []) {
            return;
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $named = array_slice($ids, 0, self::IDS_NAMED);

        throw new \RuntimeException(sprintf(
            'Stopped before changing anything: %d order(s) have no created_at, so the year of the order number '
            . 'they were issued with cannot be known, and no number is invented for them. Order ids: %s%s. '
            . 'Decide what these orders are numbered, record it (a created_at, or an order_number once that '
            . 'column exists), and run the migrations again.',
            count($ids),
            implode(', ', $named),
            count($ids) > count($named) ? ', ...' : ''
        ));
    }

    /**
     * The prefix every existing order was shown under: the stored setting,
     * cleaned exactly as OrderRepository cleaned it on this date.
     */
    private function historicalPrefix(): string
    {
        if (!$this->hasTable('site_settings')) {
            return self::GENERIC_PREFIX;
        }

        $row = $this->fetchRow("SELECT setting_value FROM site_settings WHERE setting_key = 'order_number_prefix'");
        $clean = is_array($row)
            ? (string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($row['setting_value'] ?? ''))
            : '';

        return $clean !== '' ? $clean : self::GENERIC_PREFIX;
    }

    /**
     * The shape OrderRepository::formatOrderNumber() had on this date. In PHP
     * rather than SQL, because LPAD() would cut a seven-digit id down to six.
     */
    private static function orderNumber(string $prefix, int $orderId, string $createdAt): string
    {
        return $prefix
            . '-' . (new \DateTimeImmutable($createdAt))->format('Y')
            . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
    }
}
