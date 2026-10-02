<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Blocks\BlockAppearance;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockSamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Themes 2.0 phase 1B-a (THEMING.md, "Oppervlakken"): a block says which
 * role its root plays on the page, never which colour.
 *
 *   - the blocks with a fixed surface of their own print the role as one
 *     class (`surface-subtle`, `surface-contrast`), and no renderer prints
 *     the old presentation names `bg-soft` or `bg-forest` any more;
 *   - core.css draws each role once, with one class, and takes its fill from
 *     a `--surface-<word>` token in the rule that a page theme recomputes;
 *   - Extra vormgeving's Subtiele achtergrond is that same fill, not a copy;
 *   - a choice in Extra vormgeving keeps the block's role class and wins
 *     with two classes, so an explicit choice beats the block's default;
 *   - the old names stay only as aliases inside those same rules;
 *   - nothing here knows a site or a theme by name.
 *
 * Pure: no database, no web server. Suites contract, fast and cms.
 */
final class SurfaceContractTest extends TestCase
{
    /** Every renderer that prints a surface of its own, and the role it prints. */
    private const RENDERERS = [
        'partials/section-stat-strip.php' => 'surface-contrast',
        'partials/section-card-carousel.php' => 'surface-subtle',
        'partials/section-feature-grid.php' => 'surface-subtle',
        'partials/related-products.php' => 'surface-subtle',
        'partials/section-detail-section.php' => 'surface-subtle',
        'partials/section-item-gallery.php' => 'surface-subtle',
    ];

    /** @return array<string, array{0: string, 1: string}> */
    public static function samples(): array
    {
        return [
            'Cijferbalk' => ['stat_strip', 'surface-contrast'],
            'Kaarten-carrousel' => ['card_carousel', 'surface-subtle'],
            'Feature grid with a heading' => ['feature_grid', 'surface-subtle'],
        ];
    }

    #[DataProvider('samples')]
    public function testABlockPrintsItsRoleOnItsOwnRoot(string $type, string $role): void
    {
        $html = self::sample($type);

        $this->assertMatchesRegularExpression('/^\s*<section class="' . $role . '">/', $html, "{$type}: the role, as the one class of its root");
        $this->assertStringNotContainsString('bg-soft', $html);
        $this->assertStringNotContainsString('bg-forest', $html);
    }

    public function testRelatedProductsPrintTheSubtleRole(): void
    {
        require_once dirname(__DIR__, 2) . '/partials/related-products.php';

        ob_start();
        try {
            render_related_products(['product_ids' => [1, 2], 'heading' => 'Bekijk ook']);
        } finally {
            $html = (string) ob_get_clean();
        }

        $this->assertMatchesRegularExpression('/<section class="surface-subtle" data-related-products>/', $html);
    }

    public function testNoRendererPrintsTheOldPresentationNames(): void
    {
        foreach (self::RENDERERS as $path => $role) {
            $source = self::file($path);
            $this->assertStringContainsString($role, $source, "{$path}: prints its role");
            $this->assertDoesNotMatchRegularExpression('/\bbg-(soft|forest)\b/', $source, "{$path}: no presentation name, not even in a comment");
        }

        foreach (glob(dirname(__DIR__, 2) . '/*.php') ?: [] as $template) {
            $this->assertDoesNotMatchRegularExpression('/\bbg-(soft|forest)\b/', (string) file_get_contents($template), basename($template));
        }
    }

    public function testCoreDrawsEachRoleOnceFromItsToken(): void
    {
        $css = self::stripComments(self::file('assets/css/core.css'));

        $this->assertMatchesRegularExpression('/\.surface-subtle,\s*\.bg-soft\{[^}]*background: var\(--surface-subtle\);/', $css, 'one rule, the alias beside it');
        $this->assertMatchesRegularExpression('/\.surface-contrast,\s*\.bg-forest\{[^}]*background: var\(--surface-contrast\);/', $css);
        $this->assertMatchesRegularExpression('/\.surface-contrast::before,\s*\.bg-forest::before\{/', $css, 'the hairline belongs to the role');

        // The fills are built from palette tokens, so they live in the rule a
        // page theme recomputes inside its <main> (PageThemeCssContractTest).
        $this->assertMatchesRegularExpression('/:root,\s*main\[data-page-theme\]\{[^}]*--surface-subtle:\s*radial-gradient[^}]*--surface-contrast: var\(--color-bg-deep\);/s', $css);

        // An alias never has a rule of its own: it can only ever be the role.
        $this->assertDoesNotMatchRegularExpression('/(^|\})\s*\.bg-(soft|forest)[^,{]*\{/', $css);
    }

    public function testTheChosenSubtleBackgroundIsTheSameFill(): void
    {
        $css = self::stripComments(self::file(BlockAppearance::STYLESHEET));

        $this->assertMatchesRegularExpression('/\.block-appearance\.block-appearance--bg-subtle\{\s*background: var\(--surface-subtle\);\s*\}/', $css);
        $this->assertStringNotContainsString('radial-gradient', $css, 'no second copy of a surface');
    }

    public function testAnExplicitChoiceKeepsTheRoleAndWins(): void
    {
        $html = self::sample('stat_strip');
        $chosen = BlockAppearance::apply($html, ['background' => 'page', 'border' => 'none'] + BlockAppearance::defaults());

        $this->assertMatchesRegularExpression('/^\s*<section class="surface-contrast block-appearance block-appearance--bg-page block-appearance--border-none">/', $chosen);

        $css = self::stripComments(self::file(BlockAppearance::STYLESHEET));
        $this->assertStringContainsString('.block-appearance.block-appearance--bg-page{', $css, 'two classes against the one of a role');
        $this->assertStringContainsString('.block-appearance.block-appearance--border-none.surface-contrast::before', $css, 'a chosen border also replaces the hairline of the role');
    }

    public function testNoSiteOrThemeIsKnownByName(): void
    {
        $surfaces = self::file('assets/css/core.css');
        $start = (int) strpos($surfaces, '--- Surfaces');
        $surfaces = substr($surfaces, $start, (int) strpos($surfaces, '/* Value props', $start) - $start);

        $sources = ['core.css surfaces' => $surfaces, BlockAppearance::STYLESHEET => self::file(BlockAppearance::STYLESHEET)];
        foreach (array_keys(self::RENDERERS) as $path) {
            $sources[$path] = self::file($path);
        }

        foreach ($sources as $name => $source) {
            $this->assertNotSame('', $source, $name);
            $this->assertDoesNotMatchRegularExpression('/kobold|luxury|luxe|vvld|van ?veluw/i', $source, $name);
        }
    }

    private static function sample(string $type): string
    {
        $definition = BlockDefinitions::get($type);
        ob_start();
        try {
            $definition->renderSample((array) $definition->sampleContent(new BlockSamples()), $type . '-1');
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private static function file(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    private static function stripComments(string $css): string
    {
        return preg_replace('~/\*.*?\*/~s', '', $css) ?? '';
    }
}
