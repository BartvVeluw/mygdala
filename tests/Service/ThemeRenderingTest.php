<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\PageAssets;
use App\Service\SiteSettings;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;

/**
 * What actually reaches a page's <head>: the font stylesheet, the theme
 * override block, the theme-colour meta tag and the favicon link.
 *
 * These render the real partials and the real PageAssets, with the settings
 * faked in memory — no database, no web server.
 */
final class ThemeRenderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PageAssets::reset();
    }

    protected function tearDown(): void
    {
        ThemeSettings::overrideForTests(null);
        SiteSettings::overrideForTests(null);
        ModuleRegistry::reset();
        PageAssets::reset();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* The stylesheet block                                                */
    /* ------------------------------------------------------------------ */

    public function testTheDefaultThemeAddsNoStyleBlockToTheHead(): void
    {
        ThemeSettings::overrideForTests([]);

        $html = $this->renderStyles();

        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringContainsString('/assets/css/core.css', $html);
    }

    public function testAChangedThemeIsEmittedAfterEveryStylesheet(): void
    {
        ThemeSettings::overrideForTests(['primary_color' => '#2F6FED']);
        PageAssets::requireStyle('assets/css/shop/shop.css');

        $html = $this->renderStyles();

        $themeAt = strpos($html, '<style id="site-theme">');
        $coreAt = strpos($html, '/assets/css/core.css');
        $shopAt = strpos($html, '/assets/css/shop/shop.css');

        $this->assertIsInt($themeAt);
        $this->assertIsInt($coreAt);
        $this->assertIsInt($shopAt);
        $this->assertGreaterThan($coreAt, $themeAt, 'the theme must override core.css');
        $this->assertGreaterThan($shopAt, $themeAt, 'the theme must override a module stylesheet too');
        $this->assertStringContainsString('--color-primary: #2F6FED;', $html);
    }

    public function testTheButtonShapeReachesTheHeadAsATokenNotAPixelValue(): void
    {
        ThemeSettings::overrideForTests(['button_shape' => 'rounded']);

        $this->assertStringContainsString('--button-radius: var(--radius-md);', $this->renderStyles());
    }

    /* ------------------------------------------------------------------ */
    /* Fonts                                                               */
    /* ------------------------------------------------------------------ */

    public function testTheDefaultPairingLoadsExactlyTheStylesheetTheImportUsedTo(): void
    {
        ThemeSettings::overrideForTests([]);

        $html = $this->renderStyles();

        $this->assertStringContainsString('family=Trirong', $html);
        $this->assertStringContainsString('family=Quattrocento+Sans', $html);
        $this->assertSame(1, substr_count($html, 'fonts.googleapis.com/css2'));
    }

    public function testAnotherPairingLoadsItsOwnFontAndNotTheDefaultOne(): void
    {
        ThemeSettings::overrideForTests(['font_pairing' => 'playfair-source-sans']);

        $html = $this->renderStyles();

        $this->assertStringContainsString('family=Playfair+Display', $html);
        $this->assertStringNotContainsString('family=Trirong', $html);
        $this->assertSame(1, substr_count($html, 'fonts.googleapis.com/css2'));
    }

    public function testTheSystemPairingRequestsNoWebFontAtAll(): void
    {
        ThemeSettings::overrideForTests(['font_pairing' => 'system']);

        $html = $this->renderStyles();

        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);
    }

    public function testCoreCssNoLongerImportsAFontItself(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/core.css');

        // A comment may mention it; a real @import rule may not exist.
        $this->assertDoesNotMatchRegularExpression('/^\s*@import/m', $css);
    }

    /* ------------------------------------------------------------------ */
    /* The branding head                                                   */
    /* ------------------------------------------------------------------ */

    public function testTheThemeColourMetaUsesTheEffectiveBackground(): void
    {
        ThemeSettings::overrideForTests(['background_color' => '#101820']);

        $this->assertStringContainsString('<meta name="theme-color" content="#101820">', $this->renderBrandingHead());
    }

    public function testNoTemplateStillCarriesItsOwnThemeColourOrFavicon(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach ($this->publicTemplates($root) as $file) {
            if (str_ends_with($file, 'partials/head-branding.php')) {
                continue;
            }

            $source = (string) file_get_contents($file);

            if (str_contains($source, 'name="theme-color"') || str_contains($source, 'rel="icon"')) {
                $offenders[] = substr($file, strlen($root) + 1);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'theme-color and the favicon belong to partials/head-branding.php alone'
        );
    }

    public function testTheFaviconTypeFollowsTheConfiguredFile(): void
    {
        SiteSettings::overrideForTests(['favicon_path' => 'assets/images/branding/icon.svg']);
        $this->assertStringContainsString('type="image/svg+xml"', $this->renderBrandingHead());

        SiteSettings::overrideForTests(['favicon_path' => 'assets/images/branding/icon.ico']);
        $this->assertStringContainsString('type="image/x-icon"', $this->renderBrandingHead());

        SiteSettings::overrideForTests(['favicon_path' => 'assets/images/branding/icon.png']);
        $this->assertStringContainsString('type="image/png"', $this->renderBrandingHead());
    }

    public function testAnUnknownFaviconExtensionGetsNoTypeAttributeRatherThanAWrongOne(): void
    {
        SiteSettings::overrideForTests(['favicon_path' => 'assets/images/branding/icon.bin']);

        $html = $this->renderBrandingHead();

        $this->assertStringContainsString('rel="icon"', $html);
        $this->assertStringNotContainsString('type=', $html);
    }

    public function testNoFaviconMeansNoLinkAtAll(): void
    {
        SiteSettings::overrideForTests(['favicon_path' => '']);

        $this->assertStringNotContainsString('rel="icon"', $this->renderBrandingHead());
    }

    /* ------------------------------------------------------------------ */
    /* Modules                                                             */
    /* ------------------------------------------------------------------ */

    public function testTheThemeStillRendersWithTheShopSwitchedOff(): void
    {
        ModuleRegistry::overrideForTests(['shop' => false]);
        ThemeSettings::overrideForTests(['primary_color' => '#2F6FED']);

        $html = $this->renderStyles();

        $this->assertStringContainsString('<style id="site-theme">', $html);
        $this->assertStringContainsString('--color-primary: #2F6FED;', $html);
        $this->assertStringNotContainsString('/assets/css/shop/', $html);
    }

    public function testTheShopsOwnStylesheetsConsumeThemeTokensRatherThanBrandColours(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['shop/shop.css', 'shop/cart.css', 'shop/personalization.css'] as $file) {
            $css = (string) file_get_contents($root . '/assets/css/' . $file);

            $this->assertDoesNotMatchRegularExpression(
                '/var\(--(gold|cream|ink|line|surface|bg)[a-z0-9-]*\)/',
                $css,
                $file . ' still uses a brand-named token instead of a semantic one'
            );
            $this->assertMatchesRegularExpression('/var\(--color-/', $css, $file . ' should consume theme tokens');
        }
    }

    /* ------------------------------------------------------------------ */

    private function renderStyles(): string
    {
        ob_start();
        PageAssets::renderStyles();

        return (string) ob_get_clean();
    }

    private function renderBrandingHead(): string
    {
        ob_start();
        require dirname(__DIR__, 2) . '/partials/head-branding.php';

        return (string) ob_get_clean();
    }

    /** @return list<string> */
    private function publicTemplates(string $root): array
    {
        $files = array_merge(
            glob($root . '/*.php') ?: [],
            glob($root . '/partials/*.php') ?: []
        );

        return array_values($files);
    }
}
