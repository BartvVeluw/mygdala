<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * The migration that records the payment mode on orders (20260927160000,
 * `orders.payment_mode`) on a database built from zero and on one upgraded
 * from main before Mollie Setup 2.0 (184 migrations, 20260927100000), with a
 * shop that already took payments seeded first: a paid order with its
 * invoice and a refund, and a pending one.
 *
 *   - both end on the same `orders` table, with one new nullable column
 *     after `mollie_status`;
 *   - every existing order gets NULL, which keeps it a real sale with its
 *     real invoice (OrderRepository::isTestOrder() is only 'test');
 *   - nothing else moves: the orders' payment ids, statuses and totals, the
 *     refunds, the invoices and the invoice counter are identical after it;
 *   - running it again changes nothing, a recorded mode included.
 */
#[Group('migration-backfill')]
final class PaymentModeMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_paymode_fresh';
    private const UPGRADED = 'mygdala_scratch_paymode_upgraded';

    /** The last migration on main before Mollie Setup 2.0 (Product Gallery 2.0). */
    private const BEFORE = '20260927100000';

    private const MIGRATION = '20260927160000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $before = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $after = [];

    private static bool $columnBefore = true;

    /** @var list<array<string, mixed>> */
    private static array $ordersBeforeReplay = [];

    /** @var list<array<string, mixed>> */
    private static array $ordersAfterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::$columnBefore = self::columns(self::$upgraded, 'payment_mode') !== [];
        self::seed(self::$upgraded);
        self::$before = self::untouched(self::$upgraded);
        self::$upgraded->catchUp(self::MIGRATION);
        self::$after = self::untouched(self::$upgraded);

        self::$upgraded->pdo()->exec("UPDATE orders SET payment_mode = 'test' WHERE mollie_payment_id = 'tr_zzpaymodeopen'");
        self::$ordersBeforeReplay = self::$upgraded->rows("SELECT * FROM orders WHERE mollie_payment_id LIKE 'tr_zzpaymode%' ORDER BY id");
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$ordersAfterReplay = self::$upgraded->rows("SELECT * FROM orders WHERE mollie_payment_id LIKE 'tr_zzpaymode%' ORDER BY id");
    }

    protected function setUp(): void
    {
        if (!ScratchInstall::available()) {
            $this->markTestSkipped('A from-zero install needs the MySQL root account (DB_ROOT_PASSWORD in .env).');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$fresh?->drop();
        self::$upgraded?->drop();
        self::$fresh = null;
        self::$upgraded = null;
    }

    public function testBothInstallsEndOnTheSameOrdersTable(): void
    {
        self::assertFalse(self::$columnBefore, 'the column is new');

        self::assertSame(self::columns(self::$fresh), self::columns(self::$upgraded));
        self::assertSame(
            [['name' => 'payment_mode', 'type' => 'varchar(4)', 'nullable' => 'YES', 'default_value' => null, 'after' => 'mollie_status']],
            self::columns(self::$upgraded, 'payment_mode')
        );
    }

    public function testEveryExistingOrderHasNoModeAndNothingElseMoved(): void
    {
        self::assertCount(2, self::$before['orders']);
        self::assertSame(['tr_zzpaymodepaid', 'tr_zzpaymodeopen'], array_column(self::$before['orders'], 'mollie_payment_id'));
        self::assertCount(1, self::$before['invoices']);

        $ordersAfter = self::$after['orders'];
        self::assertSame([null, null], array_column($ordersAfter, 'payment_mode'), 'NULL: a real sale, as before');

        foreach ($ordersAfter as $index => $order) {
            unset($order['payment_mode']);
            self::assertSame(self::$before['orders'][$index], $order, 'ids, statuses, totals and the payment id as they were');
        }

        unset(self::$before['orders'], self::$after['orders']);
        self::assertSame(self::$before, self::$after, 'refunds, invoices and the counter as they were');
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertSame(['test'], array_values(array_filter(array_column(self::$ordersBeforeReplay, 'payment_mode'))));
        self::assertSame(self::$ordersBeforeReplay, self::$ordersAfterReplay);
    }

    /**
     * @return list<array<string, mixed>> every column of `orders`, or only $column, with the one before it
     */
    private static function columns(ScratchInstall $install, ?string $column = null): array
    {
        $rows = $install->rows(
            "SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS default_value, ordinal_position AS position
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'orders'
              ORDER BY ordinal_position"
        );

        $result = [];
        foreach ($rows as $index => $row) {
            $row['after'] = $index > 0 ? $rows[$index - 1]['name'] : null;
            unset($row['position']);
            if ($column === null || $row['name'] === $column) {
                $result[] = $row;
            }
        }

        return $result;
    }

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();
        $pdo->exec("INSERT INTO customers (name, email, country, created_at, updated_at) VALUES ('ZZ Paymode', 'zz-paymode@example.invalid', 'NL', '2026-09-01 10:00:00', '2026-09-01 10:00:00')");
        $customer = (int) $pdo->lastInsertId();

        $pdo->exec(
            "INSERT INTO orders (order_number, customer_id, status, mollie_payment_id, mollie_status, total, refunded_amount, shipping_cost, currency, created_at, updated_at)
             VALUES ('ORD-2026-000881', {$customer}, 'paid', 'tr_zzpaymodepaid', 'paid', '52.40', '5.00', '6.95', 'EUR', '2026-09-01 10:00:00', '2026-09-01 10:05:00')"
        );
        $paid = (int) $pdo->lastInsertId();
        $pdo->exec(
            "INSERT INTO orders (order_number, customer_id, status, mollie_payment_id, mollie_status, total, refunded_amount, shipping_cost, currency, created_at, updated_at)
             VALUES ('ORD-2026-000882', {$customer}, 'pending', 'tr_zzpaymodeopen', 'open', '12.50', '0.00', '0.00', 'EUR', '2026-09-02 10:00:00', '2026-09-02 10:00:00')"
        );

        $pdo->exec(
            "INSERT INTO order_refunds (order_id, mollie_refund_id, amount, status, description, created_at, updated_at)
             VALUES ({$paid}, 're_zzpaymode', '5.00', 'refunded', 'Deels terug', '2026-09-03 10:00:00', '2026-09-03 10:00:00')"
        );
        $pdo->exec(
            "INSERT INTO invoices (order_id, invoice_number, invoice_date, currency, seller_snapshot, pdf_path, created_at, updated_at)
             VALUES ({$paid}, 'INV2026-000881', '2026-09-01', 'EUR', '{\"name\":\"ZZ\"}', '2026/INV2026-000881.pdf', '2026-09-01 10:05:00', '2026-09-01 10:05:00')"
        );
        $pdo->exec(
            "INSERT INTO invoice_number_counters (year, last_number, updated_at) VALUES (2026, 881, '2026-09-01 10:05:00')
             ON DUPLICATE KEY UPDATE last_number = 881"
        );
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function untouched(ScratchInstall $install): array
    {
        return [
            'orders' => $install->rows("SELECT * FROM orders WHERE mollie_payment_id LIKE 'tr_zzpaymode%' ORDER BY id"),
            'order_refunds' => $install->rows("SELECT * FROM order_refunds WHERE mollie_refund_id = 're_zzpaymode' ORDER BY id"),
            'invoices' => $install->rows("SELECT * FROM invoices WHERE invoice_number = 'INV2026-000881'"),
            'invoice_number_counters' => $install->rows('SELECT * FROM invoice_number_counters ORDER BY year'),
            'customers' => $install->rows("SELECT * FROM customers WHERE email = 'zz-paymode@example.invalid'"),
        ];
    }
}
