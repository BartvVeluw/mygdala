<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\AssetVersion;
use App\Service\PageAssets;
use App\Service\SiteSettings;
use App\Service\Theme\ThemeDefinition;
use App\Service\Theme\ThemeRegistry;
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
        // reset() alone keeps an override, and a partial override switches
        // every module it does not name OFF for the rest of the run.
        ModuleRegistry::overrideForTests(null);
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
        ModuleRegistry::overrideForTests(['shop' => false, 'multilingual' => true]);
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
    /* The Global Theme slot                                               */
    /* ------------------------------------------------------------------ */

    /**
     * A site without a stored theme (every existing and every fresh
     * installation) prints exactly the stylesheets it printed before
     * ThemeRegistry: the collected links, and directly after the last one
     * whatever comes next — here nothing, on the shipped appearance.
     */
    public function testLegacyPrintsNothingInTheThemeSlot(): void
    {
        ThemeSettings::overrideForTests([]);
        PageAssets::requireStyle('assets/css/shop/shop.css');

        $html = $this->renderStyles();

        $this->assertStringEndsWith($this->collectedLinks(), $html);
        $this->assertSame(1, substr_count($html, $this->collectedLinks()));
        $this->assertStringNotContainsString('themes/', $html);
        $this->assertStringNotContainsString('legacy', $html);
    }

    public function testOnLegacyTheSiteThemeFollowsTheLastCollectedLinkDirectly(): void
    {
        ThemeSettings::overrideForTests(['primary_color' => '#2F6FED', ThemeSettings::ACTIVE_THEME_KEY => 'legacy']);

        $this->assertStringContainsString($this->collectedLinks() . '<style id="site-theme">', $this->renderStyles());
    }

    /** The theme is a layer of its own, not one of the collected assets. */
    public function testTheThemeDoesNotChangeTheCollectedAssets(): void
    {
        ThemeSettings::overrideForTests([]);
        PageAssets::requireStyle('assets/css/shop/shop.css');
        $before = PageAssets::collected();

        ThemeSettings::overrideForTests([ThemeSettings::ACTIVE_THEME_KEY => 'kobold']);
        $this->renderStyles();
        ThemeSettings::overrideForTests([ThemeSettings::ACTIVE_THEME_KEY => 'legacy']);
        $this->renderStyles();

        $this->assertSame($before, PageAssets::collected());
    }

    /**
     * Whatever the stored value is, it never reaches the page: not as a
     * stylesheet, not as a class or an attribute.
     */
    public function testAStoredValueNeverBecomesAPathOrAttribute(): void
    {
        foreach (['../../evil.css', 'assets/css/blocks/cta-band.css', 'kobold', '"><b>x</b>'] as $stored) {
            PageAssets::reset();
            ThemeSettings::overrideForTests([ThemeSettings::ACTIVE_THEME_KEY => $stored]);

            $html = $this->renderStyles();

            $this->assertStringNotContainsString('evil', $html);
            $this->assertStringNotContainsString('cta-band.css', $html);
            $this->assertStringNotContainsString('kobold', $html);
            $this->assertStringNotContainsString('<b>', $html);
            $this->assertStringEndsWith($this->collectedLinks(), $html);
        }
    }

    /**
     * The positive path, on the rendering half with an explicit definition:
     * the registry ships no theme with a stylesheet yet (phase 2C), and no
     * fixture theme goes into the release for a test.
     */
    public function testAThemeStylesheetIsPrintedCacheBustedFromItsDefinition(): void
    {
        $theme = new ThemeDefinition('proof', 'Proof', 'assets/css/core.css');

        $this->assertSame(
            '<link rel="stylesheet" href="/' . htmlspecialchars(AssetVersion::url('assets/css/core.css'), ENT_QUOTES, 'UTF-8') . '">' . "\n",
            PageAssets::themeStylesheet($theme)
        );
    }

    public function testAThemeWithoutAStylesheetPrintsNothing(): void
    {
        $this->assertSame('', PageAssets::themeStylesheet(ThemeRegistry::fallback()));
    }

    /** A definition that points at a file that is not there: a broken deploy. */
    public function testAMissingThemeStylesheetIsLoggedAndNotLinked(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'theme-log');
        $previous = ini_set('error_log', $log);

        try {
            $html = PageAssets::themeStylesheet(new ThemeDefinition('proof', 'Proof', 'assets/css/themes/not-shipped.css'));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $logged = (string) file_get_contents($log);
        @unlink($log);

        $this->assertSame('', $html);
        $this->assertStringContainsString('stylesheet of theme "proof" is not loadable', $logged);
    }

    /**
     * The cascade order in renderStyles(): every collected stylesheet, then
     * the Global Theme, then the owner's site theme, button styles and page
     * theme. Checked on the source because legacy prints nothing in the
     * slot, so no rendered page can show where it is yet.
     */
    public function testTheThemeSlotSitsAfterTheCollectedStylesAndBeforeTheOwnersSettings(): void
    {
        $method = new \ReflectionMethod(PageAssets::class, 'renderStyles');
        $lines = file((string) $method->getFileName());
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        $body = (string) preg_replace('#//[^\n]*#', '', str_replace("\r\n", "\n", $body));

        $order = [
            'fonts' => 'self::renderFontStylesheet()',
            'collected' => 'foreach (self::$styles',
            'global theme' => 'self::themeStylesheet(ThemeRegistry::active())',
            'site-theme' => 'ThemeCss::renderStyleBlock()',
            'site-buttons' => 'ButtonStyles::renderStyleBlock()',
            'page-theme' => 'PageThemeCss::renderStyleBlock()',
        ];

        $positions = [];
        foreach ($order as $name => $needle) {
            // Whole names only: ThemeCss:: must not match inside PageThemeCss::.
            $count = preg_match_all('/(?<![A-Za-z])' . preg_quote($needle, '/') . '/', $body, $matches, PREG_OFFSET_CAPTURE);
            $this->assertSame(1, $count, $name . ' must be called exactly once in renderStyles()');
            $positions[$name] = $matches[0][0][1];
        }

        $sorted = $positions;
        asort($sorted);
        $this->assertSame(array_keys($order), array_keys($sorted));
    }

    /**
     * The active theme reaches the page through PageAssets alone; nothing
     * else in the public site reads it (no body class, no data attribute).
     */
    public function testOnlyPageAssetsReadsTheActiveTheme(): void
    {
        $root = dirname(__DIR__, 2);
        $readers = [];

        $files = array_merge(
            glob($root . '/*.php') ?: [],
            glob($root . '/partials/*.php') ?: [],
            glob($root . '/admin/*.php') ?: [],
            glob($root . '/api/*/*.php') ?: [],
            $this->phpFilesUnder($root . '/src')
        );

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/ThemeRegistry::active\(|activeThemeKey\(/', $source) === 1) {
                $readers[] = str_replace('\\', '/', substr($file, strlen($root) + 1));
            }
        }
        sort($readers);

        $this->assertSame(
            ['src/Service/PageAssets.php', 'src/Service/Theme/ThemeRegistry.php', 'src/Service/Theme/ThemeSettings.php'],
            $readers
        );
    }

    /* ------------------------------------------------------------------ */

    /** The <link> lines of the collected stylesheets, as renderStyles() prints them. */
    private function collectedLinks(): string
    {
        $links = '';
        foreach (PageAssets::collected()['styles'] as $path) {
            $links .= '<link rel="stylesheet" href="/' . htmlspecialchars(AssetVersion::url($path), ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }

        return $links;
    }

    /** @return list<string> */
    private function phpFilesUnder(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

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
