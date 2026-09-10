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

    public function testEveryAdminPageInheritsTheThemeFromTheSamePlace(): void
    {
        $tags = self::bodyTags();

        // Guards the guard: a broken scan would otherwise pass silently.
        $this->assertGreaterThan(50, count($tags), 'The admin page scan found almost nothing.');

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
