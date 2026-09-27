<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Mollie Setup 2.0's migration (20260927140000, `secret_settings`) on a
 * database built from zero and on one upgraded from the migration before
 * it, with a shop that already took a Mollie payment seeded first:
 *
 *   - both end on the same table: a slot as primary key, the sealed value,
 *     the key id and the hint, nothing else to read a secret from;
 *   - the upgrade moves nothing: no key is created, no setting is written
 *     (an installation whose key is in .env keeps it there), and the orders,
 *     their Mollie payment ids and refunds, and site_settings are
 *     byte-identical after it;
 *   - running it again changes nothing, a stored secret included.
 */
#[Group('migration-backfill')]
final class SecretSettingsMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_secrets_fresh';
    private const UPGRADED = 'mygdala_scratch_secrets_upgraded';

    /** The last migration before Mollie Setup 2.0 (Product Gallery 2.0). */
    private const BEFORE = '20260927100000';

    private const MIGRATION = '20260927140000';

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $upgraded = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $before = [];

    /** @var array<string, list<array<string, mixed>>> */
    private static array $after = [];

    private static bool $tableBefore = true;

    /** @var list<array<string, mixed>> */
    private static array $secretsBeforeReplay = [];

    /** @var list<array<string, mixed>> */
    private static array $secretsAfterReplay = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::upTo(self::FRESH, self::MIGRATION);

        self::$upgraded = ScratchInstall::upTo(self::UPGRADED, self::BEFORE);
        self::$tableBefore = self::$upgraded->rows("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'secret_settings'")[0]['n'] > 0;
        self::seed(self::$upgraded);
        self::$before = self::untouched(self::$upgraded);
        self::$upgraded->catchUp(self::MIGRATION);
        self::$after = self::untouched(self::$upgraded);

        self::$upgraded->pdo()->exec(
            "INSERT INTO secret_settings (slot, ciphertext, key_id, hint, created_at, updated_at)
             VALUES ('shop.mollie.test_api_key', 'v1:c2VhbGVk', '0123456789abcdef', 'test_••••••••abcd', NOW(), NOW())"
        );
        self::$secretsBeforeReplay = self::$upgraded->rows('SELECT * FROM secret_settings');
        self::$upgraded->replay(self::MIGRATION, self::MIGRATION);
        self::$secretsAfterReplay = self::$upgraded->rows('SELECT * FROM secret_settings');
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

    public function testBothInstallsEndOnTheSameSecretTable(): void
    {
        self::assertFalse(self::$tableBefore, 'the table is new');

        foreach ([self::$fresh, self::$upgraded] as $install) {
            self::assertSame(
                [
                    ['name' => 'slot', 'type' => 'varchar(64)', 'nullable' => 'NO', 'key' => 'PRI'],
                    ['name' => 'ciphertext', 'type' => 'text', 'nullable' => 'NO', 'key' => ''],
                    ['name' => 'key_id', 'type' => 'varchar(16)', 'nullable' => 'NO', 'key' => ''],
                    ['name' => 'hint', 'type' => 'varchar(32)', 'nullable' => 'NO', 'key' => ''],
                    ['name' => 'created_at', 'type' => 'datetime', 'nullable' => 'YES', 'key' => ''],
                    ['name' => 'updated_at', 'type' => 'datetime', 'nullable' => 'YES', 'key' => ''],
                ],
                $install->rows(
                    "SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_key AS `key` FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = 'secret_settings' ORDER BY ordinal_position"
                )
            );
        }

        self::assertSame([], self::$fresh->rows('SELECT slot FROM secret_settings'), 'no secret and no key is made by installing');
    }

    public function testTheUpgradeMovesNothingAndLeavesEveryPaymentAsItWas(): void
    {
        self::assertCount(1, self::$before['orders']);
        self::assertSame('tr_zzsecrets', self::$before['orders'][0]['mollie_payment_id']);
        self::assertCount(1, self::$before['order_refunds']);
        self::assertSame(self::$before, self::$after);
        self::assertSame([], self::$upgraded->rows("SELECT setting_key FROM site_settings WHERE setting_key LIKE 'shop_payment%'"), 'no mode or methods row: the defaults answer');
    }

    public function testASecondRunChangesNothing(): void
    {
        self::assertCount(1, self::$secretsBeforeReplay);
        self::assertSame(self::$secretsBeforeReplay, self::$secretsAfterReplay);
    }

    private static function seed(ScratchInstall $install): void
    {
        $pdo = $install->pdo();
        $pdo->exec("INSERT INTO customers (name, email, country, created_at, updated_at) VALUES ('ZZ Secrets', 'zz-secrets@example.invalid', 'NL', '2026-09-01 10:00:00', '2026-09-01 10:00:00')");
        $customer = (int) $pdo->lastInsertId();
        $pdo->exec(
            "INSERT INTO orders (order_number, customer_id, status, mollie_payment_id, mollie_status, total, refunded_amount, shipping_cost, currency, created_at, updated_at)
             VALUES ('ORD-2026-000777', {$customer}, 'paid', 'tr_zzsecrets', 'paid', '25.00', '5.00', '0.00', 'EUR', '2026-09-01 10:00:00', '2026-09-01 10:05:00')"
        );
        $order = (int) $pdo->lastInsertId();
        $pdo->exec(
            "INSERT INTO order_refunds (order_id, mollie_refund_id, amount, status, description, created_at, updated_at)
             VALUES ({$order}, 're_zzsecrets', '5.00', 'refunded', 'Deels terug', '2026-09-02 10:00:00', '2026-09-02 10:00:00')"
        );
        $pdo->exec("INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES ('zz_secrets_probe', 'unchanged', '2026-09-01 10:00:00', '2026-09-01 10:00:00')");
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function untouched(ScratchInstall $install): array
    {
        return [
            'orders' => $install->rows("SELECT * FROM orders WHERE mollie_payment_id = 'tr_zzsecrets' ORDER BY id"),
            'order_refunds' => $install->rows("SELECT * FROM order_refunds WHERE mollie_refund_id = 're_zzsecrets' ORDER BY id"),
            'site_settings' => $install->rows('SELECT * FROM site_settings ORDER BY setting_key'),
            'customers' => $install->rows("SELECT * FROM customers WHERE email = 'zz-secrets@example.invalid'"),
        ];
    }
}
