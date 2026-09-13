<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Install\InstallState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Who 20260913100000_pin_the_order_number_prefix_before_the_generic_default
 * pins to "VLD", and who it leaves on the generic "ORD".
 *
 * An order number is derived from the order's id and year every time it is
 * shown, never stored. Whoever already sent numbers with the old hardcoded
 * prefix must keep deriving them, and whoever never sent one must not be
 * handed another site's prefix. Three installations, each built by the
 * migrations themselves rather than written by hand:
 *
 *   from zero         no history, no orders          stores nothing
 *   fresh with orders built from zero, took orders   pinned to VLD
 *                     before the pin existed
 *   legacy            predates the install marker    pinned to VLD, and an
 *                                                    owner's own choice survives
 *
 * The first case is also asserted from the other side by
 * Tests\Install\FreshInstallTest, and the historical numbers themselves by
 * Tests\Install\LegacyUpgradeTest.
 */
#[Group('migration-backfill')]
final class OrderNumberPrefixPinTest extends TestCase
{
    /** The last migration before the pin: where a test puts its orders. */
    private const BEFORE_THE_PIN = '20260912100000';

    private const THE_PIN = '20260913100000';

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

    public function testAnInstallBuiltFromZeroIsNotPinned(): void
    {
        $this->install = ScratchInstall::fresh('mygdala_scratch_order_prefix_zero');

        $this->assertNull($this->storedPrefix($this->install));
    }

    public function testAFreshInstallThatAlreadyTookOrdersKeepsItsNumbers(): void
    {
        $this->install = ScratchInstall::upTo('mygdala_scratch_order_prefix_orders', self::BEFORE_THE_PIN);
        $pdo = $this->install->pdo();

        $this->assertSame(InstallState::KIND_FRESH, InstallState::kind($pdo), 'The case is a FRESH install, not a legacy one.');

        $pdo->exec("INSERT INTO customers (name, email, created_at) VALUES ('Jan Jansen', 'jan@example.invalid', NOW())");
        $pdo->prepare('INSERT INTO orders (customer_id, total, created_at) VALUES (?, ?, NOW())')
            ->execute([(int) $pdo->lastInsertId(), '12.34']);

        $this->install->catchUp();

        $this->assertSame(
            'VLD',
            $this->storedPrefix($this->install),
            'Its orders were shown VLD- numbers before the pin; they must keep them.'
        );
    }

    public function testALegacyInstallIsPinnedAndAnOwnersOwnPrefixSurvivesAReplay(): void
    {
        $this->install = ScratchInstall::legacy('mygdala_scratch_order_prefix_legacy');

        $this->assertSame('VLD', $this->storedPrefix($this->install));

        $this->install->pdo()->exec(
            "UPDATE site_settings SET setting_value = 'SHOP' WHERE setting_key = 'order_number_prefix'"
        );
        $this->install->replay(self::THE_PIN);

        $this->assertSame('SHOP', $this->storedPrefix($this->install), 'A second run must never overwrite a chosen prefix.');
    }

    private function storedPrefix(ScratchInstall $install): ?string
    {
        $rows = $install->rows("SELECT setting_value FROM site_settings WHERE setting_key = 'order_number_prefix'");

        return $rows === [] ? null : (string) $rows[0]['setting_value'];
    }
}
