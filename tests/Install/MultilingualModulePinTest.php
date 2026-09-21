<?php

declare(strict_types=1);

namespace Tests\Install;

use App\Install\InstallState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * Who 20260921100000_pin_the_multilingual_module_where_it_is_in_use keeps
 * publishing its other languages, and who it leaves on the module's new
 * default (off: the default language only).
 *
 *   from zero          no history, wizard not finished          stores nothing
 *   fresh and running  built from zero, wizard finished         pinned on
 *                      before the pin
 *   legacy             predates the install marker              pinned on, and an
 *                                                               owner's own choice
 *                                                               survives a replay
 *
 * Same shape as Tests\Install\PortfolioModulePinTest. Each installation is
 * built by the migrations themselves, never written by hand.
 */
#[Group('migration-backfill')]
final class MultilingualModulePinTest extends TestCase
{
    /** The last migration before the pin: every translation table exists. */
    private const BEFORE_THE_PIN = '20260920110000';

    private const THE_PIN = '20260921100000';

    private const SETTING_KEY = 'module_multilingual_enabled';

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
        $this->install = ScratchInstall::fresh('mygdala_scratch_multilingual_pin_zero');

        $this->assertNull($this->storedPreference($this->install), 'a new site publishes its default language until it asks for more');
        $this->assertSame(2, $this->install->count('site_languages'), 'and its registry is untouched: the languages are there, just not published');
    }

    public function testAFreshInstallThatIsAlreadyRunningKeepsPublishingItsLanguages(): void
    {
        $this->install = ScratchInstall::upTo('mygdala_scratch_multilingual_pin_fresh', self::BEFORE_THE_PIN);
        $pdo = $this->install->pdo();

        $this->assertSame(InstallState::KIND_FRESH, InstallState::kind($pdo), 'The case is a FRESH install, not a legacy one.');

        // The wizard finished before the pin: a running site, published in
        // Dutch and English like every site of its day.
        $pdo->exec(
            "INSERT INTO install_state (state_key, state_value, created_at)
             VALUES ('setup_completed_at', '2026-09-20 12:00:00', NOW())"
        );

        $this->install->catchUp();

        $this->assertSame('1', $this->storedPreference($this->install), 'a running site keeps its languages on the next deploy');

        // Still in its wizard: the new default applies, whatever the seed wrote.
        $pdo->exec('DELETE FROM module_settings');
        $pdo->exec("DELETE FROM install_state WHERE state_key = 'setup_completed_at'");
        $this->install->replay(self::THE_PIN);

        $this->assertNull($this->storedPreference($this->install), 'a site still being set up gets the new default');
    }

    public function testALegacyInstallIsPinnedAndAnOwnersOwnChoiceSurvivesAReplay(): void
    {
        $this->install = ScratchInstall::legacy('mygdala_scratch_multilingual_pin_legacy');

        $this->assertSame('1', $this->storedPreference($this->install), 'every existing site published Dutch and English');

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
