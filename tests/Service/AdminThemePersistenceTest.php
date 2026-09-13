<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\AdminSettingRepository;
use App\Repository\ThemeSettingRepository;
use App\Service\AdminTheme;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;

/**
 * Storage for the dashboard theme: saving it, reading it back, what an
 * installation that never chose one looks like, and the wall between this
 * choice and the public site's appearance.
 *
 * Runs against the test database (tests/bootstrap.php makes sure it is never
 * the development one). Every test puts back whatever `admin_settings` held
 * beforehand: the theme is installation-wide state, so a test that forgot to
 * clean up would restyle the CMS for whoever looked next.
 */
final class AdminThemePersistenceTest extends TestCase
{
    /** A light palette, as stored: lowercase and complete. */
    private const LIGHT = ['bg' => '#f7f7f2', 'sidebar' => '#ffffff', 'surface' => '#ffffff', 'text' => '#1b1b1b', 'accent' => '#0b6e4f'];

    /** @var array<string, string> */
    private array $before = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->before = (new AdminSettingRepository())->findAll();
        AdminTheme::clearCache();
    }

    protected function tearDown(): void
    {
        $repository = new AdminSettingRepository();
        $repository->deleteKeys(AdminTheme::settingKeys());

        if ($this->before !== []) {
            $repository->upsertMany($this->before);
        }

        AdminTheme::overrideForTests(null);
        AdminTheme::clearCache();

        parent::tearDown();
    }

    public function testAnInstallationThatNeverChoseOneIsOnDefault(): void
    {
        AdminTheme::reset();

        $this->assertSame('default', AdminTheme::current());
        $this->assertTrue(AdminTheme::isDefault());
    }

    public function testAChosenThemeComesBack(): void
    {
        $this->assertTrue(AdminTheme::save('ocean'));
        AdminTheme::clearCache();

        $this->assertSame('ocean', AdminTheme::current());
        $this->assertFalse(AdminTheme::isDefault());
        $this->assertSame(' data-admin-theme="ocean"', AdminTheme::bodyAttribute());
    }

    public function testEveryThemeInTheRegistryCanBeStoredAndReadBack(): void
    {
        foreach (AdminTheme::keys() as $key) {
            $this->assertTrue(AdminTheme::save($key), $key . ' could not be saved');
            AdminTheme::clearCache();

            $this->assertSame($key, AdminTheme::current());
        }
    }

    public function testSavingSomethingOutsideTheSetChangesNothing(): void
    {
        AdminTheme::save('black');
        AdminTheme::clearCache();

        $this->assertFalse(AdminTheme::save('sunset'));
        AdminTheme::clearCache();

        $this->assertSame('black', AdminTheme::current());
    }

    public function testARowThatIsNoLongerAKnownThemeFallsBackToDefault(): void
    {
        // What an installation looks like after a theme is retired, or after
        // somebody edited the row by hand.
        (new AdminSettingRepository())->upsertMany([AdminTheme::SETTING_KEY => 'sunset']);
        AdminTheme::clearCache();

        $this->assertSame('default', AdminTheme::current());
    }

    public function testResetDeletesTheRowRatherThanStoringTheDefault(): void
    {
        AdminTheme::save('classic');
        AdminTheme::reset();

        $this->assertArrayNotHasKey(
            AdminTheme::SETTING_KEY,
            (new AdminSettingRepository())->findAll()
        );
        $this->assertSame('default', AdminTheme::current());
    }

    public function testChoosingADashboardThemeLeavesTheWebsiteAppearanceAlone(): void
    {
        $websiteBefore = ThemeSettings::all();
        $themeRowsBefore = (new ThemeSettingRepository())->findAll();

        AdminTheme::save('black');
        ThemeSettings::clearCache();

        $this->assertSame($websiteBefore, ThemeSettings::all());
        $this->assertSame($themeRowsBefore, (new ThemeSettingRepository())->findAll());
    }

    public function testRestoringTheWebsiteAppearanceLeavesTheDashboardThemeAlone(): void
    {
        $themeRowsBefore = (new ThemeSettingRepository())->findAll();

        AdminTheme::save('ocean');

        try {
            ThemeSettings::reset();

            AdminTheme::clearCache();
            $this->assertSame('ocean', AdminTheme::current());
        } finally {
            if ($themeRowsBefore !== []) {
                (new ThemeSettingRepository())->upsertMany($themeRowsBefore);
            }
            ThemeSettings::clearCache();
        }
    }

    // --- Eigen kleuren -------------------------------------------------------

    public function testEigenKleurenComesBackWithItsColours(): void
    {
        AdminTheme::reset();

        $this->assertTrue(AdminTheme::save('custom', ['bg' => '#F7F7F2', 'sidebar' => 'fff', 'surface' => '#ffffff', 'text' => '#1b1b1b', 'accent' => '#0B6E4F']));
        AdminTheme::clearCache();

        $this->assertSame('custom', AdminTheme::current());
        $this->assertSame(self::LIGHT, AdminTheme::customColors());
        $this->assertStringContainsString('--admin-custom-accent: #0b6e4f', AdminTheme::bodyAttribute());
        $this->assertSame('#f7f7f2', (new AdminSettingRepository())->findAll()[AdminTheme::COLOR_SETTING_PREFIX . 'bg'] ?? null);
    }

    public function testAnInvalidColourIsRefusedAndNothingIsStored(): void
    {
        AdminTheme::reset();
        AdminTheme::save('ocean');

        $this->assertFalse(AdminTheme::save('custom', ['text' => 'red'] + self::LIGHT));
        AdminTheme::clearCache();

        $this->assertSame('ocean', AdminTheme::current());

        $stored = (new AdminSettingRepository())->findAll();
        foreach (array_keys(AdminTheme::COLORS) as $name) {
            $this->assertArrayNotHasKey(AdminTheme::COLOR_SETTING_PREFIX . $name, $stored, 'part of a refused palette was stored');
        }
    }

    public function testChoosingAFixedThemeKeepsTheColoursForNextTime(): void
    {
        AdminTheme::reset();
        AdminTheme::save('custom', self::LIGHT);
        AdminTheme::save('classic');
        AdminTheme::clearCache();

        $this->assertSame('classic', AdminTheme::current());
        $this->assertSame(' data-admin-theme="classic"', AdminTheme::bodyAttribute());
        $this->assertSame(self::LIGHT, AdminTheme::customColors());

        // Back to Eigen kleuren without new colours: the stored ones return.
        $this->assertTrue(AdminTheme::save('custom'));
        AdminTheme::clearCache();

        $this->assertSame('custom', AdminTheme::current());
        $this->assertSame(self::LIGHT, AdminTheme::customColors());
    }

    public function testAHandEditedColourRowFallsBackToTheDefaultColour(): void
    {
        AdminTheme::reset();
        (new AdminSettingRepository())->upsertMany([
            AdminTheme::SETTING_KEY => 'custom',
            AdminTheme::COLOR_SETTING_PREFIX . 'bg' => '#101820',
            AdminTheme::COLOR_SETTING_PREFIX . 'accent' => 'javascript:alert(1)',
        ]);
        AdminTheme::clearCache();

        $colors = AdminTheme::customColors();

        $this->assertSame('#101820', $colors['bg']);
        $this->assertSame(AdminTheme::COLORS['accent'], $colors['accent']);
        $this->assertSame(AdminTheme::COLORS['text'], $colors['text']);
        $this->assertStringNotContainsString('javascript', AdminTheme::bodyAttribute());
    }

    public function testResetForgetsTheColoursToo(): void
    {
        AdminTheme::save('custom', self::LIGHT);
        AdminTheme::reset();

        $stored = (new AdminSettingRepository())->findAll();
        foreach (AdminTheme::settingKeys() as $key) {
            $this->assertArrayNotHasKey($key, $stored);
        }

        $this->assertSame('default', AdminTheme::current());
        $this->assertSame(AdminTheme::COLORS, AdminTheme::customColors());
    }
}
