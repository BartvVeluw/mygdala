<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Theme\ThemeCss;
use App\Service\Theme\ThemeFonts;
use App\Service\Theme\ThemePalette;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;

/**
 * The theme's defaults, its validation and the CSS it produces — all without
 * a database or a web server, via ThemeSettings::overrideForTests().
 *
 * The load-bearing claim these tests protect is the one the whole design
 * rests on: assets/css/core.css declares the default theme, so a site that
 * changed nothing must emit NO override block at all. If that ever stops
 * being true, the default site stops being byte-for-byte what it was.
 */
final class ThemeSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        ThemeSettings::overrideForTests(null);
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Defaults                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * The formal defaults are the values assets/css/core.css declares. Read
     * out of the stylesheet rather than copied here, so the two cannot drift
     * apart without this failing.
     */
    public function testTheDefaultsAreExactlyWhatCoreCssDeclares(): void
    {
        $root = $this->coreCssRootBlock();

        $expected = [
            'primary_color' => '--color-primary',
            'on_primary_color' => '--color-on-primary',
            'background_color' => '--color-bg',
            'surface_color' => '--color-surface',
            'text_color' => '--color-text',
        ];

        foreach ($expected as $settingKey => $property) {
            $this->assertSame(
                strtoupper(ThemeSettings::defaults()[$settingKey]),
                strtoupper($this->declaredValue($root, $property)),
                $property . ' in core.css must equal the ' . $settingKey . ' default'
            );
        }
    }

    public function testTheDefaultPairingIsTheSitesOriginalOne(): void
    {
        $pairing = ThemeFonts::pairing(ThemeSettings::defaults()['font_pairing']);

        $this->assertSame("'Trirong', Georgia, serif", $pairing['heading']);
        $this->assertStringStartsWith("'Quattrocento Sans'", $pairing['body']);
        $this->assertIsString($pairing['url']);
        $this->assertStringContainsString('family=Trirong', (string) $pairing['url']);
        $this->assertStringContainsString('family=Quattrocento+Sans', (string) $pairing['url']);
    }

    public function testTheDefaultButtonShapeIsThePillCoreCssShips(): void
    {
        // About the shipped default, so it must not read whatever this
        // installation happens to have stored.
        ThemeSettings::overrideForTests([]);

        $this->assertSame('pill', ThemeSettings::defaults()['button_shape']);
        $this->assertSame('999px', ThemeSettings::buttonRadius());
        $this->assertSame('999px', $this->declaredValue($this->coreCssRootBlock(), '--button-radius'));
    }

    public function testEveryDerivedPropertyThePaletteComputesAlsoHasADefaultInCoreCss(): void
    {
        $root = $this->coreCssRootBlock();

        $derived = ThemePalette::derive([
            'primary' => '#C9A063',
            'background' => '#120D09',
            'surface' => '#1C150E',
            'text' => '#F5EFE4',
        ]);

        foreach (array_keys($derived) as $property) {
            $this->assertMatchesRegularExpression(
                '/(^|\n)\s*' . preg_quote($property, '/') . '\s*:/',
                $root,
                $property . ' is computed server-side but has no default in core.css'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* No override for the default theme                                   */
    /* ------------------------------------------------------------------ */

    public function testTheDefaultThemeEmitsNoOverrideBlockAtAll(): void
    {
        ThemeSettings::overrideForTests([]);

        $this->assertTrue(ThemeSettings::isDefault());
        $this->assertSame([], ThemeSettings::changedKeys());
        $this->assertSame([], ThemeCss::declarations());
        $this->assertSame('', ThemeCss::styleBlock());
    }

    public function testOnlyWhatChangedIsEmitted(): void
    {
        ThemeSettings::overrideForTests(['background_color' => '#101820']);

        $declarations = ThemeCss::declarations();

        $this->assertSame('#101820', $declarations['--color-bg']);
        // Background-derived tints come with it...
        $this->assertArrayHasKey('--color-bg-deep', $declarations);
        $this->assertArrayHasKey('--color-scrim-rgb', $declarations);
        $this->assertArrayHasKey('--color-surface-2', $declarations);
        // ...but nothing that depends only on an unchanged colour.
        $this->assertArrayNotHasKey('--color-primary', $declarations);
        $this->assertArrayNotHasKey('--color-primary-bright', $declarations);
        $this->assertArrayNotHasKey('--color-text-rgb', $declarations);
        $this->assertArrayNotHasKey('--font-display', $declarations);
        $this->assertArrayNotHasKey('--button-radius', $declarations);
    }

    public function testChangingThePrimaryColourAlsoMovesEverythingDerivedFromIt(): void
    {
        ThemeSettings::overrideForTests(['primary_color' => '#2F6FED']);

        $declarations = ThemeCss::declarations();

        $this->assertSame('#2F6FED', $declarations['--color-primary']);
        $this->assertSame('47, 111, 237', $declarations['--color-primary-rgb']);
        $this->assertArrayHasKey('--color-primary-bright', $declarations);
        $this->assertArrayHasKey('--color-primary-deep', $declarations);
        $this->assertArrayHasKey('--color-surface-hover', $declarations);
        $this->assertNotSame($declarations['--color-primary-bright'], $declarations['--color-primary-deep']);
    }

    /* ------------------------------------------------------------------ */
    /* Validation                                                          */
    /* ------------------------------------------------------------------ */

    /** @return list<array{string, string}> */
    public static function validColorProvider(): array
    {
        return [
            ['#2F6FED', '#2F6FED'],
            ['#2f6fed', '#2F6FED'],
            ['2F6FED', '#2F6FED'],
            ['#abc', '#AABBCC'],
            ['  #2F6FED  ', '#2F6FED'],
        ];
    }

    /** @dataProvider validColorProvider */
    public function testValidColoursAreAcceptedAndNormalised(string $input, string $expected): void
    {
        $result = ThemeSettings::validate(['primary_color' => $input]);

        $this->assertSame([], $result['errors']);
        $this->assertSame($expected, $result['values']['primary_color']);
    }

    /** @return list<array{string}> */
    public static function rejectedColorProvider(): array
    {
        return [
            ['red'],
            ['rgb(1,2,3)'],
            ['var(--anything)'],
            ['url(https://example.com/x.png)'],
            ['calc(1px)'],
            ['#2F6FED; background: url(https://example.com/x)'],
            ['#2F6FE'],
            ['#2F6FEDD'],
            ['#GGGGGG'],
            ['</style><script>alert(1)</script>'],
            ['inherit'],
            [''],
        ];
    }

    /** @dataProvider rejectedColorProvider */
    public function testAnythingThatIsNotAHexColourIsRejected(string $input): void
    {
        $result = ThemeSettings::validate(['primary_color' => $input]);

        $this->assertArrayHasKey('primary_color', $result['errors']);
        $this->assertArrayNotHasKey('primary_color', $result['values']);
    }

    public function testAnUnknownFontPairingIsRejected(): void
    {
        $result = ThemeSettings::validate(['font_pairing' => 'https://fonts.googleapis.com/css2?family=Comic']);

        $this->assertArrayHasKey('font_pairing', $result['errors']);
        $this->assertArrayNotHasKey('font_pairing', $result['values']);
    }

    public function testEveryOfferedFontPairingIsAccepted(): void
    {
        foreach (ThemeFonts::keys() as $key) {
            $result = ThemeSettings::validate(['font_pairing' => $key]);

            $this->assertSame([], $result['errors'], $key . ' should be a valid pairing');
            $this->assertSame($key, $result['values']['font_pairing']);
        }
    }

    public function testAnUnknownButtonShapeIsRejected(): void
    {
        $result = ThemeSettings::validate(['button_shape' => '20px']);

        $this->assertArrayHasKey('button_shape', $result['errors']);
        $this->assertArrayNotHasKey('button_shape', $result['values']);
    }

    public function testKeysThatWereNotSubmittedAreLeftAlone(): void
    {
        $result = ThemeSettings::validate(['primary_color' => '#2F6FED']);

        $this->assertSame(['primary_color'], array_keys($result['values']));
    }

    public function testAStoredValueThatNoLongerValidatesFallsBackToTheDefault(): void
    {
        // overrideForTests() runs the same validation the reader does, so an
        // impossible value simply never reaches the effective settings.
        ThemeSettings::overrideForTests(['primary_color' => 'url(javascript:alert(1))']);

        $this->assertSame(ThemeSettings::defaults()['primary_color'], ThemeSettings::get('primary_color'));
        $this->assertSame([], ThemeCss::declarations());
    }

    /* ------------------------------------------------------------------ */
    /* Fonts and shape                                                     */
    /* ------------------------------------------------------------------ */

    public function testOnlyTheSelectedPairingsStylesheetIsEverAskedFor(): void
    {
        foreach (ThemeFonts::all() as $key => $pairing) {
            ThemeSettings::overrideForTests(['font_pairing' => $key]);

            $this->assertSame($pairing['url'], ThemeCss::fontStylesheetUrl(), $key);
        }
    }

    public function testTheSystemPairingDownloadsNothing(): void
    {
        ThemeSettings::overrideForTests(['font_pairing' => 'system']);

        $this->assertNull(ThemeCss::fontStylesheetUrl());
    }

    public function testEveryPairingUrlIsAGoogleFontsStylesheet(): void
    {
        foreach (ThemeFonts::all() as $key => $pairing) {
            if ($pairing['url'] === null) {
                continue;
            }

            $this->assertStringStartsWith('https://fonts.googleapis.com/css2?', $pairing['url'], $key);
            $this->assertStringContainsString('display=swap', $pairing['url'], $key);
        }
    }

    public function testTheRoundedShapeEmitsTheProjectsOwnRadiusToken(): void
    {
        ThemeSettings::overrideForTests(['button_shape' => 'rounded']);

        $this->assertSame('var(--radius-md)', ThemeSettings::buttonRadius());
        $this->assertSame('var(--radius-md)', ThemeCss::declarations()['--button-radius']);
    }

    /* ------------------------------------------------------------------ */
    /* The emitted block                                                   */
    /* ------------------------------------------------------------------ */

    public function testTheStyleBlockIsOneRootRuleWithAStableId(): void
    {
        ThemeSettings::overrideForTests(['primary_color' => '#2F6FED']);

        $block = ThemeCss::styleBlock();

        $this->assertStringStartsWith('<style id="site-theme">', $block);
        $this->assertStringContainsString(':root{', $block);
        $this->assertStringEndsWith("</style>\n", $block);
        $this->assertStringContainsString('--color-primary: #2F6FED;', $block);
        $this->assertSame(1, substr_count($block, '<style'));
        $this->assertSame(1, substr_count($block, ':root{'));
    }

    public function testThemeColourMetaFollowsTheEffectiveBackground(): void
    {
        ThemeSettings::overrideForTests([]);
        $this->assertSame('#120D09', ThemeCss::backgroundColor());

        ThemeSettings::overrideForTests(['background_color' => '#FFFFFF']);
        $this->assertSame('#FFFFFF', ThemeCss::backgroundColor());
    }

    /* ------------------------------------------------------------------ */
    /* The colour arithmetic                                               */
    /* ------------------------------------------------------------------ */

    public function testChannelsAreTheFormRgbaNeeds(): void
    {
        $this->assertSame('201, 160, 99', ThemePalette::channels('#C9A063'));
        $this->assertSame('255, 255, 255', ThemePalette::channels('#FFFFFF'));
        $this->assertSame('0, 0, 0', ThemePalette::channels('#000000'));
    }

    public function testMixingReturnsTheEndpointsAtTheEdges(): void
    {
        $this->assertSame('#C9A063', ThemePalette::mix('#C9A063', '#FFFFFF', 0.0));
        $this->assertSame('#FFFFFF', ThemePalette::mix('#C9A063', '#FFFFFF', 1.0));
        $this->assertSame('#808080', ThemePalette::mix('#000000', '#FFFFFF', 0.5));
    }

    public function testLightnessShiftsAreClampedRatherThanWrapping(): void
    {
        $this->assertSame('#FFFFFF', ThemePalette::shiftLightness('#C9A063', 1.0));
        $this->assertSame('#000000', ThemePalette::shiftLightness('#C9A063', -1.0));
    }

    public function testABrightAccentIsLighterAndADeepOneDarkerWhateverTheHue(): void
    {
        foreach (['#C9A063', '#2F6FED', '#1B7F4C', '#B02020', '#777777'] as $primary) {
            $derived = ThemePalette::derive([
                'primary' => $primary,
                'background' => '#120D09',
                'surface' => '#1C150E',
                'text' => '#F5EFE4',
            ]);

            $this->assertGreaterThan(
                $this->luminance($primary),
                $this->luminance($derived['--color-primary-bright']),
                $primary . ': the bright accent must be lighter than the accent'
            );
            $this->assertLessThan(
                $this->luminance($primary),
                $this->luminance($derived['--color-primary-deep']),
                $primary . ': the deep accent must be darker than the accent'
            );
        }
    }

    public function testAScrimStaysDarkEvenOnALightBackground(): void
    {
        $derived = ThemePalette::derive([
            'primary' => '#2F6FED',
            'background' => '#FFFFFF',
            'surface' => '#F4F4F6',
            'text' => '#101014',
        ]);

        [$r, $g, $b] = array_map('intval', explode(',', $derived['--color-scrim-rgb']));

        $this->assertLessThan(64, max($r, $g, $b), 'a lightbox backdrop must stay dark on any theme');
    }

    /* ------------------------------------------------------------------ */

    private function luminance(string $hex): float
    {
        [$r, $g, $b] = ThemePalette::rgb($hex);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private function coreCssRootBlock(): string
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/core.css');

        $matched = [];
        preg_match('/:root\{(.*?)\n\}/s', $css, $matched);

        $this->assertNotEmpty($matched, 'assets/css/core.css must declare a :root block');

        return $matched[1];
    }

    private function declaredValue(string $rootBlock, string $property): string
    {
        $matched = [];
        preg_match('/(?:^|\n)\s*' . preg_quote($property, '/') . '\s*:\s*([^;]+);/', $rootBlock, $matched);

        $this->assertNotEmpty($matched, $property . ' is not declared in the :root block of core.css');

        return trim($matched[1]);
    }
}
