<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Blocks\AppearanceSupport;
use App\Service\Blocks\BlockAppearance;
use App\Service\Blocks\BlockCategories;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\BlockSamples;
use App\Service\Blocks\ReviewsBlock;
use App\Service\ReviewsContent;
use App\Service\Routing\RequestLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/partials/section-reviews.php';

/**
 * The Reviews block without a database (CONTENT-BLOCKS.md, "Reviews"): the
 * review shape every source hands the partial, the four layouts, the stars,
 * what is escaped, the definition's contract and what its stylesheet and
 * script promise.
 *
 * The saving, the lifecycle, the languages and the guards run over PHP's
 * built-in server in ReviewsHttpTest.
 */
final class ReviewsContractTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    protected function tearDown(): void
    {
        RequestLanguage::reset();
    }

    // ------------------------------------------------------------ the shape

    public function testAReviewWithOnlyItsTextHasNothingElse(): void
    {
        $review = ReviewsContent::review(['text' => '  Heel fijn contact.  ']);

        self::assertSame('Heel fijn contact.', $review['text']);
        self::assertSame(0, $review['rating']);
        self::assertSame('', $review['rating_label']);
        self::assertSame('', $review['name']);
        self::assertSame('', $review['date']);
        self::assertSame('', $review['source_href']);
        self::assertNull($review['image']);
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function ratings(): iterable
    {
        yield 'none' => ['', 0];
        yield 'null' => [null, 0];
        yield 'one' => ['1', 1];
        yield 'five' => ['5', 5];
        yield 'an int' => [4, 4];
        yield 'zero' => ['0', 0];
        yield 'six' => ['6', 0];
        yield 'negative' => ['-1', 0];
        yield 'half' => ['3.5', 0];
        yield 'words' => ['vijf', 0];
        yield 'an array' => [['5'], 0];
    }

    #[DataProvider('ratings')]
    public function testStarsAreAWholeNumberFromOneToFiveOrNone(mixed $posted, int $stars): void
    {
        self::assertSame($stars, ReviewsContent::review(['text' => 'x', 'rating' => $posted])['rating']);
        self::assertSame($stars === 0 ? null : $stars, ReviewsContent::rating($posted));
    }

    public function testTheStarsHaveASentenceForAScreenReader(): void
    {
        RequestLanguage::set('nl', true);
        self::assertSame('4 van de 5 sterren', ReviewsContent::review(['text' => 'x', 'rating' => '4'])['rating_label']);
        self::assertSame('1 van de 5 sterren', ReviewsContent::ratingLabel(1));

        RequestLanguage::set('en', true);
        self::assertSame('4 out of 5 stars', ReviewsContent::ratingLabel(4));
    }

    public function testADateIsARealCalendarDate(): void
    {
        RequestLanguage::set('nl', true);
        $review = ReviewsContent::review(['text' => 'x', 'date' => '2026-03-12']);
        self::assertSame('12 maart 2026', $review['date']);
        self::assertSame('2026-03-12', $review['date_iso']);

        foreach (['2026-02-30', '2026-13-01', '12-03-2026', '2026-3-1', 'gisteren', ''] as $wrong) {
            self::assertNull(ReviewsContent::date($wrong), $wrong);
            self::assertSame('', ReviewsContent::review(['text' => 'x', 'date' => $wrong])['date'], $wrong);
        }
    }

    public function testASourceIsOnlyALinkWhenItIsAWebAddress(): void
    {
        $safe = ReviewsContent::review(['text' => 'x', 'source_url' => 'https://www.example.org/reviews/12']);
        self::assertSame('https://www.example.org/reviews/12', $safe['source_href']);
        self::assertSame('example.org', $safe['source_label'], 'no words: the host names the link');

        $named = ReviewsContent::review(['text' => 'x', 'source_label' => 'Google', 'source_url' => 'https://example.org']);
        self::assertSame('Google', $named['source_label']);

        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', "java\tscript:alert(1)", 'data:text/html,x', 'mailto:a@example.org', 'vbscript:x'] as $unsafe) {
            $review = ReviewsContent::review(['text' => 'x', 'source_label' => 'Bron', 'source_url' => $unsafe]);
            self::assertSame('', $review['source_href'], $unsafe);
            self::assertSame('Bron', $review['source_label'], 'the words stay, without a link');
        }
    }

    // ---------------------------------------------------------- rendering

    public function testNoReviewRendersNothingNotEvenTheHeading(): void
    {
        self::assertSame('', trim($this->render($this->content([], ['title' => 'Wat klanten zeggen']))));
    }

    /** @return iterable<string, array{string, string}> */
    public static function layouts(): iterable
    {
        yield 'cards' => ['cards', 'review--card'];
        yield 'minimal' => ['minimal', 'review--minimal'];
        yield 'featured' => ['featured', 'review--featured'];
        yield 'carousel' => ['carousel', 'review--card'];
    }

    #[DataProvider('layouts')]
    public function testEveryLayoutHasItsOwnRootClassAndLook(string $layout, string $figure): void
    {
        $html = $this->render($this->content([$this->review('Eerste'), $this->review('Tweede')], ['layout' => $layout]));
        $xpath = $this->xpath($html);

        self::assertMatchesRegularExpression('/^\s*<section class="reviews-section reviews-section--' . $layout . '" data-reviews>/', $html, 'one root, the layout on it');
        self::assertSame(1, $xpath->query('//section/div[@class="container"]')->length, 'the content in a .container right under it');
        self::assertGreaterThan(0, $xpath->query('//figure[contains(concat(" ", @class, " "), " ' . $figure . ' ")]')->length);
        self::assertSame($layout === 'featured' ? 1 : 2, $xpath->query('//figure')->length, 'featured shows one review');
    }

    public function testTheLayoutsDifferInMoreThanAClass(): void
    {
        $content = $this->content([$this->review('Tekst', ['name' => 'Marieke'])]);

        $cards = $this->xpath($this->render(['layout' => 'cards'] + $content));
        self::assertSame(1, $cards->query('//ul[contains(@class, "reviews--cards")]/li[@class="reviews__item"]')->length, 'a grid list');

        $minimal = $this->xpath($this->render(['layout' => 'minimal'] + $content));
        self::assertSame(1, $minimal->query('//ul[contains(@class, "reviews--minimal")]')->length);

        $featured = $this->xpath($this->render(['layout' => 'featured'] + $content));
        self::assertSame(0, $featured->query('//ul')->length, 'one review, no list');
        self::assertSame(1, $featured->query('//div[@class="reviews reviews--featured"]/figure')->length);

        $carousel = $this->xpath($this->render(['layout' => 'carousel'] + $content));
        self::assertSame(1, $carousel->query('//div[@data-reviews-carousel][@role="region"]/ul[@data-reviews-track][@tabindex="0"]')->length, 'a named, scrollable region');

        // The stylesheet gives every layout its own character, not a radius.
        $css = self::read('assets/css/blocks/reviews.css');
        foreach (['.reviews--cards{', '.review--card{', '.review--minimal .review__quote{', '.review--featured{', '.review--featured .review__quote{', '.reviews--carousel{', '.reviews-carousel__button{'] as $rule) {
            self::assertStringContainsString($rule, $css, $rule);
        }
        self::assertStringContainsString('font-family: var(--font-display)', self::rule($css, '.review--minimal .review__quote'));
        self::assertStringContainsString('font-family: var(--font-display)', self::rule($css, '.review--featured .review__quote'));
        self::assertStringContainsString('scroll-snap-type: x mandatory', self::rule($css, '.reviews--carousel'));
    }

    public function testTheFeaturedLayoutShowsTheChosenReviewElseTheFirst(): void
    {
        $reviews = [$this->review('Eerste'), $this->review('Tweede'), $this->review('Derde')];

        $chosen = $this->render($this->content($reviews, ['layout' => 'featured', 'featured' => 2]));
        self::assertStringContainsString('Derde', $chosen);
        self::assertStringNotContainsString('Eerste', $chosen);

        // A choice that is gone (a removed review): the first, never nothing.
        $gone = $this->render($this->content($reviews, ['layout' => 'featured', 'featured' => 7]));
        self::assertStringContainsString('Eerste', $gone);
    }

    public function testTheCarouselIsAccessibleAndNeverMovesByItself(): void
    {
        RequestLanguage::set('nl', true);
        $html = $this->render($this->content([$this->review('A'), $this->review('B'), $this->review('C')], ['layout' => 'carousel', 'title' => 'Ervaringen']));
        $xpath = $this->xpath($html);

        $region = $xpath->query('//div[@data-reviews-carousel]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $region);
        self::assertSame('carrousel', $region->getAttribute('aria-roledescription'));
        self::assertSame('Ervaringen', $region->getAttribute('aria-label'));

        $slides = $xpath->query('//li[@data-reviews-slide]');
        self::assertSame(3, $slides->length);
        self::assertSame('2 van 3', $slides->item(1)->getAttribute('aria-label'));
        self::assertSame('group', $slides->item(1)->getAttribute('role'));

        // The arrows: real buttons with a name, hidden until the script sees
        // that the reviews do not all fit.
        self::assertSame(1, $xpath->query('//div[@data-reviews-controls][@hidden]')->length);
        self::assertSame('Vorige review', $xpath->query('//button[@data-reviews-prev]')->item(0)->getAttribute('aria-label'));
        self::assertSame('Volgende review', $xpath->query('//button[@data-reviews-next]')->item(0)->getAttribute('aria-label'));
        self::assertSame(1, $xpath->query('//p[@data-reviews-status][@aria-live="polite"]')->length);

        // One review needs no arrows at all.
        self::assertStringNotContainsString('data-reviews-controls', $this->render($this->content([$this->review('A')], ['layout' => 'carousel'])));

        $js = self::read('assets/js/blocks/reviews.js');
        foreach (['setInterval', 'autoplay', 'requestAnimationFrame'] as $moving) {
            self::assertStringNotContainsString($moving, preg_replace('#/\*.*?\*/#s', '', $js), $moving);
        }
        self::assertStringContainsString('prefers-reduced-motion: reduce', $js);
        self::assertStringContainsString('behavior: reducedMotion.matches ? "auto" : "smooth"', $js);
        self::assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\)\{\s*\.reviews--carousel\{ scroll-behavior: auto; \}/', self::read('assets/css/blocks/reviews.css'));
    }

    public function testNoStarsMeansNoStarRowAndStarsHaveOneName(): void
    {
        RequestLanguage::set('nl', true);
        $none = $this->render($this->content([$this->review('Zonder sterren')]));
        self::assertStringNotContainsString('review__stars', $none);
        self::assertStringNotContainsString('review__star', $none);

        $four = $this->xpath($this->render($this->content([$this->review('Met sterren', ['rating' => '4'])])));
        $stars = $four->query('//div[@class="review__stars"]');
        self::assertSame(1, $stars->length);
        self::assertSame('img', $stars->item(0)->getAttribute('role'));
        self::assertSame('4 van de 5 sterren', $stars->item(0)->getAttribute('aria-label'));
        self::assertSame(5, $four->query('//div[@class="review__stars"]/*[local-name()="svg"][@aria-hidden="true"]')->length, 'five shapes, all decoration');
        self::assertSame(4, $four->query('//*[local-name()="svg"][contains(@class, "is-filled")]')->length);

        $one = $this->xpath($this->render($this->content([$this->review('Eén ster', ['rating' => '1'])])));
        self::assertSame(1, $one->query('//*[local-name()="svg"][contains(@class, "is-filled")]')->length);
        $five = $this->xpath($this->render($this->content([$this->review('Vijf', ['rating' => '5'])])));
        self::assertSame(5, $five->query('//*[local-name()="svg"][contains(@class, "is-filled")]')->length);
    }

    public function testOptionalPartsLeaveNoEmptyElement(): void
    {
        $bare = $this->render($this->content([$this->review('Alleen tekst')]));
        foreach (['<figcaption', 'review__name', 'review__role', '<time', 'review__source', 'review__portrait'] as $absent) {
            self::assertStringNotContainsString($absent, $bare, $absent);
        }

        $full = $this->xpath($this->render($this->content([$this->review('Alles', [
            'name' => 'Marieke', 'role' => 'Eigenaar, Voorbeeld', 'rating' => '5', 'date' => '2026-03-12',
            'source_label' => 'Google', 'source_url' => 'https://example.org/r',
            'image' => ['src' => '/assets/media/thumbs/p.jpg', 'alt' => '', 'width' => 800, 'height' => 800],
        ])])));
        self::assertSame('Marieke', $full->query('//figcaption//span[@class="review__name"]')->item(0)->textContent);
        self::assertSame('Eigenaar, Voorbeeld', $full->query('//span[@class="review__role"]')->item(0)->textContent);
        self::assertSame('2026-03-12', $full->query('//time')->item(0)->getAttribute('datetime'));
        $source = $full->query('//a[@class="review__source"]')->item(0);
        self::assertSame('https://example.org/r', $source->getAttribute('href'));
        self::assertSame('nofollow noopener noreferrer', $source->getAttribute('rel'));
        $img = $full->query('//span[@class="review__portrait"]/img')->item(0);
        self::assertSame('lazy', $img->getAttribute('loading'));
        self::assertSame('800', $img->getAttribute('width'), 'dimensions: no layout shift');
        self::assertSame('800', $img->getAttribute('height'));
    }

    public function testTheQuotationMarkIsDecorationAndTheReviewIsAFigure(): void
    {
        $xpath = $this->xpath($this->render($this->content([$this->review('Tekst', ['name' => 'Peter'])])));

        self::assertSame(1, $xpath->query('//figure/span[@class="review__mark"][@aria-hidden="true"]')->length);
        self::assertSame(1, $xpath->query('//figure//blockquote[@class="review__quote"]/p')->length);
        self::assertSame(1, $xpath->query('//figure//figcaption[@class="review__meta"]')->length);
    }

    public function testEverythingAnEditorTypedIsEscapedAndNothingIsCutOff(): void
    {
        $hostile = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';
        $long = str_repeat('Een heel lange review die gewoon doorloopt. ', 30) . 'EINDE';

        $html = $this->render($this->content([
            $this->review($hostile . "\nTweede regel", ['name' => $hostile, 'role' => $hostile, 'source_label' => $hostile, 'source_url' => 'https://example.org/?a="><script>']),
            $this->review($long),
        ], ['title' => $hostile, 'eyebrow' => $hostile, 'lead' => $hostile, 'layout' => 'cards']));

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertMatchesRegularExpression('#<br>\s*Tweede regel#', $html, 'a line break is kept');
        self::assertStringContainsString('EINDE', $html, 'a long review is printed whole');
        self::assertStringNotContainsString('line-clamp', self::read('assets/css/blocks/reviews.css'), 'no text is cut off');
        self::assertStringNotContainsString('text-overflow', self::read('assets/css/blocks/reviews.css'));
    }

    public function testTheButtonIsOptionalAndUsesTheButtonStyles(): void
    {
        $reviews = [$this->review('Tekst')];
        self::assertStringNotContainsString('reviews__actions', $this->render($this->content($reviews)));

        $default = $this->xpath($this->render($this->content($reviews, ['button' => ['href' => '/ervaringen', 'label' => 'Bekijk alle ervaringen']])));
        $link = $default->query('//div[contains(@class, "reviews__actions")]/a')->item(0);
        self::assertSame('/ervaringen', $link->getAttribute('href'));
        self::assertSame('btn btn--ghost', $link->getAttribute('class'));
        self::assertSame('Bekijk alle ervaringen', $link->textContent);

        $styled = $this->xpath($this->render($this->content($reviews, ['button' => ['href' => '/ervaringen', 'label' => 'Meer'], 'button_style' => 7])));
        self::assertSame('btn btn-style-7', $styled->query('//div[contains(@class, "reviews__actions")]/a')->item(0)->getAttribute('class'));
    }

    // ------------------------------------------------------------ the theme

    public function testTheStylesheetOnlyUsesTheThemesColoursAndLetters(): void
    {
        $css = preg_replace('#/\*.*?\*/#s', '', self::read('assets/css/blocks/reviews.css'));

        self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b/i', $css, 'no colour of its own');
        self::assertDoesNotMatchRegularExpression('/rgba?\(\s*\d/', $css, 'only rgba(var(--…-rgb), …)');
        self::assertDoesNotMatchRegularExpression('/font-family:(?!\s*var\()/', $css, 'letters from the Font Library');
        foreach (['var(--color-primary)', 'var(--color-surface)', 'var(--color-text)', 'var(--font-display)', 'var(--sp-4)', 'var(--radius-lg)'] as $token) {
            self::assertStringContainsString($token, $css, $token);
        }

        // Fewer columns on smaller screens, never a horizontal page.
        self::assertMatchesRegularExpression('/@media \(max-width: 1000px\)\{\s*\.reviews--cards\{ grid-template-columns: repeat\(2, minmax\(0, 1fr\)\); \}/', $css);
        self::assertMatchesRegularExpression('/@media \(max-width: 640px\)\{\s*\.reviews--cards,\s*\.reviews--cards\.reviews--single\{ grid-template-columns: minmax\(0, 1fr\); \}/', $css);
        // A moving strip gets no moving dots behind it.
        self::assertStringContainsString('.reviews-section--carousel > .block-decor--sparks{ display: none; }', $css);
    }

    public function testExtraVormgevingLandsOnTheRootOfEveryLayout(): void
    {
        foreach (ReviewsContent::LAYOUTS as $layout) {
            $html = BlockAppearance::apply(
                $this->render($this->content([$this->review('Tekst')], ['layout' => $layout])),
                ['background' => 'subtle', 'border' => 'both', 'border_tone' => 'accent', 'spacing' => 'spacious', 'decoration' => 'sparks']
            );

            self::assertMatchesRegularExpression(
                '/^\s*<section class="reviews-section reviews-section--' . $layout . ' block-appearance block-appearance--bg-subtle [^"]*block-appearance--decor-sparks" data-reviews><div class="block-decor block-decor--sparks"/',
                $html,
                $layout
            );
        }
    }

    // -------------------------------------------------------- the definition

    public function testTheDefinitionIsARegisteredReviewsBlock(): void
    {
        $definition = BlockDefinitions::get('reviews');
        self::assertInstanceOf(ReviewsBlock::class, $definition);
        self::assertSame(BlockCategories::CONTENT, $definition->category());
        self::assertTrue($definition->meta()['manual_add']);
        self::assertTrue($definition->meta()['allow_multiple']);
        self::assertNull($definition->meta()['allowed_pages'] ?? null, 'pages, products and projects alike');
        self::assertArrayNotHasKey('owners', $definition->meta());
        self::assertStringContainsString('reviewkaarten, een grote quote of een carrousel', $definition->description());
        self::assertTrue($definition->opensAsDraft(), 'a new Reviews block is a draft until its first save');

        self::assertSame(['assets/css/responsive-media.css', 'assets/css/blocks/reviews.css'], $definition->styles());
        self::assertSame(['assets/js/blocks/reviews.js'], $definition->scripts());
        self::assertSame([], $definition->vendorScripts(), 'no carousel library');

        $support = $definition->appearanceSupport();
        self::assertInstanceOf(AppearanceSupport::class, $support);
        self::assertTrue($support->background && $support->borders && $support->spacing);
        self::assertSame(['sparks', 'glow', 'pattern'], $support->decorations);
    }

    public function testTheWordsArePerLanguageAndOnlyTheTextIsRequired(): void
    {
        $fields = BlockDefinitions::get('reviews')->translatableFields();

        self::assertSame(['eyebrow', 'title', 'lead', 'button_label'], array_map(static fn ($f) => $f->key, $fields[ReviewsContent::TABLE]));
        self::assertSame(['body', 'name', 'role', 'source_label'], array_map(static fn ($f) => $f->key, $fields[ReviewsContent::ITEMS]));

        $required = array_map(static fn ($f) => $f->key, array_filter($fields[ReviewsContent::ITEMS], static fn ($f) => $f->required));
        self::assertSame(['body'], array_values($required));
        foreach ([...$fields[ReviewsContent::TABLE], ...$fields[ReviewsContent::ITEMS]] as $field) {
            self::assertSame('plain', $field->kind, $field->key . ': plain text, escaped on output');
        }

        self::assertSame([ReviewsContent::ITEMS => ['parent' => ReviewsContent::TABLE, 'column' => 'review_block_id']], BlockDefinitions::get('reviews')->childTables());
        self::assertContains(ReviewsContent::ITEMS, BlockLocalization::ownerTables());
    }

    public function testTheSampleIsThreeReviewsAndOneOfThemBare(): void
    {
        $definition = BlockDefinitions::get('reviews');
        $sample = $definition->sampleContent(new BlockSamples());
        self::assertIsArray($sample);
        self::assertCount(3, $sample['reviews']);
        self::assertSame(5, $sample['reviews'][0]['rating']);
        self::assertSame(0, $sample['reviews'][2]['rating']);
        self::assertSame('', $sample['reviews'][2]['name']);

        ob_start();
        $definition->renderSample($sample, 'reviews-sample');
        $html = (string) ob_get_clean();
        self::assertSame(3, substr_count($html, '<figure'));
    }

    public function testTheEditorOffersFourLayoutsAndNoLookOfItsOwn(): void
    {
        $editor = self::read('admin/reviews.php');

        self::assertStringContainsString('<select id="reviews-layout" name="layout" class="admin-select"', $editor);
        self::assertSame(['cards', 'minimal', 'featured', 'carousel'], ReviewsContent::LAYOUTS);
        self::assertStringContainsString('data-reviews-sketch=', $editor);
        self::assertStringContainsString("'title' => \$name !== '' ? \$name : \$anonymous", $editor, 'a folded review without a name is "Anoniem"');
        self::assertStringContainsString('data-row-list-title-fallback=', $editor);
        foreach (['name="background', 'name="border', 'type="file"', 'appearance_'] as $absent) {
            self::assertStringNotContainsString($absent, $editor, 'one place for the look: Extra vormgeving, never ' . $absent);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function review(string $text, array $extra = []): array
    {
        return ReviewsContent::review(['text' => $text] + $extra);
    }

    /**
     * @param list<array<string, mixed>> $reviews
     * @param array<string, mixed>       $values
     *
     * @return array<string, mixed>
     */
    private function content(array $reviews, array $values = []): array
    {
        return $values + [
            'state' => ReviewsContent::STATE_ACTIVE,
            'eyebrow' => '',
            'title' => '',
            'lead' => '',
            'layout' => 'cards',
            'header_align' => 'left',
            'reviews' => $reviews,
            'featured' => 0,
            'button' => null,
            'button_style' => null,
        ];
    }

    /** @param array<string, mixed> $content */
    private function render(array $content): string
    {
        ob_start();
        try {
            render_section_reviews($content, 'reviews-1');
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents(self::ROOT . '/' . $relative);
        self::assertIsString($contents, $relative);

        return str_replace("\r\n", "\n", $contents);
    }

    /** The declarations of the first top-level rule for exactly this selector. */
    private static function rule(string $css, string $selector): string
    {
        $found = preg_match('/^' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m', $css, $match);
        self::assertSame(1, $found, $selector);

        return $match[1];
    }
}
