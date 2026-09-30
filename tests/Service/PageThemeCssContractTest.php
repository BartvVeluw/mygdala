<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\PageAssets;
use App\Service\Theme\PageAppearance;
use App\Service\Theme\PageThemeCss;
use App\Service\Theme\ThemeFonts;
use App\Service\Theme\ThemePalette;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;

/**
 * Page Themes 1.0, the Core half (THEMING.md, "Paginathema's"): what a page
 * theme can put into a page, and where.
 *
 *   - core.css recomputes every var()-based token inside a themed <main>,
 *     paints that <main> like the body, and forces the header's veil above
 *     it;
 *   - every template that renders a `pages` row prints the attribute on its
 *     <main>, and the product and project pages cannot;
 *   - an appearance is all or nothing, complete (five colours, every
 *     ThemePalette tint, both font stacks), and a tampered value never
 *     becomes CSS;
 *   - the fonts are one deduplicated set, and a page without a theme gets
 *     exactly the head it always had.
 *
 * No database and no web server (tier `contract`): the settings are faked in
 * memory, the appearance is made directly. What the module stores and what
 * a real page renders is Tests\Module\PageThemesRenderingHttpTest.
 */
final class PageThemeCssContractTest extends TestCase
{
    /** The seven templates that include partials/page-head.php, plus the editor's preview. */
    private const PAGE_TEMPLATES = [
        'index.php', 'contact.php', 'diensten.php', 'over-mij.php', 'portfolio.php', 'shop.php', 'pagina.php',
        'admin/page-preview.php',
    ];

    private const COLORS = [
        'primary_color' => '#FF7518',
        'on_primary_color' => '#111111',
        'background_color' => '#1A0F1F',
        'surface_color' => '#2A1A30',
        'text_color' => '#F7F1E8',
    ];

    protected function setUp(): void
    {
        PageAssets::reset();
    }

    protected function tearDown(): void
    {
        ThemeSettings::overrideForTests(null);
        // reset() alone keeps an override, and a partial override switches
        // every module it does not name OFF for the rest of the run.
        ModuleRegistry::overrideForTests(null);
        PageAssets::reset();
    }

    private static function source(string $path): string
    {
        $file = dirname(__DIR__, 2) . '/' . $path;
        self::assertFileExists($file);

        return str_replace("\r\n", "\n", (string) file_get_contents($file));
    }

    /**
     * Every rule of core.css whose selector list is exactly $selector, with
     * comments removed.
     *
     * @return list<string> rule bodies
     */
    private static function rules(string $selector): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', self::source('assets/css/core.css')) ?? '';
        // A rule at the top level starts at the beginning of the file or right
        // after the previous rule's closing brace (the look-behind leaves that
        // brace to the previous match). The first rule inside an @media block
        // follows a `{` and is deliberately not matched.
        preg_match_all('/(?:^|(?<=\}))\s*([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);

        $bodies = [];
        foreach ($matches as $match) {
            $selectors = preg_replace('/\s+/', ' ', trim($match[1]));
            if ($selectors === $selector) {
                $bodies[] = $match[2];
            }
        }

        return $bodies;
    }

    /** @return array<string, string> custom property => value */
    private static function declarations(string $body): array
    {
        preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/', $body, $matches, PREG_SET_ORDER);

        $out = [];
        foreach ($matches as $match) {
            $out[$match[1]] = preg_replace('/\s+/', ' ', trim($match[2])) ?? '';
        }

        return $out;
    }

    private function appearance(string $slug = 'halloween', string $font = 'playfair-source-sans'): PageAppearance
    {
        $appearance = PageAppearance::fromTheme($slug, self::COLORS, $font);
        self::assertNotNull($appearance);

        return $appearance;
    }

    private function renderStyles(): string
    {
        ob_start();
        PageAssets::renderStyles();

        return (string) ob_get_clean();
    }

    /* ------------------------------------------------------------------ */
    /* core.css                                                            */
    /* ------------------------------------------------------------------ */

    public function testEveryVarBasedTokenOfRootIsRecomputedInsideAThemedMain(): void
    {
        $shared = self::rules(':root, main[data-page-theme]');
        self::assertCount(1, $shared, 'core.css has one `:root, main[data-page-theme]` rule');
        $sharedTokens = self::declarations($shared[0]);

        $rootOnly = [];
        foreach (self::rules(':root') as $body) {
            $rootOnly += self::declarations($body);
        }
        self::assertNotSame([], $rootOnly, 'core.css still declares the theme on :root');

        foreach ($rootOnly as $property => $value) {
            self::assertStringNotContainsString(
                'var(',
                $value,
                "{$property} is built from other tokens but declared on :root alone, so it would keep the site "
                    . 'theme\'s colours inside a page theme: move it into the `:root, main[data-page-theme]` rule'
            );
        }

        foreach (['--color-primary-wash', '--color-text-muted', '--color-text-faint', '--color-line', '--color-line-strong', '--color-line-soft', '--color-glow'] as $alias) {
            self::assertArrayHasKey($alias, $sharedTokens, $alias);
            self::assertStringContainsString('var(', $sharedTokens[$alias], $alias);
        }
    }

    public function testAThemedMainIsPaintedLikeTheBodyAndTheHeaderWearsItsVeil(): void
    {
        $ground = self::rules('body, main[data-page-theme]');
        self::assertCount(1, $ground);
        foreach (['background:', 'background-attachment: fixed', 'color: var(--color-text)', 'font-family: var(--font-body)'] as $needle) {
            self::assertStringContainsString($needle, $ground[0]);
        }

        $css = self::source('assets/css/core.css');
        self::assertMatchesRegularExpression('/@media \(max-width: 900px\)\{\s*body,\s*main\[data-page-theme\]\{ background-attachment: scroll; \}/', $css);

        $veil = self::rules('.site-header.is-scrolled, body:has(> main[data-page-theme]) .site-header');
        self::assertCount(1, $veil, 'the scrolled veil and the themed-page veil are one rule');
        self::assertStringContainsString('background: rgba(var(--color-bg-veil-rgb), 0.86)', $veil[0]);
    }

    /* ------------------------------------------------------------------ */
    /* Templates                                                           */
    /* ------------------------------------------------------------------ */

    public function testEveryTemplateThatRendersAPagePrintsTheAttributeOnItsMain(): void
    {
        foreach (self::PAGE_TEMPLATES as $template) {
            $source = self::source($template);

            self::assertStringContainsString('partials/page-head.php', $source, $template);
            self::assertSame(1, substr_count($source, '<main id="main"'), $template);
            self::assertStringContainsString(
                '<main id="main"<?= \App\Service\Theme\PageThemeCss::mainAttribute() ?>>',
                $source,
                "{$template} must print the page theme attribute on its <main>"
            );
        }

        self::assertStringContainsString(
            '\App\Service\Theme\PageThemeCss::declareForPage($page ?? null);',
            self::source('partials/page-head.php')
        );
    }

    public function testNoOtherPublicTemplateIncludesThePageHeadOrPrintsTheAttribute(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ((array) glob($root . '/*.php') as $file) {
            $template = basename((string) $file);
            if (in_array($template, self::PAGE_TEMPLATES, true)) {
                continue;
            }

            $source = self::source($template);
            self::assertStringNotContainsString('partials/page-head.php', $source, $template);
            self::assertStringNotContainsString('PageThemeCss', $source, "{$template} must not carry a page theme");
        }

        // The holders of a content page, named because they matter most.
        foreach (['product.php', 'portfolio-detail.php'] as $holder) {
            self::assertStringNotContainsString('data-page-theme', self::source($holder), $holder);
        }
    }

    /* ------------------------------------------------------------------ */
    /* The appearance and its block                                        */
    /* ------------------------------------------------------------------ */

    public function testTheBlockCarriesTheCompleteTokenSetScopedToTheThemedMain(): void
    {
        $appearance = $this->appearance();
        $declarations = $appearance->declarations();

        foreach (['--color-primary', '--color-on-primary', '--color-bg', '--color-surface', '--color-text'] as $source) {
            self::assertArrayHasKey($source, $declarations);
        }

        $derived = ThemePalette::derive([
            'primary' => '#FF7518', 'background' => '#1A0F1F', 'surface' => '#2A1A30', 'text' => '#F7F1E8',
        ]);
        foreach ($derived as $property => $value) {
            self::assertSame($value, $declarations[$property] ?? null, $property . ' is ThemePalette\'s own value');
        }

        $pairing = ThemeFonts::pairing('playfair-source-sans');
        self::assertSame($pairing['heading'], $declarations['--font-display']);
        self::assertSame($pairing['body'], $declarations['--font-body']);
        self::assertCount(5 + count($derived) + 2, $declarations);
        self::assertArrayNotHasKey('--button-radius', $declarations, 'the button shape stays the site\'s');

        $block = PageThemeCss::styleBlock($appearance);
        self::assertStringStartsWith('<style id="page-theme">' . "\n" . 'main[data-page-theme="halloween"]{' . "\n", $block);
        self::assertStringEndsWith("}\n</style>\n", $block);
        self::assertStringContainsString('  --color-primary: #FF7518;', $block);
        self::assertSame(1, substr_count($block, '{'));
    }

    public function testATamperedValueNeverBecomesAnAppearance(): void
    {
        foreach (
            [
                ['primary_color' => 'red;}body{display:none'],
                ['primary_color' => '#FF7518;}body{x:y'],
                ['text_color' => 'var(--color-bg)'],
                ['surface_color' => 'url(x)'],
                ['background_color' => ''],
            ] as $tampered
        ) {
            self::assertNull(PageAppearance::fromTheme('ok', $tampered + self::COLORS, 'system'), json_encode($tampered));
        }

        self::assertNull(PageAppearance::fromTheme('ok', self::COLORS, 'comic-sans'), 'a pairing outside the closed list');

        foreach (['x"]{}body{', 'Halloween', 'a b', '-a', 'a--b', '', str_repeat('a', 81)] as $slug) {
            self::assertNull(PageAppearance::fromTheme($slug, self::COLORS, 'system'), 'slug ' . $slug);
        }

        self::assertNotNull(PageAppearance::fromTheme('ok', ['primary_color' => 'ff7518'] + self::COLORS, 'system'), 'the site theme\'s accepted shapes');
    }

    public function testTheAttributeOnlyExistsWhileAnAppearanceIsDeclared(): void
    {
        self::assertSame('', PageThemeCss::mainAttribute());
        self::assertSame('', PageThemeCss::styleBlock());

        PageThemeCss::declare($this->appearance('zomer-2026'));
        self::assertSame(' data-page-theme="zomer-2026"', PageThemeCss::mainAttribute());

        PageThemeCss::declareForPage(null);
        self::assertSame('', PageThemeCss::mainAttribute(), 'no page, no theme');

        PageThemeCss::declare($this->appearance());
        PageThemeCss::declareForPage(['id' => 1, 'owner_type' => 'product', 'page_theme_id' => 1]);
        self::assertNull(PageThemeCss::current(), 'a product\'s content page never gets a theme');
    }

    public function testWithTheModuleOffAPageThatChoseAThemeGetsNothing(): void
    {
        ModuleRegistry::overrideForTests(['page_themes' => false]);

        PageThemeCss::declareForPage(['id' => 1, 'owner_type' => null, 'page_theme_id' => 7]);

        self::assertNull(PageThemeCss::current());
        self::assertNull(ModuleRegistry::pageAppearance(['id' => 1, 'owner_type' => null, 'page_theme_id' => 7]));
    }

    /* ------------------------------------------------------------------ */
    /* The head                                                            */
    /* ------------------------------------------------------------------ */

    public function testAPageWithoutAThemeGetsExactlyTheHeadItAlwaysHad(): void
    {
        ThemeSettings::overrideForTests(['primary_color' => '#2F6FED']);
        $without = $this->renderStyles();

        self::assertStringNotContainsString('page-theme', $without);
        self::assertSame(1, substr_count($without, 'fonts.googleapis.com/css2'));

        PageAssets::reset();
        $appearance = $this->appearance();
        PageThemeCss::declare($appearance);
        $with = $this->renderStyles();

        self::assertGreaterThan(strpos($with, '<style id="site-theme">'), strpos($with, '<style id="page-theme">'), 'the page theme comes after the site theme');

        // Take away exactly the two things a theme adds, and nothing else is left over.
        $themeFont = '<link rel="stylesheet" href="' . htmlspecialchars((string) $appearance->fontStylesheetUrl(), ENT_QUOTES, 'UTF-8') . '">' . "
";
        self::assertSame($without, str_replace([$themeFont, PageThemeCss::styleBlock($appearance)], '', $with));
    }

    public function testTheFontsAreOneDeduplicatedSet(): void
    {
        ThemeSettings::overrideForTests([]);

        // The same pairing as the site: one download, as before.
        PageThemeCss::declare($this->appearance('same', ThemeFonts::DEFAULT_KEY));
        $html = $this->renderStyles();
        self::assertSame(1, substr_count($html, 'fonts.googleapis.com/css2'));
        self::assertSame(1, substr_count($html, 'rel="preconnect" href="https://fonts.googleapis.com"'));

        // Another pairing: both, preconnects once.
        PageAssets::reset();
        PageThemeCss::declare($this->appearance('other', 'playfair-source-sans'));
        $html = $this->renderStyles();
        self::assertSame(2, substr_count($html, 'fonts.googleapis.com/css2'));
        self::assertStringContainsString('family=Trirong', $html);
        self::assertStringContainsString('family=Playfair+Display', $html);
        self::assertSame(1, substr_count($html, 'rel="preconnect" href="https://fonts.googleapis.com"'));
        self::assertSame(1, substr_count($html, 'rel="preconnect" href="https://fonts.gstatic.com"'));

        // A system site with a web-font page theme still preconnects, once.
        ThemeSettings::overrideForTests(['font_pairing' => 'system']);
        PageAssets::reset();
        PageThemeCss::declare($this->appearance('other', 'playfair-source-sans'));
        $html = $this->renderStyles();
        self::assertSame(1, substr_count($html, 'fonts.googleapis.com/css2'));
        self::assertSame(1, substr_count($html, 'rel="preconnect" href="https://fonts.gstatic.com"'));

        // A system page theme on a system site downloads nothing.
        PageAssets::reset();
        PageThemeCss::declare($this->appearance('plain', 'system'));
        self::assertStringNotContainsString('fonts.g', $this->renderStyles());
    }
}
