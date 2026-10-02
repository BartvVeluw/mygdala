<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Install\FreshSiteCopyPolicy;
use App\Service\Theme\ButtonStyleCss;
use App\Service\Theme\ThemeCss;
use App\Service\Theme\ThemeDefinition;
use App\Service\Theme\ThemePalette;
use App\Service\Theme\ThemeRegistry;
use App\Update\Build\LineEndings;
use App\Update\Ownership;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a Global Theme stylesheet may contain, and where it lives
 * (THEMING.md, "Global Theme", "Het CSS-contract").
 *
 * A theme is printed after Core and the blocks and before the owner's own
 * layers (site-theme, site-buttons, page-theme). Those layers print only
 * what DIFFERS from the shipped design, so a token a theme sets would win
 * whenever the owner's choice happens to equal the shipped value. That is
 * why the forbidden lists below are computed from the classes that own the
 * values — ThemeCss, ThemePalette, ButtonStyleCss — rather than written out:
 * a palette role or a button word added there is forbidden here at once.
 *
 * Contract scans, not a CSS parser: comments are stripped, rules are split
 * on their braces (into @media), declarations on `;`. Enough for the shapes
 * a theme stylesheet has; a theme that needs more gets a sharper test.
 */
final class ThemeStylesheetContractTest extends TestCase
{
    /** Where a first-party theme's stylesheet lives: <key>.css, assets in <key>/. */
    private const THEME_DIRECTORY = 'assets/css/themes';

    /** Old names of the hero decoration and the surfaces. */
    private const RETIRED_NAMES = ['spark', 'laser', 'bg-soft', 'bg-forest'];

    /** Named colours a value might sneak in; transparent and currentColor stay allowed. */
    private const NAMED_COLOURS = ['white', 'black', 'red', 'green', 'blue', 'gold', 'silver', 'gray', 'grey', 'orange', 'yellow', 'purple', 'pink', 'brown', 'navy', 'teal'];

    /* ------------------------------------------------------------------ */
    /* Registry and files                                                  */
    /* ------------------------------------------------------------------ */

    public function testMinimalOwnsMinimalCss(): void
    {
        $this->assertSame(self::THEME_DIRECTORY . '/minimal.css', ThemeRegistry::find('minimal')?->stylesheet);
    }

    /**
     * Every first-party stylesheet is a file that is there, named after its
     * theme, in the theme folder. Phase 2B left this convention out of
     * ThemeDefinition on purpose (a Client Extension brings its own folder);
     * for Core it is a rule.
     */
    public function testEveryFirstPartyStylesheetIsAFileNamedAfterItsTheme(): void
    {
        $withStylesheet = 0;
        foreach (ThemeRegistry::all() as $key => $definition) {
            if ($definition->stylesheet === null) {
                continue;
            }
            $withStylesheet++;

            $this->assertSame(self::THEME_DIRECTORY . '/' . $key . '.css', $definition->stylesheet, $key);
            $this->assertTrue(ThemeDefinition::isValidStylesheet($definition->stylesheet), $key);
            $this->assertFileExists(self::root() . '/' . $definition->stylesheet, $key);
        }

        $this->assertGreaterThan(0, $withStylesheet, 'Core ships at least one theme with a stylesheet');
    }

    /**
     * No orphan: every file under the theme folder belongs to exactly one
     * registered theme — its stylesheet, or an asset in its own <key>/
     * folder. A file nobody links is dead weight in every release.
     */
    public function testEveryFileInTheThemeFolderBelongsToARegisteredTheme(): void
    {
        $stylesheets = [];
        foreach (ThemeRegistry::all() as $key => $definition) {
            if ($definition->stylesheet !== null) {
                $stylesheets[$definition->stylesheet] = $key;
            }
        }

        $files = $this->filesUnder(self::root() . '/' . self::THEME_DIRECTORY);
        $this->assertNotSame([], $files);

        foreach ($files as $relative) {
            if (isset($stylesheets[$relative])) {
                continue;
            }

            $owner = explode('/', substr($relative, strlen(self::THEME_DIRECTORY) + 1))[0];
            $this->assertArrayHasKey($owner, ThemeRegistry::all(), $relative . ' belongs to no registered theme');
            $this->assertNotSame($owner . '.css', basename($relative), $relative . ' is a stylesheet no definition names');
        }
    }

    /**
     * The stylesheet reaches a page through ThemeRegistry and PageAssets
     * only: no template, partial, screen, script or other class names it in
     * code.
     */
    public function testNoTemplateOrOtherClassNamesAThemeStylesheet(): void
    {
        $root = self::root();
        $files = array_merge(
            glob($root . '/*.php') ?: [],
            glob($root . '/partials/*.php') ?: [],
            glob($root . '/admin/*.php') ?: [],
            glob($root . '/api/*/*.php') ?: [],
            array_map(static fn (string $relative): string => $root . '/' . $relative, $this->filesUnder($root . '/src', 'php')),
            array_map(static fn (string $relative): string => $root . '/' . $relative, $this->filesUnder($root . '/assets/js', 'js'))
        );

        $naming = [];
        foreach ($files as $file) {
            // Code only: a docblock may explain the convention.
            $code = (string) preg_replace('#/\*.*?\*/|(?<!:)//[^\n]*#s', '', (string) file_get_contents($file));
            if (str_contains($code, 'css/themes/')) {
                $naming[] = str_replace('\\', '/', substr($file, strlen($root) + 1));
            }
        }

        $this->assertSame(['src/Service/Theme/ThemeRegistry.php'], $naming);
    }

    /**
     * A theme stylesheet is application code: shipped in every release,
     * replaced by the updater, carried into a fresh site, and text with LF
     * line endings. assets/css/** already is all of that; this pins it for
     * the theme folder.
     */
    #[DataProvider('themeStylesheets')]
    public function testAThemeStylesheetShipsWithTheApplication(string $key, string $path): void
    {
        $this->assertSame(Ownership::RELEASE, Ownership::classify($path), $key);
        $this->assertTrue(Ownership::isShipped($path), $key);
        $this->assertFalse(Ownership::isInstallationOwned($path), $key);
        $this->assertTrue(FreshSiteCopyPolicy::descendsInto(self::THEME_DIRECTORY), $key);
        $this->assertTrue(FreshSiteCopyPolicy::includes($path), $key);
        $this->assertSame(LineEndings::TEXT, LineEndings::classify($path), $key);
        $this->assertStringNotContainsString("\r", (string) file_get_contents(self::root() . '/' . $path), $key . ' is stored with LF');
    }

    /* ------------------------------------------------------------------ */
    /* What a theme stylesheet never declares                               */
    /* ------------------------------------------------------------------ */

    /**
     * The palette is the owner's: the five chosen colours, everything
     * ThemePalette derives from them, and every other colour token (the
     * alpha roles: lines, washes, muted text, the glow). The shadow tint is
     * the one colour token Core hands to a theme.
     */
    #[DataProvider('themeStylesheets')]
    public function testAThemeSetsNoPaletteToken(string $key, string $path): void
    {
        $owned = array_values(ThemeCss::DIRECT);
        $owned = array_merge($owned, array_keys(ThemePalette::derive([
            'primary' => '#C9A063',
            'background' => '#120D09',
            'surface' => '#1C150E',
            'text' => '#F5EFE4',
        ])));
        foreach (ThemePalette::dependencies() as $properties) {
            $owned = array_merge($owned, $properties);
        }

        foreach ($this->declaredProperties($path) as $property) {
            $this->assertNotContains($property, $owned, $key . ' sets the palette token ' . $property);
            if (str_starts_with($property, '--color-')) {
                $this->assertSame('--color-shadow-rgb', $property, $key . ' sets the colour token ' . $property);
            }
        }
    }

    /** Fonts are the owner's: the pairing, the Font Library, a page theme. */
    #[DataProvider('themeStylesheets')]
    public function testAThemeSetsNoFont(string $key, string $path): void
    {
        $css = self::css($path);

        $this->assertStringNotContainsStringIgnoringCase('@font-face', $css, $key);

        foreach (self::rules($css) as $rule) {
            foreach (self::declarations($rule['body']) as [$property, $value]) {
                $this->assertNotContains($property, ['--font-display', '--font-body'], $key . ' sets ' . $property);
                if ($property === 'font-family') {
                    $this->assertMatchesRegularExpression('/^(var\(--font-(display|body)\)|inherit)$/', $value, $key . ' names a font family: ' . $value);
                }
                if ($property === 'font') {
                    $this->assertSame('inherit', $value, $key . ' sets a font shorthand');
                }
            }
        }
    }

    /**
     * Buttons are the owner's (Button Styles 2.0): no --btn-*, no
     * --button-radius, no rule on a button — and none of the shared tokens
     * a button style's words resolve to. The shipped primary button's hover
     * is var(--color-glow), and "Afgerond" is var(--radius-md): a theme that
     * rescaled those would restyle buttons the owner chose.
     */
    #[DataProvider('themeStylesheets')]
    public function testAThemeSetsNothingAButtonStyleResolvesTo(string $key, string $path): void
    {
        $resolved = self::buttonStyleTokens();

        foreach ($this->declaredProperties($path) as $property) {
            $this->assertStringStartsNotWith('--btn-', $property, $key);
            $this->assertNotContains($property, $resolved, $key . ' sets ' . $property . ', which a button style resolves to');
        }

        foreach (self::selectors(self::css($path)) as $selector) {
            $this->assertDoesNotMatchRegularExpression('/\.btn(?![a-z_])|\.btn--|\.btn-style-/', $selector, $key . ' styles a button: ' . $selector);
        }
    }

    public function testTheButtonStyleTokensAreReallyFoundInTheButtonVocabulary(): void
    {
        $tokens = self::buttonStyleTokens();

        foreach (['--button-radius', '--radius-sm', '--radius-md', '--radius-lg', '--shadow-soft', '--shadow-lift', '--color-glow', '--font-body', '--font-display'] as $expected) {
            $this->assertContains($expected, $tokens);
        }
    }

    /**
     * The file parses as the rules it seems to hold. A `*` followed by a `/`
     * inside a comment ends it early, and the prose after it swallows the
     * next rule without a single error (it happened: a banner that said
     * "the duration/hover tokens" with a star before the slash cost minimal
     * its whole :root rule). So: comment markers pair up, and every
     * selector is built from element names, classes, attributes and
     * pseudo-classes — never from words.
     */
    #[DataProvider('themeStylesheets')]
    public function testAThemeStylesheetHoldsOnlyRealRules(string $key, string $path): void
    {
        $raw = (string) file_get_contents(self::root() . '/' . $path);
        $this->assertSame(substr_count($raw, '/*'), substr_count($raw, '*/'), $key . ': a comment closes early or never');

        $elements = ['html', 'body', 'main', 'header', 'footer', 'nav', 'section', 'article', 'aside', 'div', 'span', 'a', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'figure', 'img', 'svg', 'strong', 'em', 'time', 'button', 'input', 'select', 'textarea', 'label', 'form', 'fieldset', 'legend'];

        foreach (self::selectors(self::css($path)) as $selector) {
            $flat = $selector;
            do {
                $flat = (string) preg_replace('/\([^()]*\)/', '', $flat, -1, $count);
            } while ($count > 0);

            foreach (preg_split('/\s*[ >+~]\s*/', trim($flat)) ?: [] as $compound) {
                $this->assertMatchesRegularExpression('/^([a-z][a-z0-9]*)?([.:\[][^ ]*)?$/', $compound, $key . ': not a selector: ' . $selector);
                $element = (string) preg_replace('/[.:\[].*$/', '', $compound);
                if ($element !== '') {
                    $this->assertContains($element, $elements, $key . ': "' . $element . '" is not an element in ' . $selector);
                }
            }
        }
    }

    #[DataProvider('themeStylesheets')]
    public function testNoImportantNoImportAndNoForeignUrl(string $key, string $path): void
    {
        $css = self::css($path);

        $this->assertStringNotContainsString('!important', $css, $key);
        $this->assertStringNotContainsStringIgnoringCase('@import', $css, $key);

        preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $css, $matches);
        foreach ($matches[2] as $url) {
            $this->assertMatchesRegularExpression('#^' . preg_quote($key, '#') . '/[A-Za-z0-9_/-]+\.(svg|png|webp|woff2)\z#', $url, $key . ': a theme asset is a relative path into its own folder');
            $this->assertStringNotContainsString('..', $url, $key);
            $this->assertFileExists(self::root() . '/' . self::THEME_DIRECTORY . '/' . $url, $key);
        }
    }

    /** Every colour is a token: no hex, no literal rgb/hsl, no named colour. */
    #[DataProvider('themeStylesheets')]
    public function testAThemeWritesNoLiteralColour(string $key, string $path): void
    {
        foreach (self::rules(self::css($path)) as $rule) {
            foreach (self::declarations($rule['body']) as [$property, $value]) {
                $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $value, $key . ' ' . $property);
                $this->assertDoesNotMatchRegularExpression('/\b(rgba?|hsla?)\(\s*(?!var\()/i', $value, $key . ' ' . $property . ': ' . $value);
                $this->assertDoesNotMatchRegularExpression('/\b(' . implode('|', self::NAMED_COLOURS) . ')\b/i', $value, $key . ' ' . $property . ': ' . $value);
            }
        }
    }

    /**
     * Motion is the tokens' (--dur-*, --ease-*, --hover-*): Core's
     * prefers-reduced-motion rules switch off transitions on the elements
     * themselves, and a transition or animation a theme declared later would
     * win over them.
     */
    #[DataProvider('themeStylesheets')]
    public function testAThemeMovesNothingOfItsOwn(string $key, string $path): void
    {
        $css = self::css($path);

        $this->assertStringNotContainsStringIgnoringCase('@keyframes', $css, $key);
        foreach (self::rules($css) as $rule) {
            foreach (self::declarations($rule['body']) as [$property]) {
                $this->assertDoesNotMatchRegularExpression('/^(transition|animation)(-|$)/', $property, $key . ' declares ' . $property . ' on ' . $rule['selector']);
            }
        }
    }

    /**
     * A theme is for every site and every page: no site or customer name,
     * no page theme by name, no id, no block or page instance, and none of
     * the retired decoration or surface names.
     */
    #[DataProvider('themeStylesheets')]
    public function testAThemeKnowsNoSitePageThemeOrInstance(string $key, string $path): void
    {
        $css = self::css($path);

        $this->assertSame([], FreshSiteCopyPolicy::reviewNeedlesIn((string) file_get_contents(self::root() . '/' . $path)), $key);

        foreach (self::selectors($css) as $selector) {
            $this->assertStringNotContainsString('#', $selector, $key . ' uses an id: ' . $selector);
            $this->assertDoesNotMatchRegularExpression('/data-page-theme\s*[~|^$*]?=/', $selector, $key . ' names a page theme: ' . $selector);
            $this->assertDoesNotMatchRegularExpression('/\[[^\]]*id\b[^\]]*\]/i', $selector, $key . ' selects an instance: ' . $selector);
            $this->assertDoesNotMatchRegularExpression('/\.[A-Za-z_-]*\d/', $selector, $key . ' selects a numbered class: ' . $selector);
            $this->assertStringNotContainsString('block-appearance', $selector, $key . ' styles Extra vormgeving: ' . $selector);
            foreach (self::RETIRED_NAMES as $retired) {
                $this->assertStringNotContainsString($retired, $selector, $key . ' uses the retired name ' . $retired);
            }
        }
    }

    /**
     * A theme refines the tokens Core has; it invents none. A new token is
     * a Core decision (THEMING.md, "Presentatietokens").
     */
    #[DataProvider('themeStylesheets')]
    public function testAThemeOnlySetsTokensCoreDeclares(string $key, string $path): void
    {
        $core = [];
        foreach (self::rules(self::css('assets/css/core.css')) as $rule) {
            if (in_array($rule['selector'], [':root', ':root, main[data-page-theme]'], true)) {
                foreach (self::declarations($rule['body']) as [$property]) {
                    $core[] = $property;
                }
            }
        }

        foreach ($this->declaredProperties($path) as $property) {
            if (str_starts_with($property, '--')) {
                $this->assertContains($property, $core, $key . ' invents ' . $property);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Cascade                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Extra vormgeving wins over a surface role because it is two classes
     * and the role one. So a theme styles a role on that one class: a
     * selector whose subject is the role is the role's class and nothing
     * more (a pseudo-element aside), and a selector that reaches inside a
     * role starts from that one class.
     */
    #[DataProvider('themeStylesheets')]
    public function testASurfaceRoleIsStyledOnItsOneClass(string $key, string $path): void
    {
        $surfaceSelectors = 0;
        foreach (self::selectors(self::css($path)) as $selector) {
            if (!str_contains($selector, 'surface-')) {
                continue;
            }
            $surfaceSelectors++;

            $compounds = preg_split('/\s*[ >+~]\s*/', trim((string) preg_replace('/::?(before|after)\b/', '', $selector))) ?: [];
            foreach ($compounds as $index => $compound) {
                if (!str_contains($compound, 'surface-')) {
                    continue;
                }
                $this->assertMatchesRegularExpression('/^\.surface-[a-z]+$/', $compound, $key . ': ' . $selector);
                $this->assertSame(0, $index, $key . ': a role is styled from the role, not from an ancestor: ' . $selector);
            }
        }

        if ($key === 'minimal') {
            $this->assertGreaterThan(0, $surfaceSelectors, 'minimal styles the surface roles');
        }
    }

    /**
     * Core declares its var()-built tokens on `:root, main[data-page-theme]`
     * so they recompute with a page theme's colours inside its <main>. A
     * theme that restates one of them does so on the same pair, or a themed
     * <main> falls back to Core's value. The ground follows the same rule:
     * a theme that repaints `body` repaints a themed <main> with it.
     */
    #[DataProvider('themeStylesheets')]
    public function testVarBuiltTokensAndTheGroundAreRestatedInsideAPageTheme(string $key, string $path): void
    {
        $paired = [];
        foreach (self::rules(self::css('assets/css/core.css')) as $rule) {
            if ($rule['selector'] === ':root, main[data-page-theme]') {
                foreach (self::declarations($rule['body']) as [$property]) {
                    $paired[] = $property;
                }
            }
        }
        $this->assertContains('--surface-subtle', $paired);

        foreach (self::rules(self::css($path)) as $rule) {
            $list = array_map('trim', explode(',', $rule['selector']));

            foreach (self::declarations($rule['body']) as [$property]) {
                if (in_array($property, $paired, true)) {
                    $this->assertContains(':root', $list, $key . ' ' . $property);
                    $this->assertContains('main[data-page-theme]', $list, $key . ' restates ' . $property . ' on :root only');
                }
            }

            if (in_array('body', $list, true)) {
                $this->assertContains('main[data-page-theme]', $list, $key . ' repaints body without a themed <main>');
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** @return array<string, array{string, string}> */
    public static function themeStylesheets(): array
    {
        $out = [];
        foreach (ThemeRegistry::all() as $key => $definition) {
            if ($definition->stylesheet !== null) {
                $out[$key] = [$key, $definition->stylesheet];
            }
        }

        return $out;
    }

    /**
     * Every custom property a button style can end up reading: the ones in
     * the shipped buttons' declarations and in every word of the button
     * vocabulary, plus the website's button shape.
     *
     * @return list<string>
     */
    private static function buttonStyleTokens(): array
    {
        $sources = [
            ButtonStyleCss::COLORS,
            ButtonStyleCss::TEXT_COLORS,
            ButtonStyleCss::SHAPES,
            ButtonStyleCss::SHADOWS,
            ButtonStyleCss::HOVERS,
            ButtonStyleCss::FONTS,
            ButtonStyleCss::LEGACY_PRIMARY,
            ButtonStyleCss::LEGACY_SECONDARY_OVERRIDES,
            [ButtonStyleCss::PRIMARY_GRADIENT],
        ];

        $text = json_encode($sources, JSON_THROW_ON_ERROR);
        preg_match_all('/var\((--[a-z0-9-]+)/', $text, $matches);

        $tokens = array_values(array_unique(array_merge($matches[1], ['--button-radius'])));
        sort($tokens);

        return $tokens;
    }

    /** @return list<string> every property the stylesheet declares, custom or not */
    private function declaredProperties(string $path): array
    {
        $properties = [];
        foreach (self::rules(self::css($path)) as $rule) {
            foreach (self::declarations($rule['body']) as [$property]) {
                $properties[] = $property;
            }
        }

        return $properties;
    }

    /** The stylesheet without comments, with LF line endings. */
    private static function css(string $path): string
    {
        $css = str_replace("\r\n", "\n", (string) file_get_contents(self::root() . '/' . $path));

        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /**
     * Every rule as [selector, body], with the selector list normalised to
     * `a, b`; the rules inside an @media or @supports are taken as they are.
     *
     * @return list<array{selector: string, body: string}>
     */
    private static function rules(string $css): array
    {
        $rules = [];
        $length = strlen($css);
        $start = 0;
        $depth = 0;
        $prelude = '';
        $bodyStart = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];
            if ($char === '{') {
                if ($depth === 0) {
                    $prelude = trim(substr($css, $start, $i - $start));
                    $bodyStart = $i + 1;
                }
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $body = substr($css, $bodyStart, $i - $bodyStart);
                    if (str_starts_with($prelude, '@media') || str_starts_with($prelude, '@supports')) {
                        $rules = array_merge($rules, self::rules($body));
                    } elseif (!str_starts_with($prelude, '@')) {
                        $selector = implode(', ', array_map(
                            static fn (string $part): string => (string) preg_replace('/\s+/', ' ', trim($part)),
                            explode(',', $prelude)
                        ));
                        $rules[] = ['selector' => $selector, 'body' => $body];
                    }
                    $start = $i + 1;
                }
            }
        }

        return $rules;
    }

    /** @return list<string> every single selector, each list split */
    private static function selectors(string $css): array
    {
        $selectors = [];
        foreach (self::rules($css) as $rule) {
            foreach (explode(', ', $rule['selector']) as $selector) {
                $selectors[] = $selector;
            }
        }

        return $selectors;
    }

    /** @return list<array{string, string}> [property, value] */
    private static function declarations(string $body): array
    {
        $out = [];
        foreach (explode(';', $body) as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                continue;
            }
            $out[] = [trim(substr($declaration, 0, $colon)), trim((string) preg_replace('/\s+/', ' ', substr($declaration, $colon + 1)))];
        }

        return $out;
    }

    /** @return list<string> project-relative paths, sorted */
    private function filesUnder(string $directory, ?string $extension = null): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $root = self::root();
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($extension !== null && $file->getExtension() !== $extension) {
                continue;
            }
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
        sort($files);

        return $files;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
