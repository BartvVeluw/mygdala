<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\ColorPaletteRepository;
use App\Repository\ThemeSettingRepository;
use App\Service\Theme\ColorPaletteService;
use App\Service\Theme\ThemeColor;
use App\Service\Theme\ThemeCss;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\ColorPaletteFixture;

/**
 * The website's colour palettes (Branding & Design 2.0, THEMING.md
 * "Kleurenpaletten") against the test database: managing them, the
 * one-active contract, what the website shows, and the fallbacks.
 *
 * "What the website shows" is ThemeCss::styleBlock() and ThemeSettings —
 * exactly what every public page prints (App\Service\PageAssets) — so a test
 * here that says "the frontend did not change" compares the real output.
 *
 * Every test starts from one active "Standaard" palette with the shipped
 * default and puts the table back as it found it.
 */
final class ColorPaletteTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $palettes = [];

    /** @var array<string, string> */
    private array $themeRows = [];

    private int $standaard = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palettes = ColorPaletteFixture::snapshot();
        $this->themeRows = (new ThemeSettingRepository())->findAll();
        $this->standaard = ColorPaletteFixture::only();
        ThemeSettings::clearCache();
    }

    protected function tearDown(): void
    {
        ColorPaletteFixture::restore($this->palettes);

        $repository = new ThemeSettingRepository();
        $repository->deleteKeys(ThemeSettings::keys());
        if ($this->themeRows !== []) {
            $repository->upsertMany($this->themeRows);
        }
        ThemeSettings::clearCache();

        parent::tearDown();
    }

    // ------------------------------------------------------------- CRUD

    public function testTheActivePaletteIsWhatTheSettingsAndTheWebsiteRead(): void
    {
        $all = ColorPaletteService::all();

        self::assertCount(1, $all);
        self::assertSame('Standaard', $all[0]['name']);
        self::assertTrue($all[0]['active']);
        self::assertTrue(ThemeSettings::isDefault());
        self::assertSame('', ThemeCss::styleBlock(), 'a default palette emits no override, as before palettes');
    }

    public function testANewPaletteStartsAsTheWebsiteAndIsNotActive(): void
    {
        ColorPaletteService::saveActiveColors(['primary_color' => '#2F6FED']);
        self::assertSame('#2F6FED', ColorPaletteService::defaults()['primary_color']);

        $result = ColorPaletteService::validate(['name' => 'Donker'] + ColorPaletteFixture::COLORS);
        self::assertSame([], $result['errors']);
        $id = ColorPaletteService::create($result['values']);

        $palette = ColorPaletteService::find($id);
        self::assertSame('Donker', $palette['name']);
        self::assertFalse($palette['active']);
        self::assertSame('#FF7518', $palette['primary_color']);
        self::assertSame($this->standaard, $this->activeId());
    }

    public function testDuplicatingCopiesTheColoursUnderANewNameAndStaysInactive(): void
    {
        $id = ColorPaletteService::create(['name' => 'Halloween'] + ColorPaletteFixture::COLORS);

        $copy = ColorPaletteService::duplicate($id);
        $second = ColorPaletteService::duplicate($id);
        $ofActive = ColorPaletteService::duplicate($this->standaard);

        self::assertSame('Halloween (kopie)', ColorPaletteService::find($copy)['name']);
        self::assertSame('Halloween (kopie 2)', ColorPaletteService::find($second)['name']);
        self::assertSame('Standaard (kopie)', ColorPaletteService::find($ofActive)['name']);
        self::assertFalse(ColorPaletteService::find($ofActive)['active'], 'a copy of the active palette is not active');
        self::assertSame($this->standaard, $this->activeId());

        foreach (ThemeSettings::COLOR_KEYS as $key) {
            self::assertSame(ColorPaletteFixture::COLORS[$key], ColorPaletteService::find($copy)[$key]);
        }

        // ... and changes on its own.
        ColorPaletteService::update($copy, ['name' => 'Halloween (kopie)', 'primary_color' => '#00FF00'] + ColorPaletteFixture::COLORS);
        self::assertSame('#FF7518', ColorPaletteService::find($id)['primary_color']);
        self::assertNull(ColorPaletteService::duplicate(999999));
    }

    public function testRenamingAndChangingASavedPalette(): void
    {
        $id = ColorPaletteService::create(['name' => 'Licht'] + ColorPaletteFixture::COLORS);

        $result = ColorPaletteService::validate(['name' => 'Licht 2025', 'text_color' => '#000'] + ColorPaletteFixture::COLORS, $id);
        self::assertSame([], $result['errors']);
        ColorPaletteService::update($id, $result['values']);

        $palette = ColorPaletteService::find($id);
        self::assertSame('Licht 2025', $palette['name']);
        self::assertSame('#000000', $palette['text_color']);

        // Its own name is not "taken"; another palette's is (any case).
        self::assertArrayNotHasKey('name', ColorPaletteService::validate(['name' => 'Licht 2025'] + ColorPaletteFixture::COLORS, $id)['errors']);
        self::assertArrayHasKey('name', ColorPaletteService::validate(['name' => 'standaard'] + ColorPaletteFixture::COLORS, $id)['errors']);
        self::assertArrayHasKey('name', ColorPaletteService::validate(['name' => '  '] + ColorPaletteFixture::COLORS)['errors']);
        self::assertArrayHasKey('name', ColorPaletteService::validate(['name' => str_repeat('x', 81)] + ColorPaletteFixture::COLORS)['errors']);
    }

    public function testDeletingAnInactivePalette(): void
    {
        $id = ColorPaletteService::create(['name' => 'Weg'] + ColorPaletteFixture::COLORS);
        $before = ThemeCss::styleBlock();

        self::assertSame(ColorPaletteService::DELETE_DELETED, ColorPaletteService::delete($id));
        self::assertNull(ColorPaletteService::find($id));
        self::assertSame($before, ThemeCss::styleBlock(), 'deleting an inactive palette never changes the website');
        self::assertSame(ColorPaletteService::DELETE_MISSING, ColorPaletteService::delete($id));
    }

    public function testTheActivePaletteCannotBeDeleted(): void
    {
        ColorPaletteService::create(['name' => 'Ander'] + ColorPaletteFixture::COLORS);

        self::assertSame(ColorPaletteService::DELETE_ACTIVE, ColorPaletteService::delete($this->standaard));
        self::assertNotNull(ColorPaletteService::find($this->standaard));

        // The SQL refuses it too, whatever a caller checked.
        self::assertFalse((new ColorPaletteRepository())->deleteInactive($this->standaard));
        self::assertSame($this->standaard, $this->activeId());
    }

    public function testTheLastPaletteCannotBeDeleted(): void
    {
        // Even an inactive last one: the website always needs a palette.
        Database::connection()->exec('UPDATE color_palettes SET is_active = NULL');
        ColorPaletteService::clearCache();

        self::assertSame(ColorPaletteService::DELETE_LAST, ColorPaletteService::delete($this->standaard));
        self::assertSame(1, (new ColorPaletteRepository())->count());
    }

    // ------------------------------------------------------- activation

    public function testActivatingSwitchesAtomicallyAndKeepsEveryPalette(): void
    {
        $donker = ColorPaletteService::create(['name' => 'Donker'] + ColorPaletteFixture::COLORS);
        $licht = ColorPaletteService::create(['name' => 'Licht', 'background_color' => '#FFFFFF'] + ColorPaletteFixture::COLORS);

        self::assertTrue(ColorPaletteService::activate($donker));
        self::assertSame([$donker], $this->activeIds());
        self::assertSame('#FF7518', ThemeSettings::get('primary_color'));

        self::assertTrue(ColorPaletteService::activate($licht));
        self::assertSame([$licht], $this->activeIds());
        self::assertSame('#FFFFFF', ThemeSettings::get('background_color'));

        self::assertTrue(ColorPaletteService::activate($licht), 'activating the active palette is a no-op');
        self::assertSame([$licht], $this->activeIds());

        self::assertFalse(ColorPaletteService::activate(999999));
        self::assertSame([$licht], $this->activeIds(), 'an unknown id changes nothing');

        self::assertCount(3, ColorPaletteService::all());
        self::assertSame('#C9A063', ColorPaletteService::find($this->standaard)['primary_color'], 'the previous palette is kept');
    }

    public function testEditingAnInactivePaletteNeverChangesTheWebsite(): void
    {
        $id = ColorPaletteService::create(['name' => 'Concept'] + ColorPaletteFixture::COLORS);
        $settings = ThemeSettings::all();
        $block = ThemeCss::styleBlock();

        ColorPaletteService::update($id, ['name' => 'Concept', 'primary_color' => '#123456', 'background_color' => '#FFFFFF'] + ColorPaletteFixture::COLORS);

        self::assertSame($settings, ThemeSettings::all());
        self::assertSame($block, ThemeCss::styleBlock());
    }

    public function testEditingTheActivePaletteChangesTheWebsite(): void
    {
        ColorPaletteService::update($this->standaard, ['name' => 'Standaard', 'primary_color' => '#2F6FED'] + ThemeSettings::defaults());

        self::assertSame('#2F6FED', ThemeSettings::get('primary_color'));
        self::assertStringContainsString('--color-primary: #2F6FED;', ThemeCss::styleBlock());
        self::assertStringNotContainsString('--color-bg:', ThemeCss::styleBlock(), 'still only what differs from the default');
    }

    public function testTheWebsiteFollowsTheActiveChoice(): void
    {
        $id = ColorPaletteService::create(['name' => 'Donker'] + ColorPaletteFixture::COLORS);
        self::assertSame('', ThemeCss::styleBlock());

        ColorPaletteService::activate($id);
        $block = ThemeCss::styleBlock();
        self::assertStringContainsString('--color-primary: #FF7518;', $block);
        self::assertStringContainsString('--color-bg: #1A0F1F;', $block);
        self::assertSame('#1A0F1F', ThemeCss::backgroundColor(), 'the theme-color meta follows too');

        ColorPaletteService::activate($this->standaard);
        self::assertSame('', ThemeCss::styleBlock(), 'back to the shipped default: no override at all');
    }

    public function testWithoutAnActivePaletteTheWebsiteShowsTheShippedDefault(): void
    {
        ColorPaletteService::saveActiveColors(['primary_color' => '#2F6FED']);
        Database::connection()->exec('UPDATE color_palettes SET is_active = NULL');
        ColorPaletteService::clearCache();

        self::assertTrue(ThemeSettings::isDefault());
        self::assertSame('', ThemeCss::styleBlock());

        // A colour saved the old way lands in a palette that becomes active.
        ThemeSettings::save(['text_color' => '#EEEEEE']);
        self::assertSame([$this->standaard], $this->activeIds());
        self::assertSame('#EEEEEE', ThemeSettings::get('text_color'));
    }

    public function testAStoredColourThatNoLongerValidatesFallsBackForThatColourOnly(): void
    {
        Database::connection()->prepare('UPDATE color_palettes SET primary_color = ?, text_color = ? WHERE id = ?')
            ->execute(['r;}a{', '#EEEEEE', $this->standaard]);
        ColorPaletteService::clearCache();

        self::assertSame(ThemeSettings::defaults()['primary_color'], ThemeSettings::get('primary_color'));
        self::assertSame('#EEEEEE', ThemeSettings::get('text_color'));
        self::assertStringNotContainsString('r;}a{', ThemeCss::styleBlock());
        self::assertSame(ThemeSettings::defaults()['primary_color'], ColorPaletteService::find($this->standaard)['primary_color']);
    }

    // --------------------------------------------------------- security

    public function testAnInvalidColourOrACssInjectionIsRefusedAndStoresNothing(): void
    {
        foreach (['blue', '#12345', 'url(https://x.test/a.png)', 'var(--x)', '#fff;}body{display:none', "#FFF\n}", 'expression(alert(1))'] as $bad) {
            $result = ColorPaletteService::validate(['name' => 'Kwaad'] + ['primary_color' => $bad] + ColorPaletteFixture::COLORS);
            self::assertArrayHasKey('primary_color', $result['errors'], $bad);
            self::assertArrayNotHasKey('primary_color', $result['values'], $bad);
        }

        // A name is data: stored as typed, never a selector, always escaped on output.
        $result = ColorPaletteService::validate(['name' => '</style><script>x</script>'] + ColorPaletteFixture::COLORS);
        self::assertSame([], $result['errors']);

        // Every colour is required: a palette is complete.
        $partial = ColorPaletteService::validate(['name' => 'Half', 'primary_color' => '#FFFFFF']);
        self::assertArrayHasKey('text_color', $partial['errors']);
    }

    public function testAnUnknownIdIsNobody(): void
    {
        self::assertNull(ColorPaletteService::find(0));
        self::assertNull(ColorPaletteService::find(-3));
        self::assertNull(ColorPaletteService::find(999999));
    }

    public function testTheContrastWarningIsCoresSharedRule(): void
    {
        $warnings = ThemeColor::contrastWarnings(['text_color' => '#777777', 'background_color' => '#808080'] + ThemeSettings::defaults());
        self::assertSame('text_color', $warnings[0]['foreground']);
        self::assertSame('background_color', $warnings[0]['background']);

        self::assertSame([], ThemeColor::contrastWarnings(ThemeSettings::defaults()));
        self::assertSame(ThemeColor::CONTRAST_PAIRS, \App\Service\PageThemes\PageThemeService::CONTRAST_PAIRS, 'a page theme checks the same pairs');
    }

    private function activeId(): ?int
    {
        return $this->activeIds()[0] ?? null;
    }

    /** @return list<int> */
    private function activeIds(): array
    {
        return array_map('intval', Database::connection()
            ->query('SELECT id FROM color_palettes WHERE is_active = 1 ORDER BY id')
            ->fetchAll(\PDO::FETCH_COLUMN));
    }
}
