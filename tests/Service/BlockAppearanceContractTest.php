<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\Blocks\AppearanceSupport;
use App\Service\Blocks\BlockAppearance;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockSamples;
use App\Service\PageAssets;
use App\Service\SectionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Extra vormgeving (Contentblock Styling 1.0, CONTENT-BLOCKS.md "Extra
 * vormgeving") without a database or a server:
 *
 *   - the closed lists, their defaults and the validation of a submitted
 *     look against them and against what the block supports;
 *   - the classes a look becomes, and that 'Standaard' becomes nothing;
 *   - apply() puts them on the block's own root, next to its own classes and
 *     style, draws the effect as that root's first child, and leaves markup
 *     without a root (a hidden block) alone;
 *   - every supporting block's real markup (its sample) has such a root;
 *   - the capability of every block type is the one written down here, so a
 *     change is a decision, not an accident;
 *   - the stylesheets use theme tokens only, keep an effect inside its block,
 *     away from the pointer, still for reduced motion, and never set a height.
 */
final class BlockAppearanceContractTest extends TestCase
{
    /**
     * The capability of every block type that has one: background, borders,
     * spacing, effects. Every other registered block supports nothing.
     *
     * @var array<string, array{0: bool, 1: bool, 2: bool, 3: list<string>}>
     */
    private const SUPPORT = [
        'rich_text' => [true, true, true, ['sparks', 'glow', 'pattern']],
        'cta_band' => [true, true, true, ['sparks', 'glow', 'pattern']],
        'stat_strip' => [true, true, true, ['sparks', 'glow', 'pattern']],
        'step_list' => [true, true, true, ['sparks', 'glow', 'pattern']],
        'page_hero' => [true, true, false, ['sparks', 'glow', 'pattern']],
        'text_image_split' => [true, true, true, ['glow', 'pattern']],
        'feature_grid' => [true, true, true, ['glow', 'pattern']],
        'faq' => [true, true, true, ['glow', 'pattern']],
        'card_carousel' => [true, true, true, ['glow', 'pattern']],
        'item_gallery' => [true, true, true, ['glow', 'pattern']],
        'project_cards' => [true, true, true, ['glow', 'pattern']],
        'project_images' => [true, true, true, ['glow', 'pattern']],
        'hover_card_grid' => [true, true, true, ['glow', 'pattern']],
        'detail_section' => [true, true, true, ['glow', 'pattern']],
        'featured_product' => [true, true, true, ['glow', 'pattern']],
        // The Shop's listings with their optional head (v0.1.15): a grid of
        // things to click, so no sparks moving behind it.
        'product_grid' => [true, true, true, ['glow', 'pattern']],
        'shop_collections' => [true, true, true, ['glow', 'pattern']],
        'form' => [true, true, true, ['glow', 'pattern']],
        // Reviews 1.0: every effect; the carousel's stylesheet leaves the
        // sparks out behind its moving strip (reviews.css).
        'reviews' => [true, true, true, ['sparks', 'glow', 'pattern']],
        'media_banner' => [true, true, true, []],
        'marquee' => [true, true, false, []],
    ];

    protected function setUp(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'portfolio' => true, 'blog' => true, 'personalization' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        SectionRegistry::reset();
        PageAssets::reset();
    }

    protected function tearDown(): void
    {
        PageAssets::reset();
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        SectionRegistry::reset();
    }

    // ------------------------------------------------------------ storage contract

    public function testTheDefaultsAreTheBlockAsItWas(): void
    {
        $this->assertSame(
            ['background' => 'default', 'border' => 'default', 'border_tone' => 'subtle', 'spacing' => 'default', 'decoration' => 'none'],
            BlockAppearance::defaults()
        );
        $this->assertSame(BlockAppearance::defaults(), BlockAppearance::fromRow([]), 'a row from before the migration');
        $this->assertTrue(BlockAppearance::isDefault(BlockAppearance::defaults()));
        $this->assertSame([], BlockAppearance::classes(BlockAppearance::defaults()));
        $this->assertTrue(BlockAppearance::isDefault(['border_tone' => 'accent'] + BlockAppearance::defaults()), 'a line colour alone draws no line');
        $this->assertSame(['default', 'page', 'subtle', 'primary', 'secondary', 'transparent'], BlockAppearance::BACKGROUNDS);
        $this->assertSame(['default', 'none', 'top', 'bottom', 'both'], BlockAppearance::BORDERS);
        $this->assertSame(['default', 'compact', 'normal', 'spacious', 'extra'], BlockAppearance::SPACINGS);
        $this->assertSame(['none', 'sparks', 'glow', 'pattern'], BlockAppearance::DECORATIONS);
    }

    public function testAnUnknownStoredWordReadsAsTheDefault(): void
    {
        $row = [
            'appearance_background' => '#ff0000',
            'appearance_border' => 'dashed',
            'appearance_border_tone' => 'red',
            'appearance_spacing' => '999px',
            'appearance_decoration' => 'confetti',
        ];

        $this->assertSame(BlockAppearance::defaults(), BlockAppearance::fromRow($row));
    }

    public function testAValidLookIsAccepted(): void
    {
        $look = ['background' => 'secondary', 'border' => 'both', 'border_tone' => 'accent', 'spacing' => 'spacious', 'decoration' => 'sparks'];
        $checked = BlockAppearance::validate($look, AppearanceSupport::section());

        $this->assertSame([], $checked['errors']);
        $this->assertSame($look, $checked['values']);
    }

    public function testAMissingFieldIsItsDefault(): void
    {
        $checked = BlockAppearance::validate(['background' => 'subtle'], AppearanceSupport::section());

        $this->assertSame(['background' => 'subtle'] + BlockAppearance::defaults(), $checked['values']);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function forgedValues(): iterable
    {
        yield 'unknown background' => ['background', 'rainbow'];
        yield 'css in a background' => ['background', 'red;background:url(//evil.example/x)'];
        yield 'a colour code' => ['background', '#ff0000'];
        yield 'a closing tag' => ['background', '"><script>alert(1)</script>'];
        yield 'unknown border' => ['border', 'dashed'];
        yield 'css in a border tone' => ['border_tone', 'red}body{display:none'];
        yield 'a length as spacing' => ['spacing', '200px'];
        yield 'unknown effect' => ['decoration', 'confetti'];
        yield 'an array' => ['decoration', ['sparks']];
        yield 'case' => ['decoration', 'SPARKS'];
    }

    #[DataProvider('forgedValues')]
    public function testAForgedValueIsRefusedAndNothingIsReturnedToStore(string $field, mixed $value): void
    {
        $checked = BlockAppearance::validate([$field => $value], AppearanceSupport::section());

        $this->assertNull($checked['values']);
        $this->assertNotSame([], $checked['errors']);
    }

    public function testWhatABlockDoesNotSupportIsRefused(): void
    {
        $noSparks = AppearanceSupport::section(['glow', 'pattern']);
        $this->assertNull(BlockAppearance::validate(['decoration' => 'sparks'], $noSparks)['values'], 'an effect the block does not offer');
        $this->assertSame('glow', BlockAppearance::validate(['decoration' => 'glow'], $noSparks)['values']['decoration'] ?? null);

        $header = AppearanceSupport::only(true, true, false, ['sparks']);
        $this->assertNull(BlockAppearance::validate(['spacing' => 'compact'], $header)['values'], 'no room on a page header');
        $this->assertNotNull(BlockAppearance::validate(['spacing' => 'default'], $header)['values'], 'the default is always allowed');

        $none = AppearanceSupport::none();
        foreach (['background' => 'page', 'border' => 'top', 'border_tone' => 'accent', 'spacing' => 'extra', 'decoration' => 'glow'] as $field => $word) {
            $this->assertNull(BlockAppearance::validate([$field => $word], $none)['values'], $field);
        }
    }

    public function testThePageDrawsOnlyWhatTheBlockSupports(): void
    {
        $row = ['appearance_background' => 'page', 'appearance_spacing' => 'extra', 'appearance_decoration' => 'sparks'];
        $effective = BlockAppearance::effective($row, AppearanceSupport::only(true, false, false, ['glow']));

        $this->assertSame(['background' => 'page'] + BlockAppearance::defaults(), $effective);
        $this->assertNull(BlockAppearance::forSection(['section_type' => 'spacer'] + $row, BlockDefinitions::get('spacer')), 'a block without support draws nothing');
        $this->assertNull(BlockAppearance::forSection(['section_type' => 'homepage_hero'] + $row, BlockDefinitions::get('homepage_hero')));
    }

    // ------------------------------------------------------------ classes and markup

    /** @return iterable<string, array{array<string, string>, list<string>}> */
    public static function looks(): iterable
    {
        yield 'website background' => [['background' => 'page'], ['block-appearance', 'block-appearance--bg-page']];
        yield 'subtle' => [['background' => 'subtle'], ['block-appearance', 'block-appearance--bg-subtle']];
        yield 'primary' => [['background' => 'primary'], ['block-appearance', 'block-appearance--bg-primary']];
        yield 'secondary' => [['background' => 'secondary'], ['block-appearance', 'block-appearance--bg-secondary']];
        yield 'transparent' => [['background' => 'transparent'], ['block-appearance', 'block-appearance--bg-transparent']];
        yield 'border top' => [['border' => 'top'], ['block-appearance', 'block-appearance--border-top', 'block-appearance--line-subtle']];
        yield 'border bottom' => [['border' => 'bottom', 'border_tone' => 'normal'], ['block-appearance', 'block-appearance--border-bottom', 'block-appearance--line-normal']];
        yield 'both' => [['border' => 'both', 'border_tone' => 'accent'], ['block-appearance', 'block-appearance--border-both', 'block-appearance--line-accent']];
        yield 'no border' => [['border' => 'none', 'border_tone' => 'accent'], ['block-appearance', 'block-appearance--border-none']];
        yield 'compact' => [['spacing' => 'compact'], ['block-appearance', 'block-appearance--space-compact']];
        yield 'normal' => [['spacing' => 'normal'], ['block-appearance', 'block-appearance--space-normal']];
        yield 'spacious' => [['spacing' => 'spacious'], ['block-appearance', 'block-appearance--space-spacious']];
        yield 'extra' => [['spacing' => 'extra'], ['block-appearance', 'block-appearance--space-extra']];
        yield 'sparks' => [['decoration' => 'sparks'], ['block-appearance', 'block-appearance--decor-sparks']];
        yield 'glow' => [['decoration' => 'glow'], ['block-appearance', 'block-appearance--decor-glow']];
        yield 'pattern' => [['decoration' => 'pattern'], ['block-appearance', 'block-appearance--decor-pattern']];
    }

    /**
     * @param array<string, string> $look
     * @param list<string> $classes
     */
    #[DataProvider('looks')]
    public function testEveryChoiceIsOneClassWithARule(array $look, array $classes): void
    {
        $values = $look + BlockAppearance::defaults();
        $this->assertSame($classes, BlockAppearance::classes($values));

        $css = self::css(BlockAppearance::STYLESHEET) . self::css(BlockAppearance::DECORATION_STYLESHEET);
        foreach (array_slice($classes, 1) as $class) {
            $this->assertStringContainsString('.' . $class, $css, "{$class} has a rule");
        }
    }

    public function testApplyAddsTheClassesToTheRootAndKeepsItsOwn(): void
    {
        $html = "\n    <section class=\"bg-soft\" style=\"padding-top:0;\" data-x=\"a&gt;b\">\n      <div class=\"container\">x</div>\n    </section>\n";
        $out = BlockAppearance::apply($html, ['background' => 'page', 'border' => 'top'] + BlockAppearance::defaults());

        $this->assertStringStartsWith("\n    <section class=\"bg-soft block-appearance block-appearance--bg-page block-appearance--border-top block-appearance--line-subtle\" style=\"padding-top:0;\" data-x=\"a&gt;b\">\n      <div class=\"container\">", $out);
        $this->assertSame(1, substr_count($out, '<section'), 'no wrapper, no second section');
        $this->assertStringNotContainsString('block-decor', $out, 'no layer without an effect');
    }

    public function testApplyGivesARootWithoutClassesOne(): void
    {
        $out = BlockAppearance::apply('<section id="x"><div class="container"></div></section>', ['spacing' => 'compact'] + BlockAppearance::defaults());

        $this->assertStringStartsWith('<section class="block-appearance block-appearance--space-compact" id="x">', $out);
    }

    public function testTheEffectIsTheRootsFirstChildHiddenFromAssistiveTechnology(): void
    {
        $sparks = BlockAppearance::apply('<section class="a"><div class="container">x</div></section>', ['decoration' => 'sparks'] + BlockAppearance::defaults());
        $this->assertStringStartsWith('<section class="a block-appearance block-appearance--decor-sparks"><div class="block-decor block-decor--sparks" aria-hidden="true">' . str_repeat('<span></span>', 14) . '</div><div class="container">', $sparks);

        $glow = BlockAppearance::apply('<section><div class="container">x</div></section>', ['decoration' => 'glow'] + BlockAppearance::defaults());
        $this->assertStringContainsString('<div class="block-decor block-decor--glow" aria-hidden="true"></div><div class="container">', $glow);
        $this->assertStringNotContainsString('<span', $glow, 'glow is one element');
        $this->assertStringNotContainsString('style=', $sparks . $glow, 'no inline style');
        $this->assertStringNotContainsString('<script', $sparks . $glow);
    }

    public function testAHiddenOrEmptyBlockStaysEmpty(): void
    {
        $look = ['background' => 'primary', 'border' => 'both', 'decoration' => 'sparks'] + BlockAppearance::defaults();

        $this->assertSame('', BlockAppearance::apply('', $look));
        $this->assertSame("\n  \n", BlockAppearance::apply("\n  \n", $look));
        $this->assertSame('<!-- x --><section></section>', BlockAppearance::apply('<!-- x --><section></section>', $look), 'no root first: untouched');
    }

    public function testTheDefaultLookLeavesMarkupByteForByte(): void
    {
        $html = '<section class="rich-text-section"><div class="container">x</div></section>';

        $this->assertSame($html, BlockAppearance::apply($html, BlockAppearance::defaults()));
    }

    // ------------------------------------------------------------ capabilities per block

    public function testEveryBlockDeclaresExactlyTheCapabilityWrittenDownHere(): void
    {
        foreach (BlockDefinitions::all() as $type => $definition) {
            $support = $definition->appearanceSupport();
            $expected = self::SUPPORT[$type] ?? [false, false, false, []];

            $this->assertSame(
                $expected,
                [$support->background, $support->borders, $support->spacing, $support->decorations],
                "{$type}: a capability change is a decision (CONTENT-BLOCKS.md, Extra vormgeving)"
            );
        }

        $this->assertSame(['sparks', 'glow', 'pattern'], BlockAppearance::effects());
    }

    /** @return iterable<string, array{string}> */
    public static function supportingTypes(): iterable
    {
        foreach (array_keys(self::SUPPORT) as $type) {
            yield $type => [$type];
        }
    }

    /**
     * The real markup of every supporting block (its sample, through its own
     * partial) has a root apply() can style, and — for a block that offers
     * an effect — its content in a `.container` right under that root, which
     * the stylesheet lifts above the effect.
     */
    #[DataProvider('supportingTypes')]
    public function testEverySupportingBlockHasARootToStyle(string $type): void
    {
        if (!BlockDefinitions::has($type)) {
            $this->markTestSkipped("{$type} is not registered here");
        }

        $definition = BlockDefinitions::get($type);
        $sample = $definition->sampleContent(new BlockSamples());

        ob_start();
        try {
            if ($sample !== null) {
                $definition->renderSample($sample, $type . '-preview');
            } else {
                // The one block without a sample (the Productgrid, whose cards
                // the browser draws): rendered as it is without a row of its
                // own, which reads nothing from the database.
                $this->assertSame('product_grid', $type, "{$type} has a sample");
                $definition->render(['id' => 0, 'section_type' => $type, 'page_slug' => '', 'section_key' => '', 'section_id' => 0], false, $type . '-preview');
            }
        } finally {
            $html = (string) ob_get_clean();
        }

        $support = $definition->appearanceSupport();
        $look = ['background' => 'page', 'border' => 'both'] + BlockAppearance::defaults();
        if ($support->decorations !== []) {
            $look['decoration'] = $support->decorations[0];
        }

        $styled = BlockAppearance::apply($html, $look);

        $this->assertNotSame($html, $styled, "{$type}: its root was found");
        $this->assertMatchesRegularExpression('/^\s*<(section|div)\b[^>]*class="[^"]*block-appearance--bg-page/', $styled, "{$type}: on the root");

        if ($support->decorations === []) {
            return;
        }

        $this->assertMatchesRegularExpression('/^\s*<section\b[^>]*><div class="block-decor[^"]*" aria-hidden="true">(?:<span><\/span>)*<\/div>/', $styled, "{$type}: the effect is the section's first child");

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $styled . '</body>');
        libxml_clear_errors();
        $root = (new \DOMXPath($document))->query('//body/*[1]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $root);

        $childClasses = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $childClasses[] = $child->getAttribute('class');
            }
        }
        $this->assertNotEmpty(
            array_filter($childClasses, static fn (string $class): bool => in_array('container', explode(' ', $class), true)),
            "{$type}: its content is in a .container right under the root, which the effect stays under"
        );
    }

    // ------------------------------------------------------------ the blocks the brief names

    /**
     * The Kaarten-carrousel's own surface is the fixed `.surface-subtle` on
     * its <section> (partials/section-card-carousel.php). Standaard keeps it;
     * Websiteachtergrond takes its place on the root, so the carousel takes
     * the page's ground and nothing of the soft surface stays
     * (SurfaceContractTest, the precedence).
     */
    public function testTheCarouselKeepsItsSoftSurfaceUntilAnotherIsChosen(): void
    {
        $definition = BlockDefinitions::get('card_carousel');
        ob_start();
        try {
            $definition->renderSample((array) $definition->sampleContent(new BlockSamples()), 'card_carousel-1');
        } finally {
            $html = (string) ob_get_clean();
        }

        $this->assertMatchesRegularExpression('/^\s*<section class="surface-subtle">/', $html, 'the cause of its different background');
        $this->assertSame($html, BlockAppearance::apply($html, BlockAppearance::defaults()), 'Standaard: as it was');

        $page = BlockAppearance::apply($html, ['background' => 'page'] + BlockAppearance::defaults());
        $this->assertMatchesRegularExpression('/^\s*<section class="block-appearance block-appearance--bg-page">/', $page);

        $transparent = BlockAppearance::apply($html, ['background' => 'transparent', 'border' => 'none'] + BlockAppearance::defaults());
        $this->assertStringContainsString('block-appearance--bg-transparent block-appearance--border-none', $transparent, 'no surface and no lines: the page around it');
    }

    /**
     * CTA Background Height: the minimum height stays on the box that carries
     * the layers, its classes and its one custom property are untouched, and
     * a look or an effect sits next to them.
     */
    public function testTheCtaMinimumHeightSurvivesALookAndAnEffect(): void
    {
        require_once dirname(__DIR__, 2) . '/partials/section-cta-band.php';

        $band = [
            'state' => \App\Service\CtaBandContent::STATE_ACTIVE,
            'eyebrow' => 'Samen', 'title' => 'Kort', 'lead' => 'Wij helpen graag.',
            'primary_label' => 'Neem contact op', 'primary_url' => '/contact',
            'full_width' => true,
        ] + \App\Service\CtaBandContent::minHeight(['min_height' => 'custom', 'min_height_px' => 480, 'mobile_min_height' => 'tall']);

        ob_start();
        try {
            render_section_cta_band($band);
        } finally {
            $html = (string) ob_get_clean();
        }

        $this->assertMatchesRegularExpression('/<section class="cta-section cta-section--full cta-height cta-height--custom cta-height-phone--tall" style="--cta-min-height: 480px;">/', $html);

        $styled = BlockAppearance::apply($html, ['background' => 'secondary', 'decoration' => 'sparks'] + BlockAppearance::defaults());
        $this->assertMatchesRegularExpression('/<section class="cta-section cta-section--full cta-height cta-height--custom cta-height-phone--tall block-appearance block-appearance--bg-secondary block-appearance--decor-sparks" style="--cta-min-height: 480px;"><div class="block-decor block-decor--sparks"/', $styled);
        $this->assertSame(substr_count($html, 'data-reveal'), substr_count($styled, 'data-reveal'));
        $this->assertStringNotContainsString('min-height', self::stripComments(self::css(BlockAppearance::STYLESHEET) . self::css(BlockAppearance::DECORATION_STYLESHEET)), 'nothing here competes with the minimum height');
    }

    // ------------------------------------------------------------ stylesheets

    public function testTheStylesheetsUseThemeTokensAndNoColourCodes(): void
    {
        foreach ([BlockAppearance::STYLESHEET, BlockAppearance::DECORATION_STYLESHEET] as $path) {
            $css = self::stripComments(self::css($path));

            $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b(?![^{]*\{)/', preg_replace('/mask-image:[^;]+;/', '', $css) ?? '', "{$path}: no colour codes");
            $this->assertStringContainsString('var(--color-', $css);
            $this->assertStringNotContainsString(':root', $css, "{$path}: nothing global");
            $this->assertDoesNotMatchRegularExpression('/\.site-(header|footer)|\bheader\b|\bfooter\b|\bbody\b/', $css, "{$path}: never the header or footer");
        }

        $appearance = self::stripComments(self::css(BlockAppearance::STYLESHEET));
        foreach (['bg-page' => '--color-bg', 'bg-primary' => '--color-primary-rgb', 'bg-secondary' => '--color-surface', 'line-normal' => '--color-line', 'line-accent' => '--color-primary'] as $class => $token) {
            $this->assertMatchesRegularExpression('/block-appearance--' . $class . '\{[^}]*var\(' . preg_quote($token, '/') . '\)/', str_replace([' ', "\n", "\r"], '', $appearance), "{$class} reads {$token}");
        }
    }

    public function testAChosenLookWinsOverTheBlocksOwnSurfaceAndNeverSetsAHeight(): void
    {
        $css = self::stripComments(self::css(BlockAppearance::STYLESHEET));

        // Two classes against the one class of .surface-subtle, .surface-contrast and .cta-section--full.
        preg_match_all('/([^{}]+)\{/', $css, $selectors);
        foreach ($selectors[1] as $selector) {
            foreach (explode(',', $selector) as $one) {
                $one = trim($one);
                if ($one === '' || str_starts_with($one, '@')) {
                    continue;
                }
                if ($one === '.block-appearance') {
                    continue;
                }
                $this->assertStringStartsWith('.block-appearance.block-appearance--', $one, 'two classes: ' . $one);
            }
        }

        $this->assertDoesNotMatchRegularExpression('/(?<![-\w])(min-|max-)?height\s*:/', $css, 'the CTA minimum height and every block height stay the block\'s own');
        $this->assertStringContainsString('content: none', $css, 'the figures band\'s own hairline goes with a chosen border');
    }

    public function testAnEffectStaysInsideItsBlockUnderTheContentAndAwayFromThePointer(): void
    {
        $css = str_replace(["\r\n"], "\n", self::stripComments(self::css(BlockAppearance::DECORATION_STYLESHEET)));

        $this->assertMatchesRegularExpression('/\.block-decor\{[^}]*position:\s*absolute;[^}]*inset:\s*0;[^}]*z-index:\s*1;[^}]*overflow:\s*hidden;[^}]*pointer-events:\s*none;/', $css);
        $this->assertMatchesRegularExpression('/\.block-decor \*\{\s*pointer-events:\s*none;\s*\}/', $css);
        $this->assertMatchesRegularExpression('/--decor-sparks > \.container,[^{]*\{[^}]*position:\s*relative;[^}]*z-index:\s*2;/', $css, 'the content above the effect');
        $this->assertMatchesRegularExpression('/isolation:\s*isolate/', $css, 'the block is its own stacking context');
        $this->assertStringNotContainsString('100vw', $css, 'no horizontal overflow');
        $this->assertStringNotContainsString('position: fixed', $css);
        $this->assertStringNotContainsString('translateX', $css, 'the sparks only fall');
    }

    public function testReducedMotionStandsStill(): void
    {
        $css = self::stripComments(self::css(BlockAppearance::DECORATION_STYLESHEET));

        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\)\{\s*\.block-decor--sparks span\{[^}]*animation:\s*none;[^}]*opacity:\s*0\.35;/', $css);
        $this->assertSame(1, preg_match_all('/animation\s*:/', preg_replace('/@media \(prefers-reduced-motion: reduce\)\{.*$/s', '', $css) ?? ''), 'one animation, the sparks');
        $this->assertStringContainsString('span:nth-child(n+7){ display: none; }', $css, 'six sparks on a phone, as the hero');
        $this->assertSame(BlockAppearance::SPARK_COUNT, preg_match_all('/span:nth-child\(\d+\)\{/', $css), 'each spark has its own place');
    }

    public function testNoScriptAndOnlyTheStylesheetsThePageNeeds(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2) . '/' . BlockAppearance::STYLESHEET);
        $this->assertFileExists(dirname(__DIR__, 2) . '/' . BlockAppearance::DECORATION_STYLESHEET);
        $this->assertStringNotContainsString('requireScript', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/Blocks/BlockAppearance.php'), 'the effects need no script');
        $this->assertStringNotContainsString('requireVendorScript', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/Blocks/BlockAppearance.php'), 'and no library');

        $plain = ['section_type' => 'rich_text'];
        BlockAppearance::collectAssets([$plain, ['section_type' => 'spacer', 'appearance_decoration' => 'sparks']]);
        $this->assertSame([], array_values(array_filter(PageAssets::collected()['styles'] ?? [], static fn (string $s): bool => str_contains($s, 'block-'))), 'nothing styled, nothing loaded (a spacer cannot carry sparks)');

        PageAssets::reset();
        BlockAppearance::collectAssets([$plain + ['appearance_background' => 'subtle']]);
        $styles = PageAssets::collected()['styles'] ?? [];
        $this->assertContains(BlockAppearance::STYLESHEET, $styles);
        $this->assertNotContains(BlockAppearance::DECORATION_STYLESHEET, $styles, 'no effect, no effect stylesheet');

        PageAssets::reset();
        BlockAppearance::collectAssets([$plain + ['appearance_decoration' => 'sparks'], ['section_type' => 'cta_band', 'appearance_decoration' => 'glow'], $plain + ['appearance_decoration' => 'sparks']]);
        $styles = PageAssets::collected()['styles'] ?? [];
        $this->assertSame(1, count(array_keys($styles, BlockAppearance::DECORATION_STYLESHEET, true)), 'three effects, one stylesheet');
    }

    private static function css(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    private static function stripComments(string $css): string
    {
        return preg_replace('~/\*.*?\*/~s', '', $css) ?? '';
    }
}
