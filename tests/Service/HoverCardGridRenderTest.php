<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Blocks\BlockCategories;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockPreview;
use App\Service\Blocks\BlockSamples;
use App\Service\HoverCardGridContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/partials/section-hover-card-grid.php';

/**
 * Hover kaarten grid's render contract, on the partial and the read model's
 * closed lists alone (no database):
 *
 *   - no card, no block: not even its heading; every part of the heading is
 *     optional and an empty one leaves no element;
 *   - every choice is a class of a closed list, a default adds none, and an
 *     unknown stored word reads as the default;
 *   - ONE link per card, stretched over it: the call to action (named with
 *     the card's title for a screen reader) or else the title itself; a card
 *     without a link has no anchor, and an overlay card without one shows its
 *     text, since nothing on it can take keyboard focus;
 *   - the second picture is decoration (alt="", aria-hidden);
 *   - the stylesheet: every class styled, every hover rule inside
 *     (hover: hover) with a :focus-within twin, touch and reduced motion
 *     handled, nothing between a card's link and the card a containing block;
 *   - the script acts on a touch screen only, and never on a linked card.
 */
final class HoverCardGridRenderTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    // ------------------------------------------------------------- nothing

    public function testNoCardRendersNothingNotEvenTheHeading(): void
    {
        self::assertSame('', trim($this->render($this->content([], ['title' => 'Een kop', 'lead' => 'Een lead']))));
    }

    public function testTheHeadingIsOptionalAndAnEmptyPartLeavesNoElement(): void
    {
        $html = $this->render($this->content([$this->card()]));
        self::assertStringNotContainsString('section-head', $html, 'no heading at all');
        self::assertStringNotContainsString('<h2', $html);

        $html = $this->render($this->content([$this->card()], ['title' => 'Onze diensten']));
        self::assertMatchesRegularExpression('#<div class="section-head hover-cards__head" data-reveal>\s*<h2>Onze diensten</h2>\s*</div>#', $html, 'a title alone: no eyebrow, no lead');

        $html = $this->render($this->content([$this->card()], ['eyebrow' => 'Waarom wij', 'lead' => 'Kort gezegd.']));
        self::assertStringContainsString('<p class="eyebrow">Waarom wij</p>', $html);
        self::assertStringContainsString('<p class="lead">Kort gezegd.</p>', $html);
        self::assertStringNotContainsString('<h2', $html, 'no empty heading');
    }

    // ------------------------------------------------------------- choices

    /** @return iterable<string, array{string, string, string}> */
    public static function modifiers(): iterable
    {
        yield 'open layout' => ['layout', 'open', 'hover-cards--open'];
        yield 'square' => ['shape', 'square', 'hover-cards--square'];
        yield 'circle' => ['shape', 'circle', 'hover-cards--circle'];
        yield 'organic' => ['shape', 'organic', 'hover-cards--organic'];
        yield 'two columns' => ['columns', '2', 'hover-cards--cols-2'];
        yield 'four columns' => ['columns', '4', 'hover-cards--cols-4'];
        yield 'light veil' => ['overlay', 'light', 'hover-cards--veil-light'];
        yield 'dark veil' => ['overlay', 'dark', 'hover-cards--veil-dark'];
        yield 'subtle' => ['effect', 'subtle', 'hover-cards--subtle'];
    }

    #[DataProvider('modifiers')]
    public function testEveryChoiceIsAClass(string $choice, string $word, string $class): void
    {
        self::assertSame(['hover-cards', $class], $this->listClasses($this->render($this->content([$this->card()], [$choice => $word]))));
    }

    public function testTheDefaultsAddNoClassAndAnUnknownWordIsTheDefault(): void
    {
        self::assertSame(['hover-cards'], $this->listClasses($this->render($this->content([$this->card()]))));

        $settings = HoverCardGridContent::settings(['layout' => 'grid', 'shape' => 'star', 'columns' => '7', 'overlay' => 'rgba(0,0,0,.5)', 'effect' => 'wild', 'header_align' => 'justify']);
        self::assertSame(['layout' => 'overlay', 'shape' => 'rounded', 'columns' => '3', 'overlay' => 'medium', 'effect' => 'normal', 'header_align' => 'left'], $settings);
    }

    public function testTheVeilOnlyCountsForOverlayCards(): void
    {
        $open = $this->render($this->content([$this->card()], ['layout' => 'open', 'overlay' => 'dark']));
        self::assertSame(['hover-cards', 'hover-cards--open'], $this->listClasses($open), 'an open card has no veil to darken');
    }

    public function testTheHeadingAlignmentIsAClassOfTheHeadingOnly(): void
    {
        foreach (['center', 'right'] as $align) {
            $html = $this->render($this->content([$this->card()], ['title' => 'Kop', 'header_align' => $align]));
            self::assertStringContainsString('<div class="section-head hover-cards__head hover-cards__head--' . $align . '" data-reveal>', $html);
            self::assertSame(['hover-cards'], $this->listClasses($html), 'the cards stay as they are');
        }
    }

    // --------------------------------------------------------------- links

    public function testALinkLabelIsTheOneStretchedLinkNamedWithTheTitle(): void
    {
        $html = $this->render($this->content([$this->card(['title' => 'Maatwerk', 'link_label' => 'Bekijk', 'href' => '/maatwerk'])]));

        self::assertSame(1, substr_count($html, '<a '), 'one link per card');
        self::assertStringContainsString('<a class="hover-card__cta hover-card__link" href="/maatwerk">Bekijk<span class="visually-hidden">: Maatwerk</span><svg', $html);
        self::assertStringContainsString('<h2 class="hover-card__title">Maatwerk</h2>', $html, 'the title is plain text beside a call to action, and an h2 in a grid without a title (App\\Service\\Blocks\\CardHeading)');
        self::assertStringContainsString('hover-card hover-card--linked', $html);
    }

    public function testWithoutALabelTheTitleIsTheLink(): void
    {
        $html = $this->render($this->content([$this->card(['title' => 'Maatwerk', 'href' => '/maatwerk'])]));

        self::assertSame(1, substr_count($html, '<a '));
        self::assertStringContainsString('<h2 class="hover-card__title"><a class="hover-card__link" href="/maatwerk">Maatwerk</a></h2>', $html);
        self::assertStringNotContainsString('hover-card__cta', $html);
    }

    public function testACardWithoutALinkHasNoAnchorAndKeepsItsTextInView(): void
    {
        $html = $this->render($this->content([$this->card(['title' => 'Snel', 'body' => 'Binnen een week klaar.'])]));

        self::assertStringNotContainsString('<a ', $html);
        self::assertStringNotContainsString('hover-card--linked', $html);
        self::assertStringContainsString('hover-card hover-card--open-text', $html, 'nothing to focus: the text may not wait for a hover');
        self::assertStringContainsString('<p class="hover-card__text">Binnen een week klaar.</p>', $html);

        $open = $this->render($this->content([$this->card(['title' => 'Snel'])], ['layout' => 'open']));
        self::assertStringNotContainsString('hover-card--open-text', $open, 'an open card shows its words anyway');
    }

    public function testALinkLabelWithoutAnAddressIsNoLink(): void
    {
        $html = $this->render($this->content([$this->card(['title' => 'Snel', 'link_label' => 'Bekijk', 'href' => ''])]));

        self::assertStringNotContainsString('<a ', $html);
        self::assertStringNotContainsString('Bekijk', $html, 'a label without a link is not printed');
    }

    // ------------------------------------------------------------ pictures

    public function testThePictureCarriesTheLibrarysAltTextAndItsSize(): void
    {
        $html = $this->render($this->content([$this->card()]));

        self::assertStringContainsString('<img class="hover-card__image" src="/assets/media/kaart.jpg" alt="Een werkbank" width="1200" height="1500" loading="lazy" decoding="async">', $html);
        self::assertStringNotContainsString('hover-card--swap', $html);
        self::assertStringNotContainsString('data-hover-card-swap', $html);
    }

    public function testTheSecondPictureIsDecorationAndMarksTheCardForTheSwap(): void
    {
        $hover = ['src' => '/assets/media/kaart-2.jpg', 'width' => 1200, 'height' => 1500];

        $html = $this->render($this->content([$this->card(['title' => 'Snel', 'hover_image' => $hover])]));
        self::assertStringContainsString('<img class="hover-card__image hover-card__image--hover" src="/assets/media/kaart-2.jpg" alt="" width="1200" height="1500" loading="lazy" decoding="async" aria-hidden="true">', $html);
        self::assertStringContainsString('hover-card--swap', $html);
        self::assertStringContainsString('data-hover-card-swap', $html, 'a tap may turn it on a touch screen');

        $linked = $this->render($this->content([$this->card(['title' => 'Snel', 'hover_image' => $hover, 'href' => '/snel'])]));
        self::assertStringContainsString('hover-card--swap', $linked);
        self::assertStringNotContainsString('data-hover-card-swap', $linked, 'a tap on a linked card follows the link');
    }

    public function testAPictureWithoutWordsNeedsNoVeil(): void
    {
        $html = $this->render($this->content([$this->card()]));

        self::assertStringContainsString('hover-card--bare', $html);
        self::assertStringNotContainsString('hover-card__content', $html, 'no empty text box');
    }

    // ---------------------------------------------------------- the words

    public function testCardsAreAListWithH3TitlesAndEveryWordIsEscaped(): void
    {
        $evil = '<script>alert(1)</script>"&';
        $html = $this->render($this->content(
            [$this->card(['badge' => $evil, 'title' => $evil, 'body' => $evil, 'link_label' => $evil, 'href' => '/x?a=1&b="2"'])],
            ['eyebrow' => $evil, 'title' => $evil, 'lead' => $evil]
        ));

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;&quot;&amp;', $html);
        self::assertStringContainsString('href="/x?a=1&amp;b=&quot;2&quot;"', $html);
        self::assertStringContainsString('<ul class="hover-cards" role="list">', $html, 'a list, and still one without its bullets');
        self::assertSame(1, substr_count($html, '<h2>'));
        self::assertSame(1, substr_count($html, '<h3 class="hover-card__title">'));
        self::assertStringContainsString('<span class="hover-card__badge">', $html);
    }

    public function testEachCardJoinsTheInstancesRevealGroup(): void
    {
        ob_start();
        render_section_hover_card_grid($this->content([$this->card(), $this->card()]), 'hover_card_grid-41');
        $html = (string) ob_get_clean();

        self::assertSame(2, substr_count($html, 'data-reveal-group="hover_card_grid-41"'));
    }

    // ------------------------------------------------------- the stylesheet

    public function testTheStylesheetStylesEveryClassThePartialPrints(): void
    {
        $css = $this->css();

        foreach ([
            '.hover-cards--open', '.hover-cards--square', '.hover-cards--circle', '.hover-cards--organic',
            '.hover-cards--cols-2', '.hover-cards--cols-4', '.hover-cards--veil-light', '.hover-cards--veil-dark',
            '.hover-cards--subtle', '.hover-cards__head--center', '.hover-cards__head--right',
            '.hover-card--linked', '.hover-card--swap', '.hover-card--open-text', '.hover-card--bare',
            '.hover-card__image--hover', '.hover-card__badge', '.hover-card__more', '.hover-card__cta', '.hover-card__link::after',
        ] as $class) {
            self::assertStringContainsString($class, $css, $class . ' is printed but not styled');
        }

        foreach (['hover-cards--overlay', 'hover-cards--rounded', 'hover-cards--cols-3', 'hover-cards--veil-medium', 'hover-cards--normal'] as $default) {
            self::assertStringNotContainsString($default, $css, 'a default has no class');
        }
    }

    public function testEveryHoverRuleIsForADeviceThatHoversAndHasAKeyboardTwin(): void
    {
        $css = $this->css();
        $outside = $this->withoutMediaBlocks($css, ['(hover: hover)', '(prefers-reduced-motion: reduce) and (hover: hover)']);

        self::assertStringNotContainsString(':hover', $outside, 'a touch screen never gets a hover state it cannot undo');

        foreach ([
            '.hover-card:focus-within .hover-card__image',
            '.hover-card--swap:focus-within .hover-card__image--hover',
            '.hover-card:focus-within .hover-card__more',
            '.hover-card:focus-within .hover-card__frame',
        ] as $twin) {
            self::assertStringContainsString($twin, $css, 'the keyboard reaches what the mouse reaches: ' . $twin);
        }
    }

    public function testTouchAndReducedMotionAreHandled(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/@media \(hover: none\)\{[^@]*\.hover-card__more\{ grid-template-rows: 1fr; \}/s', $css, 'on touch the text is simply there');
        self::assertMatchesRegularExpression('/@media \(hover: none\)\{[^@]*\.hover-card\.is-flipped \.hover-card__image--hover\{ opacity: 1; \}/s', $css);
        self::assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\)\{[^@]*transition: none;/s', $css);
        self::assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\)\{[^@]*\.hover-card:focus-within \.hover-card__frame\{ border-radius: var\(--hover-card-radius\); \}/s', $css, 'an organic card keeps its shape');
    }

    public function testNothingBetweenACardsLinkAndTheCardIsAContainingBlock(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $this->css());

        foreach (explode('}', $css) as $rule) {
            $brace = strrpos($rule, '{');
            if ($brace === false) {
                continue;
            }
            $selectors = (string) preg_replace('/^.*\{/s', '', substr($rule, 0, $brace));
            $body = substr($rule, $brace + 1);

            foreach (explode(',', $selectors) as $selector) {
                $selector = trim($selector);
                if (!preg_match('/(\.hover-card__content|\.hover-card__more|\.hover-card__more-inner|\.hover-card__title|\.hover-card__link|\.hover-card__cta)$/', $selector)) {
                    continue;
                }
                foreach (['transform', 'position', 'filter', 'will-change', 'contain', 'translate', 'rotate', 'scale'] as $property) {
                    self::assertDoesNotMatchRegularExpression('/(^|[;{\s])' . $property . '\s*:/', $body, $selector . ' may not set ' . $property . ': the link would stop covering the card');
                }
            }
        }
    }

    // ---------------------------------------------------------- the script

    public function testTheScriptOnlyActsOnATouchScreenAndNeverOnALinkedCard(): void
    {
        $js = (string) file_get_contents(self::ROOT . '/assets/js/blocks/hover-card-grid.js');

        self::assertStringContainsString('matchMedia("(hover: none)")', $js);
        self::assertStringContainsString('[data-hover-card-grid]', $js, 'each grid on its own');
        self::assertStringContainsString('card.querySelector("a[href]")', $js, 'a linked card is left to its link');
        self::assertStringContainsString('classList.toggle("is-flipped")', $js);
        self::assertStringNotContainsString('innerHTML', $js);
    }

    // -------------------------------------------------------- registration

    public function testTheBlockIsRegisteredUnderMediaWithItsAssetsAndASample(): void
    {
        $definition = BlockDefinitions::get('hover_card_grid');

        self::assertNotNull($definition);
        self::assertSame(BlockCategories::MEDIA, $definition->category());
        self::assertSame('hover_card_grids', $definition->contentTable());
        self::assertSame(['hover_card_grid_items' => ['parent' => 'hover_card_grids', 'column' => 'hover_card_grid_id']], $definition->childTables());
        self::assertSame(['assets/css/blocks/hover-card-grid.css'], $definition->styles());
        self::assertSame(['assets/js/blocks/hover-card-grid.js'], $definition->scripts());
        self::assertSame([BlockPreview::HEADING, BlockPreview::TILES], $definition->preview());
        self::assertTrue($definition->meta()['allow_multiple']);

        foreach ($definition->translatableFields() as $table => $fields) {
            foreach ($fields as $field) {
                self::assertFalse($field->required, $table . '.' . $field->key . ' is optional: a card is its picture first');
            }
        }

        $sample = $definition->sampleContent(new BlockSamples());
        self::assertIsArray($sample);
        ob_start();
        $definition->renderSample($sample, 'hover_card_grid-sample');
        $html = (string) ob_get_clean();
        self::assertSame(3, substr_count($html, '<li class="hover-card'), 'three sample cards');
        self::assertSame(1, substr_count($html, '<a '), 'one of them links, to the preview itself');
        self::assertStringContainsString('href="' . BlockSamples::LINK . '"', $html);
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param list<array<string, mixed>> $cards
     * @param array<string, string>      $overrides heading words and choices
     *
     * @return array<string, mixed>
     */
    private function content(array $cards, array $overrides = []): array
    {
        return array_merge(
            ['state' => HoverCardGridContent::STATE_ACTIVE, 'eyebrow' => '', 'title' => '', 'lead' => ''],
            $overrides,
            HoverCardGridContent::settings($overrides),
            ['cards' => $cards]
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function card(array $overrides = []): array
    {
        return $overrides + [
            'image' => ['src' => '/assets/media/kaart.jpg', 'alt' => 'Een werkbank', 'width' => 1200, 'height' => 1500],
            'hover_image' => null,
            'badge' => '',
            'title' => '',
            'body' => '',
            'link_label' => '',
            'href' => '',
        ];
    }

    /** @param array<string, mixed> $content */
    private function render(array $content): string
    {
        ob_start();
        try {
            render_section_hover_card_grid($content, 'hover_card_grid-1');
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** @return list<string> the classes of the card list */
    private function listClasses(string $html): array
    {
        self::assertSame(1, preg_match('#<ul class="([^"]+)" role="list">#', $html, $match), 'one list of cards');

        return explode(' ', $match[1]);
    }

    private function css(): string
    {
        return (string) file_get_contents(self::ROOT . '/assets/css/blocks/hover-card-grid.css');
    }

    /**
     * The stylesheet without the blocks of the given @media preludes (the
     * braces counted, so a nested rule does not end a block early).
     *
     * @param list<string> $preludes
     */
    private function withoutMediaBlocks(string $css, array $preludes): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        foreach ($preludes as $prelude) {
            while (($start = strpos($css, '@media ' . $prelude . '{')) !== false) {
                $depth = 0;
                for ($i = $start; $i < strlen($css); $i++) {
                    if ($css[$i] === '{') {
                        $depth++;
                    } elseif ($css[$i] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                }
                $css = substr($css, 0, $start) . substr($css, $i + 1);
            }
        }

        return $css;
    }
}
