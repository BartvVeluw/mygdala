<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\ThemeSettingRepository;
use App\Service\SiteSettings;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;

/**
 * Storage: saving, reading back, partial saves, and what "restore theme
 * defaults" is and is not allowed to touch.
 *
 * Runs against the test database (tests/bootstrap.php makes sure it is never
 * the development one). Every test restores whatever the theme table held
 * beforehand, so a run leaves no trace — the theme is site-wide state, and a
 * test that forgot to clean up would change how every other test's pages
 * render.
 */
final class ThemePersistenceTest extends TestCase
{
    /** @var array<string, string> */
    private array $before = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->before = (new ThemeSettingRepository())->findAll();
        ThemeSettings::clearCache();
    }

    protected function tearDown(): void
    {
        $repository = new ThemeSettingRepository();
        $repository->deleteKeys(ThemeSettings::keys());

        if ($this->before !== []) {
            $repository->upsertMany($this->before);
        }

        ThemeSettings::clearCache();
        ThemeSettings::overrideForTests(null);

        parent::tearDown();
    }

    public function testAFreshInstallWithNoRowsRendersTheDefaultTheme(): void
    {
        (new ThemeSettingRepository())->deleteKeys(ThemeSettings::keys());
        ThemeSettings::clearCache();

        $this->assertTrue(ThemeSettings::isDefault());
        $this->assertSame(ThemeSettings::defaults(), ThemeSettings::all());
    }

    public function testSavedValuesComeBackNormalised(): void
    {
        ThemeSettings::save([
            'primary_color' => '2f6fed',
            'background_color' => '#fff',
            'font_pairing' => 'lora-montserrat',
            'button_shape' => 'rounded',
        ]);
        ThemeSettings::clearCache();

        $this->assertSame('#2F6FED', ThemeSettings::get('primary_color'));
        $this->assertSame('#FFFFFF', ThemeSettings::get('background_color'));
        $this->assertSame('lora-montserrat', ThemeSettings::get('font_pairing'));
        $this->assertSame('rounded', ThemeSettings::get('button_shape'));
    }

    public function testAPartialSaveLeavesEverythingElseOnItsDefault(): void
    {
        ThemeSettings::save(['primary_color' => '#2F6FED']);
        ThemeSettings::clearCache();

        $defaults = ThemeSettings::defaults();

        $this->assertSame('#2F6FED', ThemeSettings::get('primary_color'));
        $this->assertSame($defaults['surface_color'], ThemeSettings::get('surface_color'));
        $this->assertSame($defaults['text_color'], ThemeSettings::get('text_color'));
        $this->assertSame(['primary_color'], ThemeSettings::changedKeys());
    }

    public function testAnInvalidValueIsNeverStored(): void
    {
        // Its own starting point, like the fresh-install test above: whether
        // this installation's owner already chose a theme is not a
        // precondition of the rule. tearDown() puts their theme back.
        $repository = new ThemeSettingRepository();
        $repository->deleteKeys(ThemeSettings::keys());
        ThemeSettings::clearCache();

        ThemeSettings::save(['primary_color' => 'url(https://example.com/x.png)']);
        ThemeSettings::clearCache();

        $this->assertArrayNotHasKey('primary_color', $repository->findAll());
        $this->assertSame(ThemeSettings::defaults()['primary_color'], ThemeSettings::get('primary_color'));

        // ... and a refused value never takes the place of a valid one
        // that is already stored.
        ThemeSettings::save(['primary_color' => '#2F6FED']);
        ThemeSettings::save(['primary_color' => 'url(https://example.com/x.png)']);
        ThemeSettings::clearCache();

        $this->assertSame(['primary_color' => '#2F6FED'], $repository->findAll());
        $this->assertSame('#2F6FED', ThemeSettings::get('primary_color'));
    }

    public function testAHandEditedRowThatIsNoLongerValidFallsBackRatherThanRendering(): void
    {
        (new ThemeSettingRepository())->upsertMany(['font_pairing' => 'a-pairing-that-was-removed']);
        ThemeSettings::clearCache();

        $this->assertSame(ThemeSettings::defaults()['font_pairing'], ThemeSettings::get('font_pairing'));
    }

    public function testResetRemovesTheRowsRatherThanWritingTheDefaultsBack(): void
    {
        ThemeSettings::save(['primary_color' => '#2F6FED', 'button_shape' => 'rounded']);
        ThemeSettings::clearCache();
        $this->assertFalse(ThemeSettings::isDefault());

        ThemeSettings::reset();
        ThemeSettings::clearCache();

        $this->assertTrue(ThemeSettings::isDefault());
        $this->assertSame([], (new ThemeSettingRepository())->findAll());
    }

    /**
     * The property the whole SiteSettings/ThemeSettings split exists for.
     */
    public function testResetLeavesEverySiteSettingUntouched(): void
    {
        $identityBefore = SiteSettings::all();

        ThemeSettings::save(['primary_color' => '#2F6FED']);
        ThemeSettings::reset();

        SiteSettings::clearCache();

        $this->assertSame($identityBefore, SiteSettings::all());
    }

    public function testTheThemeTableIsSeparateFromTheSiteSettingsTable(): void
    {
        $tables = Database::connection()
            ->query("SHOW TABLES LIKE 'theme_settings'")
            ->fetchAll();

        $this->assertCount(1, $tables, 'the theme needs its own table, not a prefix in site_settings');

        ThemeSettings::save(['primary_color' => '#2F6FED']);

        $leaked = Database::connection()
            ->query("SELECT COUNT(*) FROM site_settings WHERE setting_key LIKE '%_color'")
            ->fetchColumn();

        $this->assertSame(0, (int) $leaked, 'a theme colour must never end up in site_settings');
    }
}
