<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockSamples;
use App\Service\Blocks\CardPresentation;
use App\Service\Blocks\PresentsCards;
use App\Service\ItemGalleryContent;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/partials/section-item-gallery.php';
require_once dirname(__DIR__, 2) . '/admin/_card_presentation_field.php';

/**
 * Card Presentation 2.0 (App\Service\Blocks\CardPresentation,
 * CONTENT-BLOCKS.md "Kaartweergave") without a database:
 *
 *   - a closed list of three presentations, the default first; a stored
 *     NULL, an unknown word or a non-string is the default;
 *   - a save takes only a presentation the block offers, keeps the stored
 *     one when the field is missing and refuses anything else;
 *   - the classes come from one resolver: nothing for the default, one fixed
 *     class per part otherwise, never a word from outside;
 *   - exactly the gallery and Projecten present cards, and offer all three;
 *   - the default renders the cards exactly as before (no shared class, the
 *     hover caption, the same tags); compact and wide keep every link, zoom,
 *     call to action, alt text and lazy picture, with no link inside a link,
 *     the card title as a heading on the CardHeading rule, the words
 *     escaped and never shortened;
 *   - the stylesheet uses theme tokens only, scopes every rule under the
 *     shared class and stacks the wide card on a phone;
 *   - the editor field offers what the block offers, and nothing for a
 *     block that does not present cards.
 *
 * Saving, refusing, permissions and the page's stylesheet:
 * Tests\Service\CardPresentationHttpTest. The column:
 * Tests\Install\CardPresentationMigrationTest.
 */
final class CardPresentationContractTest extends TestCase
{
    /** The blocks that present cards, and nothing else. */
    private const CONNECTED = ['item_gallery', 'project_cards'];

    private const STYLESHEET = 'assets/css/card-presentation.css';

    protected function setUp(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'blog' => true, 'portfolio' => true, 'multilingual' => true]);
        ItemGalleryContent::clearCache();
    }

    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
        ItemGalleryContent::clearCache();
    }

    public function testThreePresentationsTheDefaultFirst(): void
    {
        self::assertSame(['default', 'compact', 'wide'], CardPresentation::ALL);
        foreach (CardPresentation::ALL as $presentation) {
            self::assertTrue(CardPresentation::isPresentation($presentation));
        }
        foreach (['', 'visual', 'Wide', 'wide ', 'grid-template-columns:1fr'] as $word) {
            self::assertFalse(CardPresentation::isPresentation($word), $word);
        }
    }

    public function testWhatIsStoredReadsSafelyAndAnythingUnknownIsTheDefault(): void
    {
        self::assertSame('wide', CardPresentation::stored('wide'));
        self::assertSame('compact', CardPresentation::stored('compact'));
        foreach ([null, '', 'visual', 'WIDE', 3, ['wide'], true] as $value) {
            self::assertSame('default', CardPresentation::stored($value), var_export($value, true));
        }
    }

    public function testASaveTakesOnlyWhatTheBlockOffers(): void
    {
        $projects = BlockDefinitions::get('project_cards');
        self::assertNotNull($projects);

        self::assertSame('wide', CardPresentation::choiceFromRequest(['card_presentation' => 'wide'], $projects, 'default'));
        self::assertSame('default', CardPresentation::choiceFromRequest(['card_presentation' => 'default'], $projects, 'wide'));
        self::assertSame('compact', CardPresentation::choiceFromRequest([], $projects, 'compact'), 'no field: what is stored stays');
        self::assertSame('default', CardPresentation::choiceFromRequest([], $projects, null), 'no field and nothing stored: the default');

        foreach (['visual', '', 'wide;color:red', ['wide'], 'WIDE'] as $forged) {
            self::assertNull(CardPresentation::choiceFromRequest(['card_presentation' => $forged], $projects, 'default'), var_export($forged, true));
        }

        $faq = BlockDefinitions::get('faq');
        self::assertNotNull($faq);
        self::assertSame([], CardPresentation::offered($faq), 'a block without cards offers nothing');
        self::assertNull(CardPresentation::choiceFromRequest(['card_presentation' => 'wide'], $faq, null), 'and stores nothing');
    }

    public function testTheClassesComeFromOneResolverAndTheDefaultHasNone(): void
    {
        foreach (['grid', 'card', 'media', 'body', 'title', 'text'] as $part) {
            self::assertSame('', CardPresentation::classes('default', $part), "default {$part}");
            self::assertSame('', CardPresentation::classes('visual', $part), "unknown {$part}");
        }

        self::assertSame(' card-presentation card-presentation--wide', CardPresentation::classes('wide', 'grid'));
        self::assertSame(' card-presentation card-presentation--compact', CardPresentation::classes('compact', 'grid'));
        self::assertSame(' card-presentation__card', CardPresentation::classes('wide', 'card'));
        self::assertSame(' card-presentation__media', CardPresentation::classes('compact', 'media'));
        self::assertSame('', CardPresentation::classes('wide', 'style'), 'an unknown part');
        self::assertSame('', CardPresentation::classes('wide" onmouseover="x', 'grid'), 'never a word from outside');
    }

    public function testExactlyTheGalleryAndProjectenPresentCardsAndOfferAllThree(): void
    {
        $presenting = [];
        foreach (BlockDefinitions::all() as $type => $definition) {
            if ($definition instanceof PresentsCards) {
                $presenting[] = $type;
                self::assertSame(CardPresentation::ALL, CardPresentation::offered($definition), $type);
            }
        }
        sort($presenting);

        self::assertSame(self::CONNECTED, $presenting, 'a new card block joins on purpose, here and in CONTENT-BLOCKS.md');
    }

    public function testTheDefaultRendersTheCardsExactlyAsBefore(): void
    {
        foreach (self::CONNECTED as $type) {
            $sample = self::sample($type);
            $none = self::render($sample);

            self::assertSame($none, self::render(['card_presentation' => 'default'] + $sample), "{$type}: default");
            self::assertSame($none, self::render(['card_presentation' => 'visual'] + $sample), "{$type}: an unknown word");
            self::assertStringNotContainsString('card-presentation', $none, "{$type}: no shared class at all");
            self::assertStringContainsString('<p class="gallery-item__title">', $none, "{$type}: the hover caption it always had");
            self::assertStringContainsString('<span class="gallery-item__overlay">', $none, $type);
            self::assertStringContainsString('<div class="gallery-grid">', $none, $type);
        }
    }

    /**
     * Every card a source can hand over — a link, a zoom with "Bekijk
     * project", a zoom without, a plain card, no picture, no words, long
     * words — keeps what it does in every presentation.
     */
    public function testCompactAndWideKeepEveryLinkZoomAndWord(): void
    {
        $content = self::cards();

        foreach (['compact', 'wide'] as $presentation) {
            $html = self::render(['card_presentation' => $presentation] + $content);

            self::assertStringContainsString('<div class="gallery-grid card-presentation card-presentation--' . $presentation . '">', $html);
            self::assertSame(7, substr_count($html, 'card-presentation__card'), "{$presentation}: every card");

            // The link card stays one link, the zoom a button, the call to action its own link.
            self::assertStringContainsString('<a class="gallery-item gallery-item--linked card-presentation__card" href="/zz-product/1"', $html);
            self::assertStringContainsString('<button type="button" class="gallery-item__zoom card-presentation__media" data-lightbox-trigger', $html);
            self::assertStringContainsString('<a class="gallery-item__cta" href="/portfolio/zz-project">', $html);
            self::assertSame([], self::nestedLinks($html), "{$presentation}: no link inside a link");

            // Pictures: lazy, alt text as given, a frame of their own, an empty frame without one.
            self::assertSame(5, substr_count($html, 'loading="lazy"'), $presentation);
            self::assertStringContainsString('alt="ZZ alt &lt;tekst&gt;"', $html);
            self::assertSame(2, substr_count($html, '<span class="card-presentation__media" aria-hidden="true"></span>'), "{$presentation}: the cards without a picture");

            // The words: escaped, whole, the title a heading under the block's own h2.
            self::assertStringContainsString('<h3 class="gallery-item__title card-presentation__title">ZZ &lt;script&gt;alert(1)&lt;/script&gt;</h3>', $html);
            self::assertStringNotContainsString('<script>alert', $html);
            self::assertStringContainsString(str_repeat('Een lange beschrijving ', 30), $html, 'never shortened');
            self::assertStringContainsString(str_repeat('Lange titel ', 15), $html);
            self::assertStringNotContainsString('<p class="gallery-item__title', $html);

            $untitled = self::render(['card_presentation' => $presentation, 'title' => ''] + $content);
            self::assertStringContainsString('<h2 class="gallery-item__title card-presentation__title">', $untitled, 'without a block title the card title is an h2');
            self::assertStringNotContainsString('<h3', $untitled);
        }
    }

    public function testTheStylesheetUsesThemeTokensAndStacksTheWideCardOnAPhone(): void
    {
        $css = (string) file_get_contents(self::root() . '/' . self::STYLESHEET);
        self::assertSame(self::STYLESHEET, CardPresentation::STYLESHEET);
        $rules = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $rules, 'no colour of its own');
        self::assertDoesNotMatchRegularExpression('/\b(?:rgba?|hsla?)\((?!var\()/', $rules, 'colours only through tokens');
        self::assertDoesNotMatchRegularExpression('/font-family\s*:/', $rules, 'the Font Library decides the type');

        preg_match_all('/([^{}]+)\{/', $rules, $selectors);
        foreach ($selectors[1] as $selector) {
            $selector = trim($selector);
            if (str_starts_with($selector, '@media')) {
                continue;
            }
            foreach (explode(',', $selector) as $one) {
                self::assertStringStartsWith('.card-presentation', trim($one), "every rule under the shared class: {$one}");
            }
        }

        self::assertMatchesRegularExpression('/@media \(max-width: 600px\)\{\s*\.card-presentation--wide \.card-presentation__card\{ display: flex; flex-direction: column; \}/', $rules, 'the wide card stacks on a phone');
        self::assertStringContainsString('object-fit: cover', $rules);
    }

    public function testTheEditorFieldOffersWhatTheBlockOffersAndNothingElse(): void
    {
        $projects = BlockDefinitions::get('project_cards');
        self::assertNotNull($projects);

        ob_start();
        admin_card_presentation_field($projects, 'wide', 'zz-cp');
        $field = (string) ob_get_clean();

        self::assertSame(3, substr_count($field, 'name="card_presentation"'));
        self::assertMatchesRegularExpression('#id="zz-cp-wide" name="card_presentation" value="wide" checked#', $field);
        self::assertStringNotContainsString('value="default" checked', $field);
        foreach (['grid-template-columns', 'aspect-ratio', 'object-fit'] as $technical) {
            self::assertStringNotContainsString($technical, $field, 'no technical words for the editor');
        }

        ob_start();
        admin_card_presentation_field($projects, 'visual', 'zz-cp');
        self::assertMatchesRegularExpression('#value="default" checked#', (string) ob_get_clean(), 'an unknown value shows as the default');

        $faq = BlockDefinitions::get('faq');
        self::assertNotNull($faq);
        ob_start();
        admin_card_presentation_field($faq, 'wide', 'zz-cp');
        self::assertSame('', (string) ob_get_clean(), 'no field for a block without cards');

        foreach (['admin/project-cards.php' => 'project_cards', 'admin/item-gallery.php' => 'item_gallery'] as $editor => $type) {
            self::assertStringContainsString("admin_card_presentation_field(\\App\\Service\\Blocks\\BlockDefinitions::get('{$type}')", (string) file_get_contents(self::root() . '/' . $editor), $editor);
        }
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private static function sample(string $type): array
    {
        $sample = BlockDefinitions::get($type)?->sampleContent(new BlockSamples());
        self::assertIsArray($sample, $type);

        return $sample;
    }

    /** @return array<string, mixed> every kind of card a source hands over, under a block title */
    private static function cards(): array
    {
        $card = static fn (array $over): array => $over + [
            'image_path' => 'assets/images/zz-card.jpg',
            'alt' => 'ZZ alt <tekst>',
            'title' => 'ZZ kaart',
            'subtitle' => 'ZZ categorie',
            'categories' => '',
            'url' => '',
            'is_detail_link' => false,
        ];

        return [
            'title' => 'ZZ blok',
            'eyebrow' => '',
            'lead' => '',
            'footer_note' => '',
            'button_label' => '',
            'button_url' => '',
            'enable_lightbox' => false,
            'fallback_link_url' => '',
            'filter_categories' => [],
            'background' => 'default',
            'tight_top' => false,
            'items' => [
                $card(['url' => '/zz-product/1', 'is_detail_link' => true]),
                $card(['opens_lightbox' => true, 'follows_fallback_link' => false, 'cta' => ['url' => '/portfolio/zz-project', 'label' => 'Bekijk project']]),
                $card(['opens_lightbox' => true, 'follows_fallback_link' => false, 'cta' => null, 'title' => 'ZZ <script>alert(1)</script>']),
                $card(['image_path' => '', 'url' => '/zz-product/2', 'is_detail_link' => true]),
                $card(['image_path' => '']),
                $card(['title' => '', 'subtitle' => '']),
                $card(['title' => str_repeat('Lange titel ', 15), 'subtitle' => str_repeat('Een lange beschrijving ', 30)]),
            ],
        ];
    }

    /** @param array<string, mixed> $content */
    private static function render(array $content): string
    {
        ItemGalleryContent::clearCache();
        ob_start();
        render_section_item_gallery($content, 'zz-gallery-1');

        return (string) ob_get_clean();
    }

    /** @return list<string> every <a> opened while another is still open */
    private static function nestedLinks(string $html): array
    {
        $nested = [];
        $open = 0;
        preg_match_all('#<(/?)a\b[^>]*>#i', $html, $tags, PREG_SET_ORDER);
        foreach ($tags as $tag) {
            if ($tag[1] === '/') {
                $open--;
                continue;
            }
            if ($open > 0) {
                $nested[] = $tag[0];
            }
            $open++;
        }

        return $nested;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
