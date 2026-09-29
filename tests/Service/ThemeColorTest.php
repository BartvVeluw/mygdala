<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\PageThemes\PageThemeService;
use App\Service\Theme\ThemeColor;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;

/**
 * The one rule for a theme colour and the one contrast formula, shared by
 * the site theme and the page themes (App\Service\Theme\ThemeColor), plus the
 * two pure pieces of the page-theme editor that build on them: its contrast
 * warning and the values of its live preview. No database, no web server.
 */
final class ThemeColorTest extends TestCase
{
    protected function tearDown(): void
    {
        ThemeSettings::overrideForTests(null);
    }

    public function testAColourHasOneCanonicalShape(): void
    {
        self::assertSame('#C9A063', ThemeColor::normalise('#c9a063'));
        self::assertSame('#C9A063', ThemeColor::normalise('C9A063'));
        self::assertSame('#FFAA00', ThemeColor::normalise(' #fa0 '));

        foreach (['red', 'url(x)', 'var(--color-bg)', '#C9A063;}body{', '#C9A06', '#GGGGGG', '', 'rgb(1,2,3)'] as $refused) {
            self::assertNull(ThemeColor::normalise($refused), $refused);
        }
    }

    public function testTheSiteThemeValidatesColoursThroughTheSameRule(): void
    {
        $result = ThemeSettings::validate(['primary_color' => '#fa0', 'text_color' => 'red;}body{']);

        self::assertSame('#FFAA00', $result['values']['primary_color']);
        self::assertArrayHasKey('text_color', $result['errors']);
    }

    public function testTheContrastRatioIsTheWcagOne(): void
    {
        self::assertEqualsWithDelta(21.0, ThemeColor::contrastRatio('#000000', '#FFFFFF'), 0.001);
        self::assertEqualsWithDelta(1.0, ThemeColor::contrastRatio('#C9A063', '#C9A063'), 0.001);
        self::assertEqualsWithDelta(4.48, ThemeColor::contrastRatio('#777777', '#FFFFFF'), 0.01);
        self::assertEqualsWithDelta(
            ThemeColor::contrastRatio('#FFFFFF', '#336699'),
            ThemeColor::contrastRatio('#336699', '#FFFFFF'),
            0.0001,
            'which one is the text does not matter'
        );
    }

    public function testTheWarningNamesExactlyThePairsBelowTheMinimum(): void
    {
        $readable = ['primary_color' => '#C9A063', 'on_primary_color' => '#1B140D', 'background_color' => '#120D09', 'surface_color' => '#1C150E', 'text_color' => '#F5EFE4'];
        self::assertSame([], PageThemeService::contrastWarnings($readable), 'the shipped theme is readable');

        $warnings = PageThemeService::contrastWarnings(['text_color' => '#777777', 'background_color' => '#888888'] + $readable);
        $pairs = array_map(static fn (array $w): string => $w['foreground'] . '/' . $w['background'], $warnings);

        self::assertContains('text_color/background_color', $pairs);
        self::assertNotContains('on_primary_color/primary_color', $pairs);
        foreach ($warnings as $warning) {
            self::assertLessThan(ThemeColor::MIN_TEXT_CONTRAST, $warning['ratio']);
        }
    }

    public function testThePreviewShowsWhatASaveWouldStoreAndTheSiteThemeForTheRest(): void
    {
        ThemeSettings::overrideForTests(['primary_color' => '#2F6FED', 'font_pairing' => 'system']);

        $values = PageThemeService::previewValues([
            'primary_color' => 'red;}body{',
            'background_color' => '#fff',
            'font_pairing' => 'comic-sans',
            'text_color' => ['#000000'],
        ]);

        self::assertSame('#2F6FED', $values['primary_color'], 'refused: the site theme\'s value');
        self::assertSame('#FFFFFF', $values['background_color'], 'accepted, in its canonical shape');
        self::assertSame('system', $values['font_pairing']);
        self::assertSame(ThemeSettings::get('text_color'), $values['text_color']);
        self::assertSame(PageThemeService::VALUE_FIELDS, array_keys($values));
    }

    public function testANewThemeStartsAsTheSiteTheme(): void
    {
        ThemeSettings::overrideForTests(['background_color' => '#FAFAFA', 'text_color' => '#111111']);

        $defaults = PageThemeService::defaults();

        self::assertSame('#FAFAFA', $defaults['background_color']);
        self::assertSame('#111111', $defaults['text_color']);
        self::assertSame(PageThemeService::VALUE_FIELDS, array_keys($defaults));
    }
}
