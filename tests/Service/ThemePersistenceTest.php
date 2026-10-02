<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\ThemeSettingRepository;
use App\Service\SiteSettings;
use App\Service\Theme\ButtonStyles;
use App\Service\Theme\ThemeRegistry;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\ButtonStyleFixture;
use Tests\Support\ColorPaletteFixture;

/**
 * Storage: saving, reading back, partial saves, and what "restore theme
 * defaults" is and is not allowed to touch.
 *
 * Since Branding & Design 2.0 the colours are the ACTIVE colour palette
 * (color_palettes) and the font pairing and button shape stay in
 * theme_settings; ThemeSettings is still the one reader and writer of both,
 * which is what these tests hold it to. The palettes themselves are
 * ColorPaletteTest's. Since Button Styles 2.0 the button shape is a facade
 * over the two default button styles, so those are snapshot, started on the
 * default shape and restored too.
 *
 * Runs against the test database (tests/bootstrap.php makes sure it is never
 * the development one). Every test restores whatever the theme table and the
 * palettes held beforehand, so a run leaves no trace — the theme is site-wide state, and a
 * test that forgot to clean up would change how every other test's pages
 * render.
 */
final class ThemePersistenceTest extends TestCase
{
    /** @var array<string, string> */
    private array $before = [];

    /** @var list<array<string, mixed>> */
    private array $palettes = [];

    /** @var array{styles: list<array<string, mixed>>, defaults: list<array<string, mixed>>} */
    private array $buttonStyles = ['styles' => [], 'defaults' => []];

    protected function setUp(): void
    {
        parent::setUp();

        $this->before = (new ThemeSettingRepository())->findAll();
        (new ThemeSettingRepository())->deleteKeys([ThemeSettings::ACTIVE_THEME_KEY]);
        $this->palettes = ColorPaletteFixture::snapshot();
        ColorPaletteFixture::only();
        $this->buttonStyles = ButtonStyleFixture::snapshot();
        ButtonStyles::saveDefaultShape(ThemeSettings::defaults()['button_shape']);
        ThemeSettings::clearCache();
    }

    protected function tearDown(): void
    {
        $repository = new ThemeSettingRepository();
        $repository->deleteKeys([...ThemeSettings::keys(), ThemeSettings::ACTIVE_THEME_KEY]);

        if ($this->before !== []) {
            $repository->upsertMany($this->before);
        }

        ColorPaletteFixture::restore($this->palettes);
        ButtonStyleFixture::restore($this->buttonStyles);
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

        ThemeSettings::save(['primary_color' => 'url(https://example.com/x.png)', 'button_shape' => 'x;}a{b:c']);
        ThemeSettings::clearCache();

        $this->assertSame([], $repository->findAll());
        $this->assertSame(ThemeSettings::defaults()['primary_color'], ThemeSettings::get('primary_color'));
        $this->assertSame(ThemeSettings::defaults()['primary_color'], ColorPaletteFixture::snapshot()[0]['primary_color']);

        // ... and a refused value never takes the place of a valid one
        // that is already stored.
        ThemeSettings::save(['primary_color' => '#2F6FED']);
        ThemeSettings::save(['primary_color' => 'url(https://example.com/x.png)']);
        ThemeSettings::clearCache();

        $this->assertSame('#2F6FED', ColorPaletteFixture::snapshot()[0]['primary_color']);
        $this->assertSame('#2F6FED', ThemeSettings::get('primary_color'));
    }

    public function testAColourIsSavedIntoTheActivePaletteAndNeverIntoTheThemeTable(): void
    {
        (new ThemeSettingRepository())->deleteKeys(ThemeSettings::keys());
        ThemeSettings::save(['primary_color' => '#2F6FED', 'font_pairing' => 'lora-montserrat']);

        $this->assertSame(['font_pairing' => 'lora-montserrat'], (new ThemeSettingRepository())->findAll());
        $palettes = ColorPaletteFixture::snapshot();
        $this->assertCount(1, $palettes);
        $this->assertSame('#2F6FED', $palettes[0]['primary_color']);
        $this->assertSame(1, (int) $palettes[0]['is_active']);
    }

    public function testAnOldColourRowIsIgnoredWhileAPaletteIsActive(): void
    {
        // Migration 20261003100000 removes these rows; one that comes back
        // (a hand edit, an old backup) must not become a second source.
        (new ThemeSettingRepository())->upsertMany(['primary_color' => '#FF0000']);
        ThemeSettings::clearCache();

        $this->assertSame(ThemeSettings::defaults()['primary_color'], ThemeSettings::get('primary_color'));
    }

    public function testResetSetsTheActivePaletteBackAndLeavesTheOthers(): void
    {
        ThemeSettings::save(['primary_color' => '#2F6FED']);
        $other = \App\Service\Theme\ColorPaletteService::create(['name' => 'ZZ Ander'] + ColorPaletteFixture::COLORS);

        ThemeSettings::reset();
        ThemeSettings::clearCache();

        $this->assertTrue(ThemeSettings::isDefault());
        $this->assertSame('#FF7518', \App\Service\Theme\ColorPaletteService::find($other)['primary_color']);
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

    /* ------------------------------------------------------------------ */
    /* The Global Theme key (ThemeRegistry)                                */
    /* ------------------------------------------------------------------ */

    public function testNoActiveThemeRowReadsAsEmptyAndResolvesToLegacy(): void
    {
        ThemeSettings::clearCache();

        $this->assertSame('', ThemeSettings::activeThemeKey());
        $this->assertSame('legacy', ThemeRegistry::active()->key);
    }

    public function testTheStoredActiveThemeIsReadRawAndCachedWithTheRest(): void
    {
        $repository = new ThemeSettingRepository();
        $repository->upsertMany([ThemeSettings::ACTIVE_THEME_KEY => 'Kobold']);
        ThemeSettings::clearCache();

        $this->assertSame('Kobold', ThemeSettings::activeThemeKey());

        $repository->upsertMany([ThemeSettings::ACTIVE_THEME_KEY => 'legacy']);
        $this->assertSame('Kobold', ThemeSettings::activeThemeKey(), 'read once per request, like every theme row');

        ThemeSettings::clearCache();
        $this->assertSame('legacy', ThemeSettings::activeThemeKey());
    }

    /**
     * An unknown key renders legacy and stays stored: the theme may come
     * back (an extension deployed later), and a repair would throw away
     * what the owner chose.
     */
    public function testAnUnknownStoredThemeRendersLegacyAndIsNotRepaired(): void
    {
        foreach (['kobold', '../../evil.css'] as $stored) {
            (new ThemeSettingRepository())->upsertMany([ThemeSettings::ACTIVE_THEME_KEY => $stored]);
            ThemeSettings::clearCache();

            $this->assertSame('legacy', ThemeRegistry::active()->key);
            $this->assertNull(ThemeRegistry::active()->stylesheet);
            $this->assertSame($stored, (new ThemeSettingRepository())->findAll()[ThemeSettings::ACTIVE_THEME_KEY] ?? null);
        }
    }

    /** "Standaardvormgeving herstellen" is not a theme switch. */
    public function testResetLeavesTheActiveThemeAlone(): void
    {
        (new ThemeSettingRepository())->upsertMany([ThemeSettings::ACTIVE_THEME_KEY => 'kobold']);
        ThemeSettings::save(['primary_color' => '#2F6FED', 'font_pairing' => 'lora-montserrat']);

        ThemeSettings::reset();
        ThemeSettings::clearCache();

        $this->assertTrue(ThemeSettings::isDefault());
        $this->assertSame([ThemeSettings::ACTIVE_THEME_KEY => 'kobold'], (new ThemeSettingRepository())->findAll());
        $this->assertSame('kobold', ThemeSettings::activeThemeKey());
    }

    public function testAStoredThemeLeavesTheAppearanceDefaultAndUnchanged(): void
    {
        (new ThemeSettingRepository())->deleteKeys(ThemeSettings::keys());
        (new ThemeSettingRepository())->upsertMany([ThemeSettings::ACTIVE_THEME_KEY => 'legacy']);
        ThemeSettings::clearCache();

        $this->assertTrue(ThemeSettings::isDefault());
        $this->assertSame([], ThemeSettings::changedKeys());
        $this->assertSame(ThemeSettings::defaults(), ThemeSettings::all());
        $this->assertNotContains(ThemeSettings::ACTIVE_THEME_KEY, ThemeSettings::keys());
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
