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
 *   - precedence (phase 1B-a.1): a background chosen in Extra vormgeving
 *     takes the block's role off its root, so nothing the role draws stays
 *     under it; Standaard, and a choice of lines, room or effect only, keep
 *     the role, and no stylesheet resets a role per chosen background;
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

    /**
     * Every block with Extra vormgeving that prints a role of its own, with
     * the markup it prints that role in: its sample, or the sample in the
     * position or old setting that gives it the role.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function roleBlocks(): array
    {
        return [
            'Cijferbalk' => ['stat_strip', 'surface-contrast'],
            'Kaarten-carrousel' => ['card_carousel', 'surface-subtle'],
            'Feature grid with a heading' => ['feature_grid', 'surface-subtle'],
            'Detailsectie, every second one' => ['detail_section_odd', 'surface-subtle'],
            'Galerij with the old soft background' => ['item_gallery_soft', 'surface-subtle'],
        ];
    }

    #[DataProvider('roleBlocks')]
    public function testAChosenBackgroundTakesTheRoleOffTheRoot(string $block, string $role): void
    {
        $html = self::roleSample($block);
        $this->assertContains($role, self::rootClasses($html), "{$block}: the role it starts with");

        foreach (array_diff(BlockAppearance::BACKGROUNDS, ['default']) as $background) {
            $chosen = BlockAppearance::apply($html, ['background' => $background] + BlockAppearance::defaults());
            $classes = self::rootClasses($chosen);

            $this->assertContains('block-appearance--bg-' . $background, $classes, "{$block} + {$background}");
            $this->assertSame([], array_values(array_filter($classes, static fn (string $class): bool => str_starts_with($class, 'surface-'))), "{$block} + {$background}: no role left beside the choice");
            $this->assertSame(substr($html, (int) strpos($html, '>') + 1), substr($chosen, (int) strpos($chosen, '>') + 1), "{$block} + {$background}: only the root's classes change");
        }

        // Together with lines, room and an effect: the background still decides.
        $mixed = BlockAppearance::apply($html, ['background' => 'page', 'border' => 'top', 'border_tone' => 'normal', 'spacing' => 'spacious', 'decoration' => 'glow'] + BlockAppearance::defaults());
        $classes = self::rootClasses($mixed);
        $this->assertNotContains($role, $classes);
        foreach (['block-appearance--bg-page', 'block-appearance--border-top', 'block-appearance--line-normal', 'block-appearance--space-spacious', 'block-appearance--decor-glow'] as $class) {
            $this->assertContains($class, $classes, "{$block}: {$class} stays");
        }
    }

    #[DataProvider('roleBlocks')]
    public function testOnlyABackgroundTakesTheRoleOff(string $block, string $role): void
    {
        $html = self::roleSample($block);

        $this->assertSame($html, BlockAppearance::apply($html, BlockAppearance::defaults()), "{$block}: Standaard is the block as it was");

        $others = [
            'lines only' => ['border' => 'both', 'border_tone' => 'accent'],
            'no lines' => ['border' => 'none'],
            'room only' => ['spacing' => 'spacious'],
            'effect only' => ['decoration' => 'glow'],
            'lines, room and effect' => ['border' => 'bottom', 'spacing' => 'compact', 'decoration' => 'pattern'],
        ];
        foreach ($others as $name => $look) {
            $classes = self::rootClasses(BlockAppearance::apply($html, $look + BlockAppearance::defaults()));
            $this->assertContains($role, $classes, "{$block}, {$name}: the role stays");
            $this->assertContains('block-appearance', $classes, "{$block}, {$name}");
        }
    }

    /**
     * The rule itself, on markup no block prints: apply() knows the role
     * words and nothing else. Every other class of the root stays, in its
     * order, and a class that only starts like a role is not one.
     */
    public function testTheRoleIsTheOnlyClassAChosenBackgroundRemoves(): void
    {
        $html = '<section class="intro surface-contrast surface-subtle-note" id="a" data-x="1"><div class="container surface-subtle"></div></section>';

        $this->assertSame(
            '<section class="intro surface-subtle-note block-appearance block-appearance--bg-transparent" id="a" data-x="1"><div class="container surface-subtle"></div></section>',
            BlockAppearance::apply($html, ['background' => 'transparent'] + BlockAppearance::defaults()),
            'the root loses its role; a child is not the root'
        );
        $this->assertSame(
            '<section class="intro surface-contrast surface-subtle-note block-appearance block-appearance--border-none" id="a" data-x="1"><div class="container surface-subtle"></div></section>',
            BlockAppearance::apply($html, ['border' => 'none'] + BlockAppearance::defaults())
        );

        // The vocabulary of THEMING.md: every background word plus contrast.
        $expected = array_map(static fn (string $word): string => 'surface-' . $word, array_merge(array_diff(BlockAppearance::BACKGROUNDS, ['default']), ['contrast']));
        $roles = BlockAppearance::SURFACE_ROLES;
        sort($expected);
        sort($roles);
        $this->assertSame($expected, $roles);
    }

    /**
     * The precedence lives in the markup, so the stylesheet needs no reset
     * per role and chosen background, and the shared code knows no block.
     */
    public function testThePrecedenceNeedsNoResetsAndNoBlockNames(): void
    {
        $css = self::stripComments(self::file(BlockAppearance::STYLESHEET));

        $this->assertDoesNotMatchRegularExpression('/block-appearance--bg-[a-z]+[^,{]*\.(surface-|bg-soft|bg-forest)/', $css, 'no background-and-role reset');
        $this->assertDoesNotMatchRegularExpression('/\.(surface-[a-z]+|bg-soft|bg-forest)[^,{]*block-appearance--bg-/', $css, 'no background-and-role reset');
        $this->assertStringContainsString('.block-appearance.block-appearance--bg-page{', $css, "two classes against the one of a component's own background");
        $this->assertStringContainsString('.block-appearance.block-appearance--border-none.surface-contrast::before', $css, 'a chosen border, with the role still there, also replaces its hairline');

        $this->assertDoesNotMatchRegularExpression('/[\'"](stat_strip|card_carousel|feature_grid|detail_section|item_gallery)[\'"]/', self::file('src/Service/Blocks/BlockAppearance.php'), 'no block type in the shared code');
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

    private static function roleSample(string $block): string
    {
        if ($block === 'detail_section_odd') {
            require_once dirname(__DIR__, 2) . '/partials/section-detail-section.php';
            $content = (array) BlockDefinitions::get('detail_section')->sampleContent(new BlockSamples());
            ob_start();
            try {
                render_section_detail_section($content, ['index_label' => '02', 'bg_soft' => true], 'detail_section-2');
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        }

        if ($block === 'item_gallery_soft') {
            $definition = BlockDefinitions::get('item_gallery');
            $content = ['background' => 'soft'] + (array) $definition->sampleContent(new BlockSamples());
            ob_start();
            try {
                $definition->renderSample($content, 'item_gallery-2');
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        }

        return self::sample($block);
    }

    /** @return list<string> the classes of the markup's first element */
    private static function rootClasses(string $html): array
    {
        if (!preg_match('/^\s*<[a-z]+\b[^>]*?\sclass="([^"]*)"/', $html, $match)) {
            return [];
        }

        return array_values(array_filter(explode(' ', $match[1])));
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
