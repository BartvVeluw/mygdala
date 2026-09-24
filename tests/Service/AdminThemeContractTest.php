<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AdminTheme;
use PHPUnit\Framework\TestCase;

/**
 * Static source-inspection guard (same technique as
 * tests/Service/AdminAccessControlTest.php) over the three promises the
 * dashboard themes rest on:
 *
 *  1. EVERY admin page inherits the theme. Not most of them, and not the
 *     ones somebody remembered: the list below is built by scanning
 *     admin/, so a screen added next month fails this test until its
 *     <body> carries the attribute.
 *  2. The theme keys live in ONE place. App\Service\AdminTheme is the
 *     registry; admin.css has one block per key and no others; no page,
 *     endpoint or script names a theme on its own.
 *  3. No theme ships half a palette. Every colour token :root declares is
 *     re-declared by every theme, so a component can never fall through to
 *     Default's warm charcoal inside Ocean.
 *
 * Deliberately not an HTML snapshot: this asserts the mechanism, not the
 * markup, and stays readable when a page is redesigned.
 */
final class AdminThemeContractTest extends TestCase
{
    /** The call every admin <body> is expected to print. */
    private const BODY_CALL = 'AdminTheme::bodyAttribute()';

    /**
     * Tokens that are structure rather than skin: a theme may re-point them
     * (Classic squares off its corners) but is not required to.
     */
    private const STRUCTURAL_TOKEN_PREFIXES = ['--admin-sp-', '--admin-radius-', '--admin-transition'];

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function adminCss(): string
    {
        return (string) file_get_contents(self::projectRoot() . '/admin/assets/admin.css');
    }

    /**
     * The same stylesheet with its comments removed. The comments explain
     * the theme mechanism and therefore quote its selectors; a scan for
     * "which themes exist" must read the rules, not the prose about them.
     */
    private static function adminCssRules(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', self::adminCss());
    }

    /**
     * Every admin script that opens a <body> of its own, mapped to that
     * line. Partials and the two scripts that emit no HTML simply have none.
     *
     * @return array<string, string>
     */
    private static function bodyTags(): array
    {
        $tags = [];

        foreach ((array) glob(self::projectRoot() . '/admin/*.php') as $path) {
            $source = (string) file_get_contents((string) $path);

            if (preg_match('/^<body[^>]*>/m', $source, $match) === 1) {
                $tags[basename((string) $path)] = $match[0];
            }
        }

        return $tags;
    }

    /**
     * Admin scripts whose <body> is deliberately NOT the CMS. The preview of a
     * page shows the public website exactly as a visitor would see it, so it
     * renders the site's own header, theme and footer (admin/page-preview.php,
     * PAGE-EDITOR.md); dressed in the dashboard theme it would be a preview of
     * something else. The preview of one content block is the same case, in
     * the library's frame (admin/block-preview.php), and so is the preview of
     * one form in the form editor's frame (admin/form-preview.php).
     */
    private const PUBLIC_SHELL_SCRIPTS = ['page-preview.php', 'block-preview.php', 'form-preview.php'];

    public function testEveryAdminPageInheritsTheThemeFromTheSamePlace(): void
    {
        $tags = self::bodyTags();

        // Guards the guard: a broken scan would otherwise pass silently.
        $this->assertGreaterThan(50, count($tags), 'The admin page scan found almost nothing.');

        foreach (self::PUBLIC_SHELL_SCRIPTS as $file) {
            $this->assertArrayHasKey($file, $tags, 'admin/' . $file . ' no longer opens a <body> of its own.');
            $this->assertStringNotContainsString(
                self::BODY_CALL,
                $tags[$file],
                'admin/' . $file . ' shows the website, so it must not wear the dashboard theme'
            );
            unset($tags[$file]);
        }

        foreach ($tags as $file => $tag) {
            $this->assertStringContainsString(
                self::BODY_CALL,
                $tag,
                'admin/' . $file . ' opens a <body> that does not carry the dashboard theme. '
                    . 'Print \App\Service\AdminTheme::bodyAttribute() in it.'
            );
        }
    }

    public function testTheLoginAndSetupScreensAreThemedToo(): void
    {
        $tags = self::bodyTags();

        foreach (['login.php', 'setup.php', '_forbidden.php'] as $file) {
            $this->assertArrayHasKey($file, $tags, 'admin/' . $file . ' no longer opens a <body>.');
            $this->assertStringContainsString(self::BODY_CALL, $tags[$file]);
        }
    }

    public function testAdminCssDefinesExactlyTheThemesTheRegistryKnows(): void
    {
        preg_match_all('/\[data-admin-theme="([a-z]+)"\]/', self::adminCssRules(), $matches);

        $inCss = $matches[1];

        $this->assertSame(
            array_values($inCss),
            array_values(array_unique($inCss)),
            'A theme is declared more than once in admin.css.'
        );

        $expected = AdminTheme::keys();
        sort($expected);
        sort($inCss);

        $this->assertSame(
            $expected,
            $inCss,
            'admin.css and App\Service\AdminTheme disagree about which themes exist.'
        );
    }

    public function testTheDefaultPaletteIsDeclaredOnceAndSharedWithRoot(): void
    {
        // Default IS :root, and a second copy of the same palette would be
        // free to drift, so the two share one block. The extra selector is
        // not redundant: without it an element marked `default` inside a
        // page running Ocean would come out blue — which is exactly what the
        // settings screen's four preview sketches are.
        $this->assertMatchesRegularExpression(
            '/:root,\s*\[data-admin-theme="default"\]\s*\{/',
            self::adminCssRules(),
            ':root and the default theme must share one palette block.'
        );
    }

    public function testNoThemeShipsHalfAPalette(): void
    {
        $css = self::adminCssRules();

        $rootTokens = self::tokensDeclaredIn($css, '/:root[^{]*\{(.*?)\}/s');
        $skinTokens = array_values(array_filter(
            $rootTokens,
            static function (string $token): bool {
                foreach (self::STRUCTURAL_TOKEN_PREFIXES as $prefix) {
                    if (str_starts_with($token, $prefix)) {
                        return false;
                    }
                }

                return true;
            }
        ));

        $this->assertGreaterThan(20, count($skinTokens), 'The :root token scan found almost nothing.');

        foreach (array_diff(AdminTheme::keys(), [AdminTheme::DEFAULT_KEY]) as $key) {
            $themeTokens = self::tokensDeclaredIn(
                $css,
                '/\[data-admin-theme="' . preg_quote($key, '/') . '"\]\s*\{(.*?)\}/s'
            );

            $missing = array_diff($skinTokens, $themeTokens);

            $this->assertSame(
                [],
                array_values($missing),
                'The "' . $key . '" theme does not re-point ' . implode(', ', $missing)
                    . ' and would fall through to the Default palette there.'
            );
        }
    }

    public function testNoTemplatePinsAThemeOfItsOwn(): void
    {
        $offenders = [];

        foreach (self::sourceFilesThatMayNotPinATheme() as $path) {
            $source = (string) file_get_contents($path);

            foreach (AdminTheme::keys() as $key) {
                if (str_contains($source, 'data-admin-theme="' . $key . '"')) {
                    $offenders[] = str_replace(self::projectRoot() . '/', '', $path)
                        . ' pins "' . $key . '"';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A theme belongs to the installation, not to a screen. Print '
                . '\App\Service\AdminTheme::bodyAttribute() instead of a fixed value: '
                . implode('; ', $offenders)
        );
    }

    public function testTheSettingsScreenOffersTheWholeRegistryAndNothingElse(): void
    {
        $source = (string) file_get_contents(self::projectRoot() . '/admin/settings.php');

        $this->assertStringContainsString('Dashboard uiterlijk', $source);
        $this->assertStringContainsString('AdminTheme::all()', $source);
        $this->assertStringContainsString('/api/admin/update-admin-theme.php', $source);
        $this->assertStringContainsString('name="admin_theme"', $source);
    }

    public function testTheSaveEndpointValidatesThroughTheRegistry(): void
    {
        $source = (string) file_get_contents(self::projectRoot() . '/api/admin/update-admin-theme.php');

        $this->assertStringContainsString('AdminTheme::normalise(', $source);
        $this->assertStringContainsString('AdminTheme::save(', $source);
        $this->assertStringContainsString("requirePermissionForApi('settings.manage')", $source);
        $this->assertStringContainsString('Csrf::validate(', $source);
    }

    public function testThePublicSiteKnowsNothingAboutDashboardThemes(): void
    {
        $offenders = [];

        $roots = [
            self::projectRoot() . '/assets/css',
            self::projectRoot() . '/partials',
            self::projectRoot() . '/src/Service/Theme',
        ];

        foreach ($roots as $root) {
            foreach (self::filesUnder($root) as $path) {
                if (str_contains((string) file_get_contents($path), 'data-admin-theme')) {
                    $offenders[] = $path;
                }
            }
        }

        $this->assertSame([], $offenders, 'The dashboard theme leaked into the public site.');
    }

    // --- Eigen kleuren ------------------------------------------------------

    public function testEigenKleurenReadsTheFiveStoredColoursWithDefaultAsFallback(): void
    {
        $css = self::adminCssRules();

        $this->assertSame(1, preg_match('/\[data-admin-theme="custom"\]\s*\{(.*?)\}/s', $css, $block));
        $this->assertSame(1, preg_match('/:root[^{]*\{(.*?)\}/s', $css, $root));

        preg_match_all(
            '/(--admin-[a-z-]+)\s*:\s*var\(--admin-custom-([a-z]+),\s*(#[0-9a-f]{6})\)/',
            $block[1],
            $inputs,
            PREG_SET_ORDER
        );

        $read = [];
        foreach ($inputs as [, $token, $name, $fallback]) {
            $read[] = $name;

            $this->assertArrayHasKey($name, AdminTheme::COLORS, $token . ' reads a colour the registry does not store');
            $this->assertSame(AdminTheme::COLORS[$name], $fallback, $token . ' falls back to something other than AdminTheme::COLORS');
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($token, '/') . '\s*:\s*' . preg_quote($fallback, '/') . '\s*;/',
                $root[1],
                $token . ' does not fall back to the Default theme\'s own value'
            );
        }

        $this->assertSame(array_keys(AdminTheme::COLORS), $read, 'every stored colour is read exactly once');
    }

    public function testEigenKleurenHardCodesNoColourTheOwnerCannotChange(): void
    {
        preg_match('/\[data-admin-theme="custom"\]\s*\{(.*?)\}/s', self::adminCssRules(), $block);
        preg_match_all('/(--admin-[a-z0-9-]+)\s*:\s*([^;]+);/', $block[1] ?? '', $declarations, PREG_SET_ORDER);

        $this->assertGreaterThan(20, count($declarations), 'The custom block scan found almost nothing.');

        foreach ($declarations as [, $token, $value]) {
            // Shadows and the modal backdrop are black in every theme.
            if (preg_match('/^--admin-(overlay|shadow-)/', $token) === 1) {
                continue;
            }

            $this->assertStringContainsString('var(--admin-', $value, $token . ' is a fixed colour in a theme whose colours are the owner\'s');
        }
    }

    public function testTheSettingsScreenOffersEigenKleurenWithALivePreview(): void
    {
        $source = (string) file_get_contents(self::projectRoot() . '/admin/settings.php');

        // The five colours from the registry, in the website's colour control.
        $this->assertStringContainsString('foreach (AdminTheme::COLORS as $colorName => $colorFallback)', $source);
        $this->assertStringContainsString('name="admin_theme_colors[<?= $h($colorName) ?>]"', $source);
        $this->assertStringContainsString('pattern="<?= $h(AdminTheme::COLOR_PATTERN) ?>"', $source);
        $this->assertStringContainsString('<input type="color" class="admin-theme-color__swatch"', $source);
        $this->assertStringContainsString('data-theme-color-for="<?= $h($colorId) ?>"', $source);
        $this->assertStringContainsString("AssetVersion::url('/admin/assets/theme-admin.js')", $source);
        $this->assertStringContainsString('AdminTheme::customProperties($customColors)', $source);

        // Previewed in the page, stored by the button that always stored it.
        $this->assertStringContainsString('data-admin-theme-form', $source);
        $this->assertStringContainsString('autocomplete="off" data-admin-theme-form', $source);
        $this->assertStringContainsString("AssetVersion::url('/admin/assets/admin-theme-preview.js')", $source);
        $this->assertStringContainsString("<button type=\"submit\"><?= admin_te('settings.uiterlijk_opslaan') ?></button>", $source);

        // The existing save bar says a preview is unsaved; there is no second mechanism.
        $this->assertStringContainsString("require_once __DIR__ . '/_save_bar.php';", $source);
        $this->assertStringContainsString('<?php save_bar(); ?>', $source);
        $this->assertStringContainsString('<?php save_bar_script(); ?>', $source);
    }

    public function testThePreviewScriptOnlyEverChangesThePageInFrontOfIt(): void
    {
        $script = (string) file_get_contents(self::projectRoot() . '/admin/assets/admin-theme-preview.js');
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $script);

        $this->assertStringContainsString('body.setAttribute("data-admin-theme"', $code);
        $this->assertStringContainsString('style.setProperty("--admin-custom-"', $code);
        $this->assertStringContainsString('getAttribute("pattern")', $code, 'a typed colour must match the pattern the server wrote');

        foreach (['fetch(', 'XMLHttpRequest', 'sendBeacon', 'localStorage', 'sessionStorage', 'document.cookie', '.submit(', 'requestSubmit', 'location', 'innerHTML'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, 'a preview must not save, send or remember anything: ' . $forbidden);
        }

        foreach (AdminTheme::keys() as $key) {
            $this->assertStringNotContainsString('"' . $key . '"', $code, 'the script names no theme: ' . $key);
        }

        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $code, $literals);
        foreach ($literals[1] as $literal) {
            $this->assertMatchesRegularExpression(
                '/^(?:use strict|[a-z-]*|\[data-[a-z-]+\]|#|\^\(\?:|\)\$)$/',
                $literal,
                'the script holds no palette and no sentence an editor reads: "' . $literal . '"'
            );
        }
    }

    public function testTheSaveEndpointTakesEigenKleurenOnlyAsACompleteValidSet(): void
    {
        $source = (string) file_get_contents(self::projectRoot() . '/api/admin/update-admin-theme.php');

        $this->assertStringContainsString("AdminTheme::normaliseColors(\$_POST['admin_theme_colors'] ?? null)", $source);
        $this->assertStringContainsString('AdminTheme::save($requested, $colors)', $source);
        $this->assertStringContainsString("validation.dashboard_colors_invalid", $source);
    }

    /**
     * The custom property names declared inside the first block the pattern
     * matches.
     *
     * @return list<string>
     */
    private static function tokensDeclaredIn(string $css, string $pattern): array
    {
        if (preg_match($pattern, $css, $block) !== 1) {
            return [];
        }

        preg_match_all('/(--admin-[a-z0-9-]+)\s*:/', $block[1], $tokens);

        return array_values(array_unique($tokens[1]));
    }

    /**
     * Everything that renders or handles the admin, minus the one stylesheet
     * that is allowed to name a theme because it is where the palettes live.
     *
     * @return list<string>
     */
    private static function sourceFilesThatMayNotPinATheme(): array
    {
        $files = array_merge(
            self::filesUnder(self::projectRoot() . '/admin'),
            self::filesUnder(self::projectRoot() . '/api'),
            self::filesUnder(self::projectRoot() . '/src'),
            self::filesUnder(self::projectRoot() . '/partials')
        );

        $palettes = self::projectRoot() . '/admin/assets/admin.css';

        return array_values(array_filter(
            $files,
            static fn (string $path): bool => $path !== $palettes
        ));
    }

    /**
     * @return list<string>
     */
    private static function filesUnder(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if (!in_array($file->getExtension(), ['php', 'css', 'js'], true)) {
                continue;
            }

            $found[] = str_replace('\\', '/', $file->getPathname());
        }

        sort($found);

        return $found;
    }
}
