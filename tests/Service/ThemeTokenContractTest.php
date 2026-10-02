<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Theme\PageAppearance;
use App\Service\Theme\ThemeColor;
use App\Service\Theme\ThemeCss;
use App\Service\Theme\ThemePalette;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Themes 2.0 phase 1A (THEMING.md, "Presentatietokens"): the public
 * stylesheets draw borders, pills, shadows, sheen, status colours, the
 * heading and eyebrow character and the hover movement from tokens, so a
 * theme can change them in one place.
 *
 *   - core.css declares every one of those tokens with the shipped value, so
 *     the default theme renders exactly as before;
 *   - no public stylesheet writes a colour as a literal (hex, rgb() with
 *     numbers) outside core.css's token declarations, except a short list of
 *     colours that sit on a photograph or are a mask, each with its reason;
 *   - no status colour, border weight or pill radius is written out;
 *   - no hover lifts or zooms by a literal distance;
 *   - a page theme recomputes the shadows and restates the sheen in its own
 *     colours, and never carries a shape, border or movement token;
 *   - the status colours keep their shipped shades wherever those are
 *     readable (the default theme, a dark palette) and become readable on a
 *     light one, as part of the palette a page theme restates (phase 1A.1);
 *   - --color-on-primary is never the background of a panel (it is the
 *     colour of text and marks on a primary fill);
 *   - the accent is text only as --color-primary-text, which keeps the
 *     bright accent wherever that reads and becomes readable on a light
 *     ground, while --color-primary-bright stays a free highlight; the
 *     faint text tone keeps its shipped alpha on a dark ground and rises on
 *     a light one until it reads like it does on the default theme
 *     (phase 1A.2);
 *   - the display titles that set their own weight take it from a heading
 *     token (phase 1A.2).
 *
 * Pure: no database, no web server. Suites contract, fast and cms.
 */
final class ThemeTokenContractTest extends TestCase
{
    /** The tokens of this phase, with the value core.css ships. */
    private const SHIPPED = [
        '--border-width' => '1px',
        '--border-width-strong' => '1.5px',
        '--radius-pill' => '999px',
        '--color-shadow-rgb' => '0, 0, 0',
        '--color-sheen-rgb' => '255, 255, 255',
        '--color-danger' => '#E2685C',
        '--color-danger-rgb' => '226, 104, 92',
        '--color-danger-text' => '#F0897E',
        '--color-danger-text-rgb' => '240, 137, 126',
        '--color-danger-on-wash' => '#F5B4AC',
        '--color-success' => '#78B482',
        '--color-success-rgb' => '120, 180, 130',
        '--color-success-on-wash' => '#B7E0C0',
        '--color-primary-text' => '#E4C78E',
        '--fw-heading' => '500',
        '--fw-h1' => '400',
        '--tracking-heading' => '0.01em',
        '--eyebrow-weight' => '700',
        '--eyebrow-tracking' => '0.18em',
        '--eyebrow-case' => 'uppercase',
        '--eyebrow-rule-display' => 'inline-block',
        '--hover-lift' => '1',
        '--hover-zoom' => '1',
    ];

    /**
     * Display titles that set a weight of their own instead of the one their
     * h1–h4 element gets, per stylesheet: each takes it from a heading token.
     */
    private const TITLE_WEIGHTS = [
        'shop/shop.css' => ['.product-detail__title'],
        'shop/personalization.css' => ['.personalizer__title'],
        'blog/blog.css' => ['.blog-card__title', '.blog-related__heading'],
        'articles/articles.css' => ['.article-row__title'],
    ];

    /** Stylesheets an admin screen owns (a preview frame), not the website. */
    private const ADMIN_OWNED = [
        'block-preview.css',
        'button-style-preview.css',
        'color-palette-preview.css',
        'page-preview.css',
        'page-theme-preview.css',
    ];

    /**
     * Literal colours that stay literal, per file, with how often they occur.
     * Each one sits on a photograph or a product picture, whose colours no
     * theme decides, or is a mask, where only the alpha counts.
     */
    private const LITERAL_COLOURS = [
        // A mask fades the decoration out: black is "fully shown", not a colour.
        'block-decorations.css' => ['#000' => 2, 'rgba(0,0,0,0.35)' => 2],
        // The caption over a gallery photo: light text on a dark scrim, on the photo.
        'blocks/detail-section.css' => ['rgba(0,0,0,0.72)' => 1, 'rgba(0,0,0,0)' => 1, '#fff' => 1],
        // The engraving preview on the product picture, and a colour swatch's edge.
        'shop/personalization.css' => [
            '#F7F1E6' => 1,
            'rgba(27, 20, 13, 0.9)' => 1,
            'rgba(27, 20, 13, 0.7)' => 4,
            'rgba(0, 0, 0, 0.45)' => 1,
            'rgba(0, 0, 0, 0.25)' => 1,
        ],
        // The checkout's "redirecting to payment" overlay: a backdrop (THEMING.md, fase 1B).
        'shop/shop.css' => ['rgba(15,11,7,0.72)' => 1],
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function source(string $path): string
    {
        $file = self::root() . '/' . $path;
        self::assertFileExists($file);

        return str_replace("\r\n", "\n", (string) file_get_contents($file));
    }

    private static function withoutComments(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
    }

    /** @return array<string, string> path under assets/css => source without comments */
    private static function publicStylesheets(): array
    {
        $base = self::root() . '/assets/css/';
        $files = array_merge(glob($base . '*.css') ?: [], glob($base . '*/*.css') ?: []);

        $out = [];
        foreach ($files as $file) {
            $name = substr($file, strlen($base));
            if (in_array($name, self::ADMIN_OWNED, true)) {
                continue;
            }
            $out[$name] = self::withoutComments(self::source('assets/css/' . $name));
        }
        self::assertArrayHasKey('core.css', $out);
        self::assertGreaterThan(20, count($out), 'every public stylesheet is read');

        return $out;
    }

    /**
     * core.css without its two token rules (`:root{…}` and
     * `:root, main[data-page-theme]{…}`): what is left draws the page.
     */
    private static function coreRules(): string
    {
        $css = self::withoutComments(self::source('assets/css/core.css'));
        $stripped = preg_replace('/(?:^|(?<=\}))\s*:root(,\s*main\[data-page-theme\])?\s*\{[^{}]*\}/', '', $css, -1, $count);
        self::assertSame(2, $count, 'core.css has its two token rules');

        return (string) $stripped;
    }

    /** @return array<string, string> custom property => value, from one rule body */
    private static function declarations(string $body): array
    {
        preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/', $body, $matches, PREG_SET_ORDER);

        $out = [];
        foreach ($matches as $match) {
            $out[$match[1]] = preg_replace('/\s+/', ' ', trim($match[2])) ?? '';
        }

        return $out;
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} :root tokens, shared-rule tokens */
    private static function tokenRules(): array
    {
        $css = self::withoutComments(self::source('assets/css/core.css'));
        self::assertSame(1, preg_match('/(?:^|\})\s*:root\s*\{([^{}]*)\}/', $css, $root));
        self::assertSame(1, preg_match('/:root,\s*main\[data-page-theme\]\s*\{([^{}]*)\}/', $css, $shared));

        return [self::declarations($root[1]), self::declarations($shared[1])];
    }

    /** @return list<string> every rule in $css as "selector{body}", @media wrappers flattened */
    private static function rules(string $css): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);

        $out = [];
        foreach ($matches as $match) {
            $selector = trim(preg_replace('/\s+/', ' ', $match[1]) ?? '');
            $out[] = $selector . '{' . $match[2] . '}';
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */

    public function testCoreCssShipsEveryTokenWithTheDefaultThemesValue(): void
    {
        [$root] = self::tokenRules();

        foreach (self::SHIPPED as $property => $value) {
            self::assertArrayHasKey($property, $root, $property . ' is declared on :root');
            self::assertSame($value, $root[$property], $property . ' keeps the shipped look');
        }
    }

    public function testTheShadowsAreBuiltOnTheShadowChannelAndRecomputeInsideAPageTheme(): void
    {
        [$root, $shared] = self::tokenRules();

        self::assertArrayNotHasKey('--shadow-soft', $root);
        self::assertArrayNotHasKey('--shadow-lift', $root);
        self::assertSame('0 16px 36px -18px rgba(var(--color-shadow-rgb), 0.6)', $shared['--shadow-soft'] ?? null);
        self::assertSame('0 30px 60px -22px rgba(var(--color-shadow-rgb), 0.7)', $shared['--shadow-lift'] ?? null);
    }

    public function testNoPublicStylesheetWritesAColourOutsideTheTokens(): void
    {
        $sheets = self::publicStylesheets();
        $sheets['core.css'] = self::coreRules();

        foreach ($sheets as $name => $css) {
            preg_match_all('/#[0-9a-fA-F]{3,8}\b|rgba?\(\s*\d[^)]*\)/', $css, $found);
            $counts = array_count_values($found[0]);
            ksort($counts);
            $allowed = self::LITERAL_COLOURS[$name] ?? [];
            ksort($allowed);

            self::assertSame(
                $allowed,
                $counts,
                $name . ' writes a colour as a literal. Use a token (--color-…, rgba(var(--color-…-rgb), a)); '
                    . 'a colour on a photograph goes in LITERAL_COLOURS with its reason'
            );
        }
    }

    public function testNoStatusColourBorderWeightOrPillRadiusIsWrittenOut(): void
    {
        $statusLiterals = [];
        foreach (self::SHIPPED as $property => $value) {
            if (str_starts_with($property, '--color-danger') || str_starts_with($property, '--color-success')) {
                $statusLiterals[] = $value;
            }
        }

        foreach (self::publicStylesheets() as $name => $css) {
            if ($name === 'core.css') {
                $css = self::coreRules();
            }
            foreach ($statusLiterals as $literal) {
                self::assertStringNotContainsStringIgnoringCase(
                    str_replace(', ', ',', $literal),
                    str_replace(', ', ',', $css),
                    $name . ' writes a status colour out: use --color-danger… / --color-success…'
                );
            }
            // A system colour (forced-colors mode) is the one border that keeps its pixel width.
            $borders = preg_replace('/[^;{]*\b(ButtonText|CanvasText|Highlight)\b[^;}]*/', '', $css) ?? '';
            self::assertDoesNotMatchRegularExpression(
                '/\bborder(-[a-z]+)*\s*:\s*1(\.5)?px\s+(solid|dashed|dotted)/',
                $borders,
                $name . ' writes a border weight out: use --border-width or --border-width-strong'
            );
            self::assertDoesNotMatchRegularExpression(
                '/border-radius\s*:\s*999px/',
                $css,
                $name . ' writes a pill out: use --radius-pill'
            );
        }
    }

    public function testNothingLiftsOrZoomsOnHoverByALiteralDistance(): void
    {
        foreach (self::publicStylesheets() as $name => $css) {
            foreach (self::rules($css) as $rule) {
                $selector = substr($rule, 0, (int) strpos($rule, '{'));
                if (!preg_match('/:(hover|focus-visible|focus-within)/', $selector)) {
                    continue;
                }
                self::assertDoesNotMatchRegularExpression(
                    '/translateY\(\s*-[\d.]+px\s*\)|scale\(\s*1\.\d+\s*\)/',
                    $rule,
                    $name . ': "' . $selector . '" moves by a fixed distance; multiply it by '
                        . 'var(--hover-lift) or var(--hover-zoom)'
                );
            }
        }
    }

    public function testTheHeadingAndTheEyebrowTakeTheirCharacterFromTokens(): void
    {
        $css = self::coreRules();

        self::assertMatchesRegularExpression('/h1, h2, h3, h4\{[^}]*font-weight: var\(--fw-heading\);[^}]*letter-spacing: var\(--tracking-heading\);/', $css);
        self::assertStringContainsString('h1{ font-size: var(--fs-h1); font-weight: var(--fw-h1); }', $css);
        self::assertMatchesRegularExpression(
            '/\.eyebrow\{[^}]*letter-spacing: var\(--eyebrow-tracking\);[^}]*text-transform: var\(--eyebrow-case\);[^}]*font-weight: var\(--eyebrow-weight\);/',
            $css
        );
        self::assertMatchesRegularExpression('/\.eyebrow::before\{[^}]*display: var\(--eyebrow-rule-display\);/', $css);
    }

    public function testOnPrimaryIsNeverTheBackgroundOfAPanel(): void
    {
        foreach (self::publicStylesheets() as $name => $css) {
            foreach (self::rules($name === 'core.css' ? self::coreRules() : $css) as $rule) {
                self::assertDoesNotMatchRegularExpression(
                    '/(^|[;{\s])background(-color)?\s*:[^;{}]*var\(--color-on-primary\)/',
                    $rule,
                    $name . ': "' . strtok($rule, '{') . '" paints a surface in --color-on-primary, the colour of '
                        . 'text on a primary fill. A panel is --color-surface'
                );
            }
        }
    }

    public function testAPageThemeRestatesTheSheenInItsOwnColoursAndCarriesNoShapeOrMovement(): void
    {
        $colors = [
            'primary_color' => '#2B6CB0',
            'on_primary_color' => '#FFFFFF',
            'background_color' => '#FFFFFF',
            'surface_color' => '#F4F6F8',
            'text_color' => '#1A202C',
        ];
        $appearance = PageAppearance::fromTheme('licht', $colors, ThemeSettings::defaults()['font_pairing']);
        self::assertNotNull($appearance);
        $declarations = $appearance->declarations();

        // Light ground, dark text: the sheen darkens instead of vanishing in white.
        self::assertSame('26, 32, 44', $declarations['--color-sheen-rgb'] ?? null);
        self::assertSame(ThemeCss::paletteDeclarations($colors)['--color-sheen-rgb'], $declarations['--color-sheen-rgb']);
        self::assertContains('--color-sheen-rgb', ThemePalette::dependencies()['text']);

        foreach (array_keys($declarations) as $property) {
            self::assertDoesNotMatchRegularExpression(
                '/^--(radius|border|hover|shadow|fw-|tracking|eyebrow)/',
                $property,
                'a page theme is colours and fonts only, never ' . $property
            );
        }
    }

    /** @return iterable<string, array{0: array<string, string>}> */
    public static function darkPalettes(): iterable
    {
        yield 'the default theme' => [[
            'primary_color' => '#C9A063', 'on_primary_color' => '#1B140D', 'background_color' => '#120D09',
            'surface_color' => '#1C150E', 'text_color' => '#F5EFE4',
        ]];
        yield 'a dark page theme' => [[
            'primary_color' => '#FF7518', 'on_primary_color' => '#111111', 'background_color' => '#1A0F1F',
            'surface_color' => '#2A1A30', 'text_color' => '#F7F1E8',
        ]];
    }

    /** @param array<string, string> $colors */
    #[DataProvider('darkPalettes')]
    public function testADarkPaletteKeepsTheShippedStatusShades(array $colors): void
    {
        $tokens = ThemeCss::paletteDeclarations($colors);

        foreach (self::SHIPPED as $property => $value) {
            if (str_starts_with($property, '--color-danger') || str_starts_with($property, '--color-success')) {
                self::assertSame($value, $tokens[$property] ?? null, $property . ' keeps the shade core.css ships');
            }
        }
    }

    /** @return iterable<string, array{0: array<string, string>}> */
    public static function lightPalettes(): iterable
    {
        yield 'white with a grey card' => [[
            'primary_color' => '#2B6CB0', 'on_primary_color' => '#FFFFFF', 'background_color' => '#FFFFFF',
            'surface_color' => '#F4F6F8', 'text_color' => '#1A202C',
        ]];
        yield 'warm cream' => [[
            'primary_color' => '#7A4E1D', 'on_primary_color' => '#FFFFFF', 'background_color' => '#F3EBDD',
            'surface_color' => '#FBF7EF', 'text_color' => '#2A2118',
        ]];
    }

    /** @param array<string, string> $colors */
    #[DataProvider('lightPalettes')]
    public function testALightPageThemeGetsReadableStatusColours(array $colors): void
    {
        $appearance = PageAppearance::fromTheme('licht', $colors, ThemeSettings::defaults()['font_pairing']);
        self::assertNotNull($appearance);
        $tokens = $appearance->declarations();

        $grounds = [$colors['background_color'], $colors['surface_color']];
        foreach ($grounds as $ground) {
            self::assertGreaterThanOrEqual(3.0, ThemeColor::contrastRatio($tokens['--color-danger'], $ground), 'an error border');
            self::assertGreaterThanOrEqual(4.5, ThemeColor::contrastRatio($tokens['--color-danger-text'], $ground), 'an error message');
            self::assertGreaterThanOrEqual(3.0, ThemeColor::contrastRatio($tokens['--color-success'], $ground), 'a success mark');

            $dangerWash = ThemePalette::mix($ground, $tokens['--color-danger'], 0.12);
            $successWash = ThemePalette::mix($ground, $tokens['--color-success'], 0.12);
            self::assertGreaterThanOrEqual(4.5, ThemeColor::contrastRatio($tokens['--color-danger-on-wash'], $dangerWash), 'text on the error wash');
            self::assertGreaterThanOrEqual(4.5, ThemeColor::contrastRatio($tokens['--color-danger-text'], $dangerWash), 'an error message on a faint wash');
            self::assertGreaterThanOrEqual(4.5, ThemeColor::contrastRatio($tokens['--color-success-on-wash'], $successWash), 'text on the success wash');
        }

        // Still the same meaning: an error stays red, a success green.
        [$r, $g, $b] = ThemePalette::rgb($tokens['--color-danger-text']);
        self::assertGreaterThan(max($g, $b), $r);
        [$r, $g, $b] = ThemePalette::rgb($tokens['--color-success-on-wash']);
        self::assertGreaterThan(max($r, $b), $g);

        // The -rgb channels are the same colours, for the washes.
        self::assertSame(ThemePalette::channels($tokens['--color-danger']), $tokens['--color-danger-rgb']);
        self::assertSame(ThemePalette::channels($tokens['--color-danger-text']), $tokens['--color-danger-text-rgb']);
        self::assertSame(ThemePalette::channels($tokens['--color-success']), $tokens['--color-success-rgb']);
    }

    public function testTheAccentIsTextOnlyThroughItsTextRole(): void
    {
        foreach (self::publicStylesheets() as $name => $css) {
            foreach (self::rules($name === 'core.css' ? self::coreRules() : $css) as $rule) {
                self::assertDoesNotMatchRegularExpression(
                    '/(^|[;{\s])color\s*:[^;{}]*var\(--color-primary-bright\)/',
                    $rule,
                    $name . ': "' . strtok($rule, '{') . '" sets text in --color-primary-bright, the highlight. '
                        . 'Text in the accent is --color-primary-text, which stays readable on a light ground'
                );
            }
        }
    }

    /** @param array<string, string> $colors */
    #[DataProvider('darkPalettes')]
    public function testADarkPaletteKeepsTheBrightAccentAsTextAndTheShippedFaintAlpha(array $colors): void
    {
        $tokens = ThemeCss::paletteDeclarations($colors);

        self::assertSame($tokens['--color-primary-bright'], $tokens['--color-primary-text']);
        self::assertSame('rgba(' . ThemePalette::channels($colors['text_color']) . ', 0.46)', $tokens['--color-text-faint']);
    }

    /** @param array<string, string> $colors */
    #[DataProvider('lightPalettes')]
    public function testALightPageThemeGetsReadableAccentTextAndFaintTextAndKeepsItsHighlight(array $colors): void
    {
        $appearance = PageAppearance::fromTheme('licht', $colors, ThemeSettings::defaults()['font_pairing']);
        self::assertNotNull($appearance);
        $tokens = $appearance->declarations();

        self::assertSame(1, preg_match('/^rgba\((\d+), (\d+), (\d+), ([\d.]+)\)$/', $tokens['--color-text-faint'], $faint));
        self::assertSame(ThemePalette::channels($colors['text_color']), $faint[1] . ', ' . $faint[2] . ', ' . $faint[3]);
        $alpha = (float) $faint[4];
        self::assertGreaterThan(0.46, $alpha, 'the faint tone rises on a light ground');
        self::assertLessThan(0.70, $alpha, 'and stays quieter than --color-text-muted');

        // On the ground, a card and the footer's deeper ground; accent text
        // also on the accent wash over the ground and the card.
        foreach ([$colors['background_color'], $colors['surface_color'], $tokens['--color-bg-deep']] as $ground) {
            self::assertGreaterThanOrEqual(4.5, ThemeColor::contrastRatio($tokens['--color-primary-text'], $ground), 'accent text');
            self::assertGreaterThanOrEqual(4.0, ThemeColor::contrastRatio(ThemePalette::mix($ground, $colors['text_color'], $alpha), $ground), 'faint text');
        }
        foreach ([$colors['background_color'], $colors['surface_color']] as $ground) {
            $wash = ThemePalette::mix($ground, $colors['primary_color'], 0.10);
            self::assertGreaterThanOrEqual(4.5, ThemeColor::contrastRatio($tokens['--color-primary-text'], $wash), 'accent text on the accent wash');
        }

        // The highlight is still the lighter accent: a theme picks it freely.
        [$pr, $pg, $pb] = ThemePalette::rgb($colors['primary_color']);
        [$br, $bg, $bb] = ThemePalette::rgb($tokens['--color-primary-bright']);
        self::assertGreaterThan($pr + $pg + $pb, $br + $bg + $bb);
    }

    public function testTheDisplayTitlesWithAWeightOfTheirOwnTakeItFromAHeadingToken(): void
    {
        $sheets = self::publicStylesheets();

        foreach (self::TITLE_WEIGHTS as $name => $selectors) {
            foreach ($selectors as $selector) {
                $found = false;
                foreach (self::rules($sheets[$name]) as $rule) {
                    if (strtok($rule, '{') !== $selector || !str_contains($rule, 'font-weight')) {
                        continue;
                    }
                    $found = true;
                    self::assertMatchesRegularExpression(
                        '/font-weight\s*:\s*var\(--fw-(heading|h1)\)/',
                        $rule,
                        $name . ': ' . $selector . ' writes its weight out; use --fw-heading or --fw-h1'
                    );
                }
                self::assertTrue($found, $name . ' still sets the weight of ' . $selector);
            }
        }
    }
}
