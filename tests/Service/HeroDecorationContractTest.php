<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockSamples;
use App\Service\HomepageHeroContent;
use PHPUnit\Framework\TestCase;

/**
 * Themes 2.0 phase 1B-b (THEMING.md, "Decoratie van de homepage-opening"):
 * the homepage hero offers neutral decoration hooks, and the stylesheet
 * decides what they look like.
 *
 *   - the renderer prints one canvas with two empty layers, aria-hidden,
 *     with no inline style and no name of an ornament (spark, laser);
 *   - the script finds them by their data hooks only, starts per hero, and
 *     adds no particle when the theme hides the canvas or the particles;
 *   - reduced motion still stops the flourish;
 *   - the default look sits in homepage-hero.css on the neutral classes, and
 *     the canvas never takes a click;
 *   - nothing here knows a site or a theme by name.
 *
 * Pure: no database, no web server. Suites contract, fast and cms.
 */
final class HeroDecorationContractTest extends TestCase
{
    private const PARTIAL = 'partials/section-homepage-hero.php';
    private const SCRIPT = 'assets/js/blocks/homepage-hero.js';
    private const STYLES = 'assets/css/blocks/homepage-hero.css';

    /** Names of one concrete ornament, a site or a theme: none belongs in the hero's code. */
    private const FORBIDDEN = ['spark', 'laser', 'vvld', 'veluw', 'kobold', 'luxe', 'luxury', 'gold', 'forest'];

    public function testTheRendererPrintsOneNeutralCanvasWithTwoLayers(): void
    {
        foreach ([HomepageHeroContent::LAYOUT_MEDIA_RIGHT, HomepageHeroContent::LAYOUT_MEDIA_LEFT, HomepageHeroContent::LAYOUT_BACKGROUND] as $layout) {
            $html = self::sample(['layout' => $layout]);

            $this->assertMatchesRegularExpression(
                '~<section class="hero[^"]*">\s*(<\?php.*?\?>\s*)?<div class="hero-decoration" data-hero-decoration aria-hidden="true">\s*'
                . '<div class="hero-decoration__layer" data-decoration-layer="1"></div>\s*'
                . '<div class="hero-decoration__layer" data-decoration-layer="2"></div>\s*'
                . '</div>\s*<div class="container hero__grid">~s',
                $html,
                "layout {$layout}: the canvas comes first, before the content, with exactly two empty layers"
            );
            $this->assertSame(1, substr_count($html, 'data-hero-decoration'));
            $this->assertSame(2, substr_count($html, 'data-decoration-layer'));
        }
    }

    public function testTheDecorationCarriesNoContentNoStyleAndNoOrnamentName(): void
    {
        $html = self::sample([]);
        $this->assertSame(1, preg_match('~<div class="hero-decoration".*?</div>\s*</div>~s', $html, $m));
        $decoration = $m[0];

        $this->assertStringNotContainsString('style=', $decoration, 'its position belongs to the stylesheet, which a theme can override');
        $this->assertStringNotContainsString('tabindex', $decoration);
        $this->assertStringNotContainsString('alt=', $decoration);
        $this->assertSame('', trim(strip_tags($decoration)), 'no text in the decoration');
        $this->assertDoesNotMatchRegularExpression('~<(h[1-6]|a|button|img|svg)\b~', $decoration);

        foreach (self::FORBIDDEN as $name) {
            $this->assertStringNotContainsStringIgnoringCase($name, $html, "the hero's markup names \"{$name}\"");
        }
    }

    public function testTheCodeNamesNoOrnamentSiteOrTheme(): void
    {
        foreach ([self::PARTIAL, self::SCRIPT, self::STYLES] as $file) {
            $source = self::source($file);
            foreach (self::FORBIDDEN as $name) {
                $this->assertDoesNotMatchRegularExpression('/' . $name . '/i', $source, "{$file} names \"{$name}\"");
            }
        }
    }

    public function testTheScriptUsesTheNeutralHooksPerHero(): void
    {
        $script = self::source(self::SCRIPT);

        $this->assertStringContainsString('querySelector("[data-hero-decoration]")', $script);
        $this->assertStringContainsString('querySelectorAll("[data-decoration-layer]")', $script);
        $this->assertStringContainsString('"hero-decoration__particle"', $script);
        $this->assertStringContainsString('document.querySelectorAll(".hero").forEach(initHeroMotion)', $script, 'one start per hero');
        $this->assertDoesNotMatchRegularExpression('/document\.querySelector\(/', $script, 'no search of the whole document for one hero');
    }

    public function testAThemeThatHidesTheDecorationGetsNoParticles(): void
    {
        $script = self::source(self::SCRIPT);

        $this->assertMatchesRegularExpression('/getComputedStyle\(field\)\.display === "none"\) return;/', $script, 'a hidden canvas gets no particles');
        $this->assertMatchesRegularExpression('/getComputedStyle\(s\)\.display === "none"\)\s*\{\s*field\.removeChild\(s\);\s*return;/', $script, 'hidden particles: the first is taken out again, no tween starts');
    }

    public function testReducedMotionStillStopsTheFlourish(): void
    {
        $script = self::source(self::SCRIPT);

        $this->assertStringContainsString('matchMedia("(prefers-reduced-motion: reduce)")', $script);
        $this->assertMatchesRegularExpression('/function initHeroMotion\(hero\) \{\s*if \(prefersReducedMotion \|\| typeof gsap === "undefined"\)/', $script);
        $this->assertMatchesRegularExpression('/function spawnParticles\(field\) \{\s*if \(!field \|\| prefersReducedMotion \|\| typeof gsap === "undefined"\) return;/', $script);
    }

    public function testTheDefaultLookSitsOnTheNeutralClasses(): void
    {
        $css = self::source(self::STYLES);

        $this->assertMatchesRegularExpression('/\.hero-decoration\{[^}]*position: absolute;[^}]*inset: 0;[^}]*pointer-events: none;[^}]*overflow: hidden;/', $css, 'the canvas covers the hero and never takes a click');
        $this->assertMatchesRegularExpression('/\.hero-decoration__layer:nth-child\(1\)\{ top: 22%; left: 0; width: 38%; \}/', $css);
        $this->assertMatchesRegularExpression('/\.hero-decoration__layer:nth-child\(2\)\{ bottom: 14%; right: 0; width: 26%; \}/', $css);
        $this->assertMatchesRegularExpression('/\.hero-decoration__particle\{/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.hero-decoration[^{]*\{[^}]*pointer-events: auto/', $css);
    }

    public function testTheHeroStillAsksForItsOwnAssets(): void
    {
        $hero = BlockDefinitions::get('homepage_hero');

        $this->assertContains(self::STYLES, $hero->styles());
        $this->assertSame([self::SCRIPT], $hero->scripts());
        $this->assertSame(['gsap'], $hero->vendorScripts());
    }

    /** @param array<string, mixed> $overrides */
    private static function sample(array $overrides): string
    {
        $definition = BlockDefinitions::get('homepage_hero');
        ob_start();
        try {
            $definition->renderSample($overrides + (array) $definition->sampleContent(new BlockSamples()), 'hero-1');
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private static function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}
