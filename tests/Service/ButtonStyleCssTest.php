<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Theme\ButtonIcons;
use App\Service\Theme\ButtonStyleCss;
use App\Service\Theme\ButtonStyles;
use App\Service\Theme\ThemeCss;
use PHPUnit\Framework\TestCase;

/**
 * Button Styles 2.0 without a database: what one style becomes as CSS
 * (App\Service\Theme\ButtonStyleCss), the icons (ButtonIcons), the resolver
 * every partial asks (ButtonStyles::classes()) and how a stored row is read
 * (ButtonStyles::shape()). See THEMING.md, "Knopstijlen".
 *
 *   - core.css's .btn and .btn--ghost declare exactly LEGACY_PRIMARY and
 *     LEGACY_SECONDARY_OVERRIDES, so "the shipped look" is one model in two
 *     places that cannot drift;
 *   - the two shipped designs as seeded produce no CSS at all, a changed
 *     default only its difference, a chosen style its complete set;
 *   - every appearance, shape, border, shadow, hover and icon, a theme colour
 *     as var() and a fixed colour as itself;
 *   - nothing unsafe reaches a stylesheet, and the class name is an id.
 */
final class ButtonStyleCssTest extends TestCase
{
    /** The seeded "Primair" (db/migrations/20261005100000). */
    private const PRIMARY = [
        'id' => 1, 'name' => 'Primair', 'appearance' => 'filled', 'shape' => 'pill', 'size' => 'normal',
        'fill_color' => 'primary', 'fill_gradient' => true, 'text_color' => 'on_primary',
        'border_width' => 'none', 'border_color' => 'primary', 'shadow' => 'none',
        'font_weight' => 'bold', 'font_role' => 'body', 'uppercase' => false, 'underline' => false,
        'icon' => 'none', 'icon_position' => 'after', 'icon_gap' => 'normal', 'icon_motion' => true,
        'hover_effect' => 'glow', 'hover_fill_color' => null, 'hover_text_color' => null, 'hover_border_color' => null,
    ];

    /** The seeded "Secundair". */
    private const SECONDARY = [
        'id' => 2, 'name' => 'Secundair', 'appearance' => 'outline', 'text_color' => 'text', 'border_width' => 'normal',
        'border_color' => 'line_strong', 'fill_gradient' => false, 'hover_effect' => 'lift', 'hover_fill_color' => 'primary_wash',
        'hover_text_color' => 'primary_bright', 'hover_border_color' => 'primary',
    ] + self::PRIMARY;

    // ------------------------------------------------------ the shipped look

    public function testCoreCssDeclaresTheShippedButtonsAsTheLegacyModel(): void
    {
        self::assertSame(ButtonStyleCss::LEGACY_PRIMARY, $this->coreRule('.btn'));
        self::assertSame(ButtonStyleCss::LEGACY_SECONDARY_OVERRIDES, $this->coreRule('.btn--ghost'));
    }

    public function testTheSeededDefaultsAreTheShippedButtons(): void
    {
        $primary = ButtonStyleCss::declarations(self::PRIMARY);
        unset($primary['--btn-radius']);
        $legacy = ButtonStyleCss::LEGACY_PRIMARY;
        unset($legacy['--btn-radius']);
        self::assertSame($legacy, $primary);

        $secondary = ButtonStyleCss::declarations(self::SECONDARY);
        unset($secondary['--btn-radius']);
        $legacy = ButtonStyleCss::legacySecondary();
        unset($legacy['--btn-radius']);
        self::assertSame($legacy, $secondary);
    }

    public function testASiteOnTheShippedButtonsPrintsNoButtonCss(): void
    {
        self::assertSame('', ButtonStyleCss::styleBlock(self::PRIMARY, self::SECONDARY, []));
        self::assertSame('', ButtonStyleCss::styleBlock(null, null, []));
        // The old Knopvorm "rounded" on both defaults is still the shape token's job.
        self::assertSame('', ButtonStyleCss::styleBlock(['shape' => 'rounded'] + self::PRIMARY, ['shape' => 'rounded'] + self::SECONDARY, []));
    }

    public function testAChangedDefaultPrintsOnlyItsDifference(): void
    {
        $block = ButtonStyleCss::styleBlock(['size' => 'large', 'uppercase' => true] + self::PRIMARY, self::SECONDARY, []);

        self::assertStringStartsWith('<style id="site-buttons">', $block);
        $rule = $this->ruleIn($block, '.btn:where(:not(.btn--ghost, .btn--on-dark))');
        self::assertSame([
            '--btn-pad-y' => '1.15rem',
            '--btn-pad-x' => '2.4rem',
            '--btn-font-size' => '1.05rem',
            '--btn-case' => 'uppercase',
            '--btn-tracking' => '0.08em',
        ], $rule);
        self::assertStringNotContainsString('.btn--ghost{', $block, 'the second button did not change');
    }

    public function testASecondButtonOfAnotherShapeCarriesItsOwnRadius(): void
    {
        $block = ButtonStyleCss::styleBlock(self::PRIMARY, ['shape' => 'square'] + self::SECONDARY, []);

        self::assertSame(['--btn-radius' => '0px'], $this->ruleIn($block, '.btn--ghost'));
    }

    public function testADefaultWithAnIconHidesTheOldArrow(): void
    {
        $block = ButtonStyleCss::styleBlock(['icon' => 'arrow_right'] + self::PRIMARY, ['icon' => 'plus'] + self::SECONDARY, []);

        self::assertSame(['display' => 'none'], $this->ruleIn($block, '.btn:where(:not(.btn--ghost, .btn--on-dark)) > .btn__arrow'));
        self::assertSame(['display' => 'none'], $this->ruleIn($block, '.btn--ghost > .btn__arrow'));
        self::assertStringNotContainsString('.btn__arrow', ButtonStyleCss::styleBlock(self::PRIMARY, self::SECONDARY, []));
    }

    public function testAChosenStyleIsOneCompleteRuleSharedByEveryButtonThatChoseIt(): void
    {
        $outline = ['id' => 7, 'appearance' => 'outline', 'text_color' => 'primary'] + self::SECONDARY;
        $block = ButtonStyleCss::styleBlock(self::PRIMARY, self::SECONDARY, [7 => $outline]);

        self::assertSame(1, substr_count($block, '.btn.btn-style-7{'));
        self::assertSame(ButtonStyleCss::declarations($outline), $this->ruleIn($block, '.btn.btn-style-7'));
        self::assertCount(count(ButtonStyleCss::LEGACY_PRIMARY), $this->ruleIn($block, '.btn.btn-style-7'));
        self::assertSame('btn-style-7', ButtonStyleCss::className(7));
    }

    // ------------------------------------------------------- appearances

    public function testFilled(): void
    {
        $css = ButtonStyleCss::declarations(['fill_gradient' => false, 'fill_color' => 'surface', 'text_color' => 'text'] + self::PRIMARY);

        self::assertSame('var(--color-surface)', $css['--btn-bg']);
        self::assertSame('var(--color-text)', $css['--btn-fg']);
        self::assertSame('transparent', $css['--btn-border-color']);
        self::assertSame('1.5px', $css['--btn-border-width'], 'no border keeps the height of a bordered button');
    }

    public function testFilledWithASheenOverAnyColour(): void
    {
        self::assertSame(ButtonStyleCss::PRIMARY_GRADIENT, ButtonStyleCss::declarations(self::PRIMARY)['--btn-bg']);
        self::assertSame(
            ButtonStyleCss::SHEEN . ', #0F766E',
            ButtonStyleCss::declarations(['fill_color' => '#0F766E'] + self::PRIMARY)['--btn-bg']
        );
    }

    public function testOutline(): void
    {
        $css = ButtonStyleCss::declarations(['appearance' => 'outline', 'border_width' => 'thick', 'border_color' => 'primary', 'text_color' => 'primary'] + self::PRIMARY);

        self::assertSame('transparent', $css['--btn-bg']);
        self::assertSame('2.5px', $css['--btn-border-width']);
        self::assertSame('var(--color-primary)', $css['--btn-border-color']);
    }

    public function testGhost(): void
    {
        $css = ButtonStyleCss::declarations(['appearance' => 'ghost', 'text_color' => 'text'] + self::PRIMARY);

        self::assertSame('transparent', $css['--btn-bg']);
        self::assertSame('transparent', $css['--btn-border-color']);
    }

    public function testText(): void
    {
        $css = ButtonStyleCss::declarations(['appearance' => 'text', 'underline' => true, 'shadow' => 'strong', 'border_width' => 'thick', 'hover_border_color' => 'primary'] + self::PRIMARY);

        self::assertSame('transparent', $css['--btn-bg']);
        self::assertSame('0px', $css['--btn-border-width']);
        self::assertSame(['0.2em', '0px'], [$css['--btn-pad-y'], $css['--btn-pad-x']]);
        self::assertSame('underline', $css['--btn-decoration']);
        self::assertSame('none', $css['--btn-shadow'], 'no surface, no shadow');
        self::assertSame('var(--btn-border-color)', $css['--btn-hover-border-color']);
        self::assertSame('none', ButtonStyleCss::declarations(['underline' => true] + self::PRIMARY)['--btn-decoration'], 'underline is for a text button');
    }

    public function testShapes(): void
    {
        $radius = static fn (string $shape): string => ButtonStyleCss::declarations(['shape' => $shape] + self::PRIMARY)['--btn-radius'];

        self::assertSame('0px', $radius('square'));
        self::assertSame('var(--radius-sm)', $radius('soft'));
        self::assertSame('var(--radius-md)', $radius('rounded'));
        self::assertSame('var(--radius-lg)', $radius('round'));
        self::assertSame('999px', $radius('pill'));
    }

    public function testShadowsAndBorders(): void
    {
        foreach (ButtonStyleCss::SHADOWS as $shadow => $value) {
            self::assertSame($value, ButtonStyleCss::declarations(['shadow' => $shadow] + self::PRIMARY)['--btn-shadow']);
        }

        foreach (ButtonStyleCss::BORDERS as $border => $width) {
            $css = ButtonStyleCss::declarations(['appearance' => 'ghost', 'border_width' => $border] + self::PRIMARY);
            self::assertSame($width, $css['--btn-border-width']);
            self::assertSame($border === 'none' ? 'transparent' : 'var(--color-primary)', $css['--btn-border-color']);
        }
    }

    public function testHover(): void
    {
        $css = ButtonStyleCss::declarations(['hover_effect' => 'shadow', 'hover_fill_color' => 'primary_deep', 'hover_text_color' => '#FFFFFF', 'hover_border_color' => 'text'] + self::PRIMARY);
        self::assertSame(['-2px', 'var(--shadow-lift)', 'none'], [$css['--btn-hover-lift'], $css['--btn-hover-shadow'], $css['--btn-hover-filter']]);
        self::assertSame(['var(--color-primary-deep)', '#FFFFFF', 'var(--color-text)'], [$css['--btn-hover-bg'], $css['--btn-hover-fg'], $css['--btn-hover-border-color']]);

        $none = ButtonStyleCss::declarations(['hover_effect' => 'none', 'shadow' => 'subtle'] + self::PRIMARY);
        self::assertSame(['0px', ButtonStyleCss::SHADOWS['subtle'], 'none'], [$none['--btn-hover-lift'], $none['--btn-hover-shadow'], $none['--btn-hover-filter']]);
        self::assertSame(['var(--btn-bg)', 'var(--btn-fg)', 'var(--btn-border-color)'], [$none['--btn-hover-bg'], $none['--btn-hover-fg'], $none['--btn-hover-border-color']]);

        self::assertSame('brightness(1.08)', ButtonStyleCss::declarations(['hover_effect' => 'brighten'] + self::PRIMARY)['--btn-hover-filter']);
    }

    public function testCoreCssDrawsHoverFocusDisabledAndReducedMotionFromTheModel(): void
    {
        $css = $this->coreCss();

        self::assertMatchesRegularExpression('/\.btn:hover\{[^}]*transform: translateY\(var\(--btn-hover-lift\)\)[^}]*background: var\(--btn-hover-bg\)[^}]*border-color: var\(--btn-hover-border-color\)/s', $css);
        self::assertMatchesRegularExpression('/\.btn:disabled\{ opacity: 0\.5; cursor: not-allowed; transform: none;/', $css);
        self::assertMatchesRegularExpression('/:focus-visible\{\s*outline: 2\.5px solid var\(--color-primary-text\);/', $css);
        self::assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\)\{[^@]*\.btn:hover,\s*\.btn:hover::before,\s*\.btn:hover::after,\s*\.btn:hover svg\{ transform: none; \}/s', $css);

        // No style can switch the focus ring off: the model has no outline.
        foreach ([self::PRIMARY, ['appearance' => 'text', 'icon' => 'external'] + self::PRIMARY] as $style) {
            foreach (array_keys(ButtonStyleCss::declarations($style)) as $property) {
                self::assertStringStartsWith('--btn-', $property);
            }
        }
    }

    // ------------------------------------------------------------ colours

    public function testAThemeColourFollowsTheThemeAndAFixedColourStays(): void
    {
        self::assertSame('var(--color-primary)', ButtonStyleCss::color('primary'));
        self::assertSame('var(--color-bg)', ButtonStyleCss::color('background'));
        self::assertSame('#0F766E', ButtonStyleCss::color('#0f766e'));
        self::assertSame('var(--color-primary)', ButtonStyleCss::color('red;}body{x'), 'never printed as typed');
    }

    public function testTheBrightAccentAsALabelIsTheAccentAsText(): void
    {
        // As text and hover text the highlight is --color-primary-text: the
        // same colour where the highlight reads, readable where it does not.
        $outline = ['appearance' => 'outline', 'border_width' => 'normal', 'text_color' => 'primary_bright', 'border_color' => 'primary_bright',
            'hover_fill_color' => 'primary_bright', 'hover_text_color' => 'primary_bright', 'hover_border_color' => 'primary_bright'] + self::PRIMARY;
        $css = ButtonStyleCss::declarations($outline);
        self::assertSame(['var(--color-primary-text)', 'var(--color-primary-text)'], [$css['--btn-fg'], $css['--btn-hover-fg']]);

        // As a fill or a border it stays the free highlight.
        self::assertSame(['var(--color-primary-bright)', 'var(--color-primary-bright)', 'var(--color-primary-bright)'],
            [$css['--btn-border-color'], $css['--btn-hover-bg'], $css['--btn-hover-border-color']]);
        self::assertSame('var(--color-primary-bright)', ButtonStyleCss::declarations(['fill_color' => 'primary_bright', 'fill_gradient' => false] + self::PRIMARY)['--btn-bg']);

        // Every other word, and a fixed colour, is the same as a label.
        foreach (array_keys(ButtonStyleCss::COLORS) as $word) {
            if ($word !== 'primary_bright') {
                self::assertSame(ButtonStyleCss::color($word), ButtonStyleCss::textColor($word), $word);
            }
        }
        self::assertSame('#0F766E', ButtonStyleCss::textColor('#0f766e'));
        self::assertEqualsCanonicalizing(array_keys(ButtonStyleCss::COLORS), array_keys(ButtonStyleCss::TEXT_COLORS), 'no word of its own');
    }

    public function testTheFontFollowsTheThemeAndTheFontLibrary(): void
    {
        self::assertSame('var(--font-body)', ButtonStyleCss::declarations(self::PRIMARY)['--btn-font']);
        self::assertSame('var(--font-display)', ButtonStyleCss::declarations(['font_role' => 'heading'] + self::PRIMARY)['--btn-font']);
        self::assertSame('600', ButtonStyleCss::declarations(['font_weight' => 'semibold'] + self::PRIMARY)['--btn-weight']);
    }

    // -------------------------------------------------------------- icons

    public function testWithoutAnIcon(): void
    {
        $css = ButtonStyleCss::declarations(self::PRIMARY);

        self::assertSame(['none', 'none', 'none', '0px'], [$css['--btn-icon'], $css['--btn-icon-before'], $css['--btn-icon-after'], $css['--btn-icon-shift']]);
    }

    public function testAnArrowToTheRightAndToTheLeft(): void
    {
        $right = ButtonStyleCss::declarations(['icon' => 'arrow_right'] + self::PRIMARY);
        self::assertSame(ButtonIcons::maskUrl('arrow_right'), $right['--btn-icon']);
        self::assertSame(['none', '""', '3px'], [$right['--btn-icon-before'], $right['--btn-icon-after'], $right['--btn-icon-shift']]);

        $left = ButtonStyleCss::declarations(['icon' => 'arrow_left', 'icon_position' => 'before'] + self::PRIMARY);
        self::assertSame(['""', 'none', '-3px'], [$left['--btn-icon-before'], $left['--btn-icon-after'], $left['--btn-icon-shift']]);

        $still = ButtonStyleCss::declarations(['icon' => 'arrow_right', 'icon_motion' => false, 'icon_gap' => 'large'] + self::PRIMARY);
        self::assertSame(['0px', '0.9rem'], [$still['--btn-icon-shift'], $still['--btn-gap']]);
    }

    public function testEveryOtherIconIsAClosedDrawingThatDoesNotMove(): void
    {
        self::assertSame(['none', 'arrow_right', 'arrow_left', 'chevron_right', 'external', 'plus', 'download', 'cart'], ButtonIcons::keys());

        foreach (ButtonIcons::keys() as $key) {
            if ($key === 'none') {
                self::assertNull(ButtonIcons::maskUrl($key));
                continue;
            }

            $mask = (string) ButtonIcons::maskUrl($key);
            self::assertStringStartsWith('url("data:image/svg+xml,%3Csvg', $mask, $key);
            self::assertTrue(ThemeCss::isSafeValue($mask), $key);
            self::assertStringContainsString('aria-hidden="true"', ButtonIcons::inlineSvg($key));
        }

        self::assertSame(0, ButtonIcons::direction('plus'));
        self::assertSame(0, ButtonIcons::direction('download'));
        self::assertSame(0, ButtonIcons::direction('cart'));
    }

    public function testAnUnknownIconIsNothing(): void
    {
        self::assertFalse(ButtonIcons::isValid('<svg onload=alert(1)>'));
        self::assertFalse(ButtonIcons::isValid('../../x'));
        self::assertNull(ButtonIcons::maskUrl('fa-rocket'));
        self::assertSame('', ButtonIcons::inlineSvg('fa-rocket'));
        self::assertSame('none', ButtonStyleCss::declarations(['icon' => 'fa-rocket'] + self::PRIMARY)['--btn-icon']);
    }

    // --------------------------------------------------- safety and reading

    public function testNothingUnsafeReachesTheStylesheet(): void
    {
        $style = ButtonStyles::shape([
            'id' => 9, 'name' => '</style><script>x</script>', 'appearance' => 'filled;}', 'shape' => '20px',
            'fill_color' => 'red;}body{display:none', 'text_color' => 'url(x)', 'border_color' => 'var(--x)',
            'hover_fill_color' => 'expression(1)', 'icon' => '<svg>', 'hover_effect' => 'spin',
        ]);

        self::assertSame('filled', $style['appearance']);
        self::assertSame('pill', $style['shape']);
        self::assertSame('primary', $style['fill_color']);
        self::assertSame('on_primary', $style['text_color']);
        self::assertSame('primary', $style['border_color']);
        self::assertNull($style['hover_fill_color']);
        self::assertSame('none', $style['icon']);
        self::assertSame('glow', $style['hover_effect']);

        $block = ButtonStyleCss::styleBlock(self::PRIMARY, self::SECONDARY, [9 => $style]);
        self::assertStringNotContainsString('</style><script>', $block);
        self::assertSame(1, substr_count($block, '</style>'));
        foreach (ButtonStyleCss::declarations($style) as $value) {
            self::assertTrue(ThemeCss::isSafeValue($value), $value);
        }
    }

    public function testTheResolverKeepsTheOldMarkupWithoutAChoice(): void
    {
        self::assertSame(['class' => 'btn', 'legacy_icon' => true], ButtonStyles::classes(null, ['btn']));
        self::assertSame(['class' => 'btn btn--ghost btn--block', 'legacy_icon' => true], ButtonStyles::classes(null, ['btn', 'btn--ghost'], ['btn--block']));
        self::assertSame(['class' => 'btn btn-style-4 btn--block', 'legacy_icon' => false], ButtonStyles::classes(4, ['btn', 'btn--ghost', 'btn--sm'], ['btn--block']));
        self::assertSame(['class' => 'btn', 'legacy_icon' => true], ButtonStyles::classes(0, ['btn']));
        self::assertNull(ButtonStyles::storedChoice('0'));
        self::assertNull(ButtonStyles::storedChoice('abc'));
        self::assertNull(ButtonStyles::storedChoice(null));
        self::assertSame(12, ButtonStyles::storedChoice('12'));
    }

    public function testTheRecipeIsTheSameMapsTheWebsiteUses(): void
    {
        $recipe = ButtonStyleCss::recipe();

        self::assertSame(ButtonStyleCss::COLORS, $recipe['colors']);
        self::assertSame(ButtonStyleCss::TEXT_COLORS, $recipe['textColors']);
        self::assertSame(ButtonStyleCss::SHAPES, $recipe['shapes']);
        self::assertSame(ButtonStyleCss::HOVERS, $recipe['hovers']);
        self::assertSame(ButtonStyleCss::PRIMARY_GRADIENT, $recipe['primaryGradient']);
        self::assertSame(ButtonIcons::keys(), array_keys($recipe['icons']));
        self::assertSame(ButtonIcons::maskUrl('external'), $recipe['icons']['external']['mask']);
    }

    // ------------------------------------------------------------ helpers

    private function coreCss(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/core.css'));
    }

    /** @return array<string, string> the --btn-* declarations of the first rule with exactly this selector */
    private function coreRule(string $selector): array
    {
        self::assertSame(1, preg_match('/\n' . preg_quote($selector, '/') . '\{\n(.*?)\n\}/s', $this->coreCss(), $m), $selector);

        $out = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^\s*(--btn-[a-z-]+):\s*(.+);$/', $line, $d) === 1) {
                $out[$d[1]] = $d[2];
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private function ruleIn(string $block, string $selector): array
    {
        self::assertSame(1, preg_match('/(?:^|\n)' . preg_quote($selector, '/') . '\{\n(.*?)\}\n/s', $block, $m), $selector);

        $out = [];
        foreach (explode("\n", trim($m[1])) as $line) {
            if (preg_match('/^\s*([a-z-]+):\s*(.+);$/', $line, $d) === 1) {
                $out[$d[1]] = $d[2];
            }
        }

        return $out;
    }
}
