<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Install\InstallState;
use App\Repository\OrderRepository;
use App\Service\SiteSettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * What 20260913120000_snapshot_the_order_number_on_every_order stores on the
 * orders that already exist, and when it refuses to.
 *
 * Every installation here is built by the migrations themselves and stands
 * where a real one stood before this migration existed. Its orders are put
 * in with plain SQL, the way the code of that time wrote them, and then the
 * remaining migrations run through Phinx:
 *
 *   ordering        the prefix pin runs before the snapshot in one run, so
 *                   the snapshot reads the pinned prefix
 *   legacy          VLD numbers stored exactly as issued; a replay, and a
 *                   prefix changed afterwards, change none of them
 *   fresh, orders   built from zero, took orders before the pin: VLD
 *   generic         orders taken under the generic default: ORD
 *   owner's prefix  a prefix chosen before the first order: that prefix
 *   no orders       the column and its unique index, nothing to backfill
 *   no created_at   the migration stops, names the orders, changes nothing
 *
 * The prefix pin itself is covered by Tests\Install\OrderNumberPrefixPinTest.
 */
#[Group('migration-backfill')]
final class OrderNumberSnapshotMigrationTest extends TestCase
{
    /** The migration that writes the install marker. */
    private const FIRST_MIGRATION = '20260903120000';

    /** The last migration before the prefix pin: where a test puts its older orders. */
    private const BEFORE_THE_PIN = '20260912100000';

    private const THE_PIN = '20260913100000';

    private const THE_SNAPSHOT = '20260913120000';

    private ?ScratchInstall $install = null;

    protected function setUp(): void
    {
        if (!ScratchInstall::available()) {
            $this->markTestSkipped(
                'A from-zero install needs the MySQL root account (DB_ROOT_PASSWORD in .env), like scripts/test-db.php.'
            );
        }
    }

    protected function tearDown(): void
    {
        $this->install?->drop();
        $this->install = null;
    }

    /**
     * The snapshot is only as right as the prefix it reads, and for an
     * installation that issued "VLD-" numbers that prefix is written by the
     * pin. Proven on the outcome rather than on the file names: the database
     * has neither migration, one Phinx run applies both, and without the pin
     * there would be no stored prefix and the number would have come out
     * "ORD-".
     */
    public function testThePrefixPinRunsBeforeTheSnapshotInOneMigrationRun(): void
    {
        $this->assertLessThan((int) self::THE_SNAPSHOT, (int) self::THE_PIN, 'Phinx orders pending migrations by version.');

        $this->install = $this->legacyUpTo('mygdala_scratch_order_number_order', self::BEFORE_THE_PIN);
        $this->insertOrder(127, '2026-03-14 10:30:00');

        $this->assertNull($this->storedPrefix(), 'precondition: nothing is pinned yet');
        $this->assertFalse($this->hasOrderNumberColumn(), 'precondition: there is no column yet');

        $this->install->catchUp();

        $this->assertSame([self::THE_PIN, self::THE_SNAPSHOT], $this->applied([self::THE_PIN, self::THE_SNAPSHOT]));
        $this->assertSame('VLD', $this->storedPrefix());
        $this->assertSame([127 => 'VLD-2026-000127'], $this->orderNumbers());
    }

    public function testALegacyUpgradeStoresEveryHistoricalNumberExactlyAsItWasIssued(): void
    {
        $this->install = $this->legacyUpTo('mygdala_scratch_order_number_legacy', self::BEFORE_THE_PIN);
        $this->insertOrder(127, '2026-03-14 10:30:00');
        $this->insertOrder(1234567, '2027-05-01 08:00:00');
        // The last second of a year still belongs to that year.
        $this->insertOrder(1234568, '2025-12-31 23:59:59');

        $this->install->catchUp();

        $issued = [
            127 => 'VLD-2026-000127',
            1234567 => 'VLD-2027-1234567',
            1234568 => 'VLD-2025-1234568',
        ];
        $this->assertSame($issued, $this->orderNumbers());

        $this->install->replay(self::THE_SNAPSHOT);
        $this->assertSame($issued, $this->orderNumbers(), 'A second run must change nothing.');

        $this->install->pdo()->exec(
            "UPDATE site_settings SET setting_value = 'SHOP' WHERE setting_key = 'order_number_prefix'"
        );
        $this->install->replay(self::THE_SNAPSHOT);
        $this->assertSame($issued, $this->orderNumbers(), 'A prefix changed afterwards must not reach an existing order.');

        $this->assertSame(
            [127 => '2026-03-14 10:30:00', 1234567 => '2027-05-01 08:00:00', 1234568 => '2025-12-31 23:59:59'],
            $this->creationMoments(),
            'The backfill writes the number and nothing else.'
        );
    }

    public function testAFreshInstallThatTookOrdersBeforeThePinStoresItsVldNumbers(): void
    {
        $this->install = ScratchInstall::upTo('mygdala_scratch_order_number_fresh_vld', self::BEFORE_THE_PIN);
        $this->assertSame(InstallState::KIND_FRESH, InstallState::kind($this->install->pdo()), 'The case is a FRESH install.');

        $this->insertOrder(3, '2026-09-01 12:00:00');
        $this->install->catchUp();

        $this->assertSame([3 => 'VLD-2026-000003'], $this->orderNumbers());
    }

    public function testOrdersTakenUnderTheGenericDefaultStoreOrdNumbers(): void
    {
        // Built from zero with the pin already behind it: there were no orders
        // when it ran, so nothing was pinned and the generic default applied.
        $this->install = ScratchInstall::upTo('mygdala_scratch_order_number_generic', self::THE_PIN);
        $this->assertNull($this->storedPrefix(), 'precondition: the pin left this installation alone');

        $this->insertOrder(1, '2026-09-13 09:00:00');
        $this->install->catchUp();

        $this->assertSame([1 => 'ORD-2026-000001'], $this->orderNumbers());
    }

    /**
     * Stored with a separator in it on purpose: the number was shown with
     * the prefix cleaned to its letters and digits, so that is what is stored.
     */
    public function testAPrefixTheOwnerChoseBeforeTheFirstOrderIsTheOneStored(): void
    {
        $this->install = ScratchInstall::upTo('mygdala_scratch_order_number_owner', self::THE_PIN);
        $this->install->pdo()->exec(
            "INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at)
             VALUES ('order_number_prefix', 'SHOP-', NOW(), NOW())"
        );

        $this->insertOrder(1, '2026-09-13 09:00:00');
        $this->install->catchUp();

        $this->assertSame([1 => 'SHOP-2026-000001'], $this->orderNumbers());
    }

    public function testADatabaseWithoutOrdersGetsTheColumnAndNothingToBackfill(): void
    {
        $this->install = ScratchInstall::fresh('mygdala_scratch_order_number_empty');

        $this->assertSame(
            [['nullable' => 'YES', 'type' => 'varchar', 'length' => 32]],
            array_map(
                static fn (array $row): array => ['nullable' => (string) $row['nullable'], 'type' => (string) $row['type'], 'length' => (int) $row['length']],
                $this->install->rows(
                    "SELECT is_nullable AS nullable, data_type AS type, character_maximum_length AS length
                     FROM information_schema.columns
                     WHERE table_schema = ? AND table_name = 'orders' AND column_name = 'order_number'",
                    [$this->install->database]
                )
            )
        );

        $this->assertSame(
            ['0'],
            array_map(
                static fn (array $row): string => (string) $row['non_unique'],
                $this->install->rows(
                    "SELECT non_unique AS non_unique FROM information_schema.statistics
                     WHERE table_schema = ? AND table_name = 'orders' AND column_name = 'order_number'",
                    [$this->install->database]
                )
            ),
            'One index on order_number, and it is unique.'
        );

        $this->assertSame(0, $this->install->count('orders'));
    }

    /**
     * The code gave an order without `created_at` the CURRENT year, a
     * different number every January, so there is no truthful number to
     * store. The migration refuses before it touches anything, names the
     * orders, and completes once someone has decided.
     */
    public function testAnOrderWithoutACreationMomentStopsTheMigrationBeforeItChangesAnything(): void
    {
        $this->install = $this->legacyUpTo('mygdala_scratch_order_number_no_created_at', self::BEFORE_THE_PIN);
        $this->insertOrder(10, '2026-03-14 10:30:00');
        $this->insertOrder(11, null);
        $this->insertOrder(12, null);

        try {
            $this->install->catchUp();
            $this->fail('The migration must refuse orders it cannot give a truthful number.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('2 order(s) have no created_at', $e->getMessage());
            $this->assertStringContainsString('Order ids: 11, 12.', $e->getMessage());
        }

        $this->assertFalse($this->hasOrderNumberColumn(), 'Nothing may change, the schema included.');
        $this->assertSame(
            [10 => '2026-03-14 10:30:00', 11 => null, 12 => null],
            $this->creationMoments(),
            'No creation moment is invented either.'
        );
        $this->assertSame([self::THE_PIN], $this->applied([self::THE_PIN, self::THE_SNAPSHOT]), 'Only the pin before it ran.');

        $this->install->pdo()->exec("UPDATE orders SET created_at = '2026-02-01 09:00:00' WHERE id IN (11, 12)");
        $this->install->catchUp();

        $this->assertSame(
            [10 => 'VLD-2026-000010', 11 => 'VLD-2026-000011', 12 => 'VLD-2026-000012'],
            $this->orderNumbers()
        );
    }

    /**
     * A brand-new installation, from zero: the first order the application
     * creates is numbered with the generic default, and a prefix chosen
     * afterwards reaches only the orders placed after it. Created through
     * OrderRepository::create() itself, against this installation's own
     * database and its own settings.
     */
    public function testAFreshInstallNumbersNewOrdersWithOrdAndALaterPrefixOnlyReachesLaterOrders(): void
    {
        $this->install = ScratchInstall::fresh('mygdala_scratch_order_number_new_orders');
        $orders = new OrderRepository($this->install->pdo());
        $this->assertNull($this->storedPrefix(), 'precondition: a from-zero install stores no prefix');

        try {
            $this->useThisInstallationsSettings();
            $first = $this->createOrderThroughTheApplication($orders);

            $this->install->pdo()->exec(
                "INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at)
                 VALUES ('order_number_prefix', 'SHOP', NOW(), NOW())"
            );
            $this->useThisInstallationsSettings();
            $second = $this->createOrderThroughTheApplication($orders);
        } finally {
            SiteSettings::overrideForTests(null);
        }

        $numbers = $this->orderNumbers();
        $years = array_map(static fn (?string $moment): string => substr((string) $moment, 0, 4), $this->creationMoments());

        $this->assertSame(1, $first, 'The first order of a new installation.');
        $this->assertSame('ORD-' . $years[$first] . '-000001', $numbers[$first]);
        $this->assertSame('SHOP-' . $years[$second] . '-' . str_pad((string) $second, 6, '0', STR_PAD_LEFT), $numbers[$second]);
    }

    /* ------------------------------------------------------------------ */

    /** Points App\Service\SiteSettings at what this installation's own site_settings table holds. */
    private function useThisInstallationsSettings(): void
    {
        $stored = [];
        foreach ($this->install->rows('SELECT setting_key, setting_value FROM site_settings') as $row) {
            $stored[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        SiteSettings::overrideForTests($stored);
    }

    private function createOrderThroughTheApplication(OrderRepository $orders): int
    {
        $pdo = $this->install->pdo();
        $pdo->prepare('INSERT INTO customers (name, email, created_at) VALUES (?, ?, NOW())')
            ->execute(['Jan Jansen', 'new-order-' . bin2hex(random_bytes(4)) . '@example.invalid']);

        return $orders->create(
            (int) $pdo->lastInsertId(),
            '10.00',
            '0.00',
            'afhalen',
            'EUR',
            true,
            new \DateTimeImmutable(),
            hash('sha256', 'test-terms'),
            [
                'first_name' => 'Jan', 'last_name' => 'Jansen', 'company' => null,
                'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
                'house_number_addition' => null, 'street' => 'Teststraat', 'city' => 'Teststad',
            ],
            null
        );
    }

    /**
     * An installation that predates the install marker, migrated up to and
     * including $version: the path ScratchInstall::legacy() takes, stopped
     * early so the test can put older orders in.
     */
    private function legacyUpTo(string $database, string $version): ScratchInstall
    {
        $install = ScratchInstall::upTo($database, self::FIRST_MIGRATION);
        $install->pdo()
            ->prepare('UPDATE ' . InstallState::TABLE . ' SET state_value = ? WHERE state_key = ?')
            ->execute([InstallState::KIND_LEGACY, InstallState::KEY_INSTALL_KIND]);
        $install->catchUp($version);

        return $install;
    }

    /** An order as the code of its day wrote it: a customer, a total and, normally, a creation moment. */
    private function insertOrder(int $id, ?string $createdAt): void
    {
        $pdo = $this->install->pdo();
        $pdo->prepare('INSERT INTO customers (name, email, created_at) VALUES (?, ?, NOW())')
            ->execute(['Jan Jansen', 'order-' . $id . '@example.invalid']);
        $pdo->prepare('INSERT INTO orders (id, customer_id, total, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$id, (int) $pdo->lastInsertId(), '12.34', $createdAt]);
    }

    /** @return array<int, string|null> id => stored order number */
    private function orderNumbers(): array
    {
        $numbers = [];
        foreach ($this->install->rows('SELECT id, order_number FROM orders ORDER BY id') as $row) {
            $numbers[(int) $row['id']] = $row['order_number'] === null ? null : (string) $row['order_number'];
        }

        return $numbers;
    }

    /** @return array<int, string|null> id => created_at */
    private function creationMoments(): array
    {
        $moments = [];
        foreach ($this->install->rows('SELECT id, created_at FROM orders ORDER BY id') as $row) {
            $moments[(int) $row['id']] = $row['created_at'] === null ? null : (string) $row['created_at'];
        }

        return $moments;
    }

    private function hasOrderNumberColumn(): bool
    {
        return $this->install->rows(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = ? AND table_name = 'orders' AND column_name = 'order_number'",
            [$this->install->database]
        ) !== [];
    }

    private function storedPrefix(): ?string
    {
        $rows = $this->install->rows("SELECT setting_value FROM site_settings WHERE setting_key = 'order_number_prefix'");

        return $rows === [] ? null : (string) $rows[0]['setting_value'];
    }

    /**
     * @param list<string> $versions
     * @return list<string> the ones among $versions that Phinx has logged, in version order
     */
    private function applied(array $versions): array
    {
        $logged = array_map(
            static fn (array $row): string => (string) $row['version'],
            $this->install->rows('SELECT version FROM phinx_migration_log ORDER BY version')
        );

        return array_values(array_intersect($logged, $versions));
    }
}
