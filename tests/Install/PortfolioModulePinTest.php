<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Install\InstallState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Who 20260914170000_pin_the_portfolio_module_where_it_is_in_use keeps on the
 * Portfolio, and who it leaves on the module's new default (off).
 *
 * The Portfolio became an optional module that a new installation only gets
 * when it asks. Whoever was already running it must keep it on the next
 * deploy, and whoever never used it must not be handed a section it never
 * wanted. Two installations, each built by the migrations themselves rather
 * than written by hand:
 *
 *   from zero          no history, no portfolio content      stores nothing
 *   fresh with content built from zero, added an item        pinned on
 *                      (or only a category) before the pin
 *   legacy             predates the install marker           pinned on, and an
 *                                                            owner's own choice
 *                                                            survives a replay
 *
 * Same shape as Tests\Install\OrderNumberPrefixPinTest.
 */
#[Group('migration-backfill')]
final class PortfolioModulePinTest extends TestCase
{
    /** A migration that runs before the pin: where a test puts its portfolio content. */
    private const BEFORE_THE_PIN = '20260913120000';

    private const THE_PIN = '20260914170000';

    private const SETTING_KEY = 'module_portfolio_enabled';

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
        $this->install = ScratchInstall::fresh('mygdala_scratch_portfolio_pin_zero');

        $this->assertNull($this->storedPreference($this->install), 'a new site gets the Portfolio only when it asks');
    }

    /**
     * One fresh installation, walked through the three states the pin must
     * tell apart: it holds an item, it holds only a category, it holds
     * nothing. replay() runs the very file that ships again, the honest proof
     * that each answer comes from the data and not from an earlier run.
     */
    public function testAFreshInstallThatAlreadyUsesThePortfolioKeepsIt(): void
    {
        $this->install = ScratchInstall::upTo('mygdala_scratch_portfolio_pin_fresh', self::BEFORE_THE_PIN);
        $pdo = $this->install->pdo();

        $this->assertSame(InstallState::KIND_FRESH, InstallState::kind($pdo), 'The case is a FRESH install, not a legacy one.');

        $pdo->exec("INSERT INTO portfolio_galleries (created_at, updated_at) VALUES (NOW(), NOW())");
        $pdo->prepare(
            "INSERT INTO portfolio_gallery_items
                (portfolio_gallery_id, image_path, alt_nl, title_nl, subtitle_nl, categories, sort_order, is_active, created_at, updated_at)
             VALUES (?, 'assets/images/sections/zz-werk.jpg', '', 'Eigen werk', '', '', 0, 1, NOW(), NOW())"
        )->execute([(int) $pdo->lastInsertId()]);

        $this->install->catchUp();

        $this->assertSame('1', $this->storedPreference($this->install), 'its item was on a live portfolio before the pin');

        // Only a category, no item: somebody started building a portfolio.
        $pdo->exec('DELETE FROM module_settings');
        $pdo->exec('DELETE FROM portfolio_gallery_items');
        // Caught up past 20260918170000, so a category's NAME is a row in
        // portfolio_category_translations, not a column here.
        $pdo->exec("INSERT INTO portfolio_categories (slug, sort_order, created_at, updated_at) VALUES ('hout', 0, NOW(), NOW())");
        $pdo->prepare(
            "INSERT INTO portfolio_category_translations (portfolio_category_id, language_code, name, created_at, updated_at)
             VALUES (?, 'nl', 'Hout', NOW(), NOW())"
        )->execute([(int) $pdo->lastInsertId()]);
        $this->install->replay(self::THE_PIN);

        $this->assertSame('1', $this->storedPreference($this->install), 'a category is portfolio content too');

        // And nothing at all: the new default applies.
        $pdo->exec('DELETE FROM module_settings');
        $pdo->exec('DELETE FROM portfolio_categories');
        $this->install->replay(self::THE_PIN);

        $this->assertNull($this->storedPreference($this->install), 'an unused Portfolio is not switched on for anybody');
    }

    public function testALegacyInstallIsPinnedAndAnOwnersOwnChoiceSurvivesAReplay(): void
    {
        $this->install = ScratchInstall::legacy('mygdala_scratch_portfolio_pin_legacy');

        $this->assertSame('1', $this->storedPreference($this->install));

        $this->install->pdo()->prepare('UPDATE module_settings SET setting_value = ? WHERE setting_key = ?')
            ->execute(['0', self::SETTING_KEY]);
        $this->install->replay(self::THE_PIN);

        $this->assertSame('0', $this->storedPreference($this->install), 'a second run must never overwrite a chosen preference');
    }

    private function storedPreference(ScratchInstall $install): ?string
    {
        $rows = $install->rows('SELECT setting_value FROM module_settings WHERE setting_key = ?', [self::SETTING_KEY]);

        return $rows === [] ? null : (string) $rows[0]['setting_value'];
    }
}
