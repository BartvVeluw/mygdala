<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\SpacerRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockSamples;
use App\Service\Blocks\CardCarouselBlock;
use App\Service\Blocks\FormBlock;
use App\Service\Blocks\ProductGridBlock;
use App\Service\Blocks\RichTextBlock;
use App\Service\Blocks\SpacerBlock;
use App\Service\CardCarouselContent;
use App\Service\FormBlockContent;
use App\Service\RichTextContent;
use App\Service\SpacerContent;
use PHPUnit\Framework\TestCase;

/**
 * Content Blocks Polish 1, what the visitor gets: the Tekstblok's width and
 * spacing, the Formulier's heading alignment, the grids without a title of
 * their own, the Witruimte block, and the carousel's heading alignment and
 * picture height. Every choice is a word from a closed list that the partial
 * turns into a class, and every default adds none (CONTENT-BLOCKS.md).
 *
 * The endpoints that store these choices are
 * Tests\Service\SimpleBlocksPolishHttpTest.
 */
final class SimpleBlocksPolishRenderTest extends TestCase
{
    private const SPACER_PAGE = 'zz-polish-spacer-test';

    public static function setUpBeforeClass(): void
    {
        // A partial is loaded by its block's definition file.
        class_exists(RichTextBlock::class);
        class_exists(ProductGridBlock::class);
        class_exists(SpacerBlock::class);
        class_exists(CardCarouselBlock::class);
    }

    protected function tearDown(): void
    {
        Database::connection()->prepare('DELETE FROM spacers WHERE page_slug = ?')->execute([self::SPACER_PAGE]);
        SpacerContent::clearCache();
    }

    // ------------------------------------------------------------ Tekstblok

    public function testTheTextBlockIsTheNarrowColumnByDefaultAndMediumIsTheSame(): void
    {
        $default = $this->richText([]);
        $medium = $this->richText(['width' => 'medium']);

        self::assertStringContainsString('class="container container--narrow"', $default);
        self::assertSame($default, $medium, 'medium is exactly the markup a text block always had');
        self::assertSame('medium', RichTextContent::width(''));
    }

    public function testALargeTextBlockHasTheNormalContentWidthOfTheSite(): void
    {
        $large = $this->richText(['width' => 'large']);

        self::assertStringContainsString('<div class="container">', $large);
        self::assertStringNotContainsString('container--narrow', $large);
        self::assertSame('large', RichTextContent::width('large'));
    }

    public function testAnUnknownWidthIsTheDefault(): void
    {
        self::assertSame('medium', RichTextContent::width('huge'));
        self::assertSame('medium', RichTextContent::width('LARGE'));
        self::assertStringContainsString('container--narrow', $this->richText(['width' => 'huge']));
        self::assertSame(['medium', 'large'], array_keys(RichTextContent::WIDTHS));
    }

    public function testTheWidthsAreTheSitesOwnContainers(): void
    {
        $core = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/core.css');

        self::assertStringContainsString('max-width: var(--container);', $core);
        self::assertStringContainsString('.container--narrow{ max-width: var(--container-narrow); }', $core);
    }

    public function testATextBlockHasTheSameRoomAboveAsBelowUnlessAHeroIsAbove(): void
    {
        $normal = $this->richText([]);
        $underHero = $this->richText([], true);

        // The room above and below is the one every section has: nothing on
        // the section itself takes the top away.
        self::assertStringContainsString('<section class="rich-text-section">', $normal);
        self::assertStringNotContainsString('padding-top', $normal);
        self::assertStringContainsString('<section class="rich-text-section" style="padding-top:0;">', $underHero);

        $core = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/core.css');
        self::assertStringContainsString('section{ position: relative; padding-block: var(--sp-7); }', $core);
    }

    public function testTwoTextBlocksInARowDoNotStackTwoPaddings(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/blocks/rich-text.css');

        self::assertStringContainsString('.rich-text-section + .rich-text-section{ padding-top: 0; }', $css);
        self::assertStringNotContainsString('px', preg_replace('#/\*.*?\*/#s', '', $css), 'no pixel value of its own');
    }

    public function testTheBlockTellsItsPartialWhetherAHeroIsAbove(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/Blocks/RichTextBlock.php');

        self::assertStringContainsString('render_section_rich_text($content, $tightTop);', $source);
    }

    // ------------------------------------------------------------ Formulier

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formAlignments(): iterable
    {
        yield 'left' => ['left', ''];
        yield 'center' => ['center', ' form-block__head--center'];
        yield 'right' => ['right', ' form-block__head--right'];
        yield 'unknown is left' => ['justify', ''];
    }

    /**
     * @dataProvider formAlignments
     */
    public function testTheFormHeadingAndIntroductionFollowTheAlignment(string $align, string $class): void
    {
        $html = $this->formBlock($align);

        self::assertStringContainsString('<h2 class="form-block__title' . $class . '">', $html);
        self::assertStringContainsString('<p class="form-block__intro' . $class . '">', $html);

        // The form itself never follows it.
        $form = substr($html, (int) strpos($html, '<form'));
        self::assertStringNotContainsString('form-block__head', $form);
        self::assertStringNotContainsString('text-align', $form);
    }

    public function testAnUnknownFormAlignmentIsLeft(): void
    {
        self::assertSame('left', FormBlockContent::headerAlign(''));
        self::assertSame('left', FormBlockContent::headerAlign('justify'));
        self::assertSame('right', FormBlockContent::headerAlign('right'));
        self::assertSame(['left', 'center', 'right'], FormBlockContent::HEADER_ALIGNMENTS);
    }

    public function testTheFormAlignmentClassesOnlyAlignText(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/blocks/form.css');

        self::assertMatchesRegularExpression('/\.form-block__head--center \{\s*text-align: center;\s*\}/', $css);
        self::assertMatchesRegularExpression('/\.form-block__head--right \{\s*text-align: right;\s*\}/', $css);
    }

    // ------------------------------------------------------------ Productgrid and Collectie-tegels

    public function testTheProductGridPrintsNoTitleOfItsOwn(): void
    {
        ob_start();
        render_section_product_grid();
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('<h2', $html);
        self::assertStringNotContainsString('Alle producten', $html);
        self::assertStringNotContainsString('All products', $html);
        self::assertStringContainsString('data-products-grid', $html, 'the grid itself is still there');
    }

    public function testTheCollectionTilesPrintNoTitleOfTheirOwn(): void
    {
        $definition = BlockDefinitions::get('shop_collections');
        self::assertNotNull($definition, 'the Shop module is on in this suite');

        $sample = $definition->sampleContent(new BlockSamples());
        ob_start();
        $definition->renderSample($sample, 'shop_collections-0');
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('<h2', $html);
        self::assertStringNotContainsString('>Collecties<', $html);
        self::assertStringNotContainsString('>Collections<', $html);
        self::assertSame(3, substr_count($html, 'class="collection-tile"'), 'the tiles themselves are still there');
    }

    // ------------------------------------------------------------ Witruimte

    /**
     * @return iterable<string, array{string}>
     */
    public static function spacerSizes(): iterable
    {
        foreach (array_keys(SpacerContent::SIZES) as $size) {
            yield $size => [$size];
        }
    }

    /**
     * @dataProvider spacerSizes
     */
    public function testASpacerIsOneEmptyElementWithItsHeight(string $size): void
    {
        $html = trim($this->spacer(['state' => SpacerContent::STATE_ACTIVE, 'size' => $size]));

        self::assertSame('<div class="spacer spacer--' . $size . '" aria-hidden="true"></div>', $html);
        self::assertSame('', trim(strip_tags($html)), 'no words');
        self::assertDoesNotMatchRegularExpression('/<(a|button|input|h[1-6]|section)\b|tabindex/', $html, 'nothing focusable, no heading, no landmark');
    }

    public function testTheSpacerSizesAreAClosedListWithMediumAsTheStart(): void
    {
        self::assertSame(['small', 'medium', 'large', 'xlarge'], array_keys(SpacerContent::SIZES));
        self::assertSame('medium', SpacerContent::DEFAULT_SIZE);
        self::assertSame('medium', SpacerContent::size('huge'));
        self::assertSame('medium', SpacerContent::size('"><script>'));
        self::assertStringContainsString('spacer--medium', $this->spacer(['state' => SpacerContent::STATE_ACTIVE, 'size' => '"><script>']));
    }

    public function testAMissingOrHiddenSpacerLeavesNoRoom(): void
    {
        self::assertSame('', trim($this->spacer(['state' => SpacerContent::STATE_FALLBACK, 'size' => 'large'])));
        self::assertSame('', trim($this->spacer(['state' => SpacerContent::STATE_HIDDEN, 'size' => 'large'])));
    }

    public function testEveryHeightIsAStepOfTheSpacingScaleAndSmallerOnAPhone(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/blocks/spacer.css');
        [$desktop, $phone] = explode('@media (max-width: 640px)', $css, 2);

        foreach (array_keys(SpacerContent::SIZES) as $size) {
            self::assertMatchesRegularExpression('/\.spacer--' . $size . '\{ height: var\(--sp-(\d)\); \}/', $desktop, $size);
            self::assertMatchesRegularExpression('/\.spacer--' . $size . '\{ height: var\(--sp-(\d)\); \}/', $phone, $size . ' on a phone');
            preg_match('/\.spacer--' . $size . '\{ height: var\(--sp-(\d)\); \}/', $desktop, $big);
            preg_match('/\.spacer--' . $size . '\{ height: var\(--sp-(\d)\); \}/', $phone, $small);
            self::assertLessThan((int) $big[1], (int) $small[1], $size . ' is lower on a phone');
        }
        $rules = preg_replace(['#/\*.*?\*/#s', '#@media[^{]*#'], '', $css);
        self::assertStringNotContainsString('px', $rules, 'no pixel value of its own');
    }

    public function testANewSpacerStartsAtMediumAndItsStoredHeightRenders(): void
    {
        $definition = new SpacerBlock();
        [$id, $key] = $definition->create(self::SPACER_PAGE);
        $section = ['page_slug' => self::SPACER_PAGE, 'section_key' => $key, 'section_id' => $id];

        self::assertSame(['state' => SpacerContent::STATE_ACTIVE, 'size' => 'medium'], SpacerContent::forSection(self::SPACER_PAGE, (string) $key));
        self::assertStringContainsString('spacer--medium', $this->render($definition, $section));
        self::assertSame('Middel', $definition->instanceTitle($section));

        (new SpacerRepository())->updateSize((int) $id, 'xlarge');
        SpacerContent::clearCache();
        self::assertStringContainsString('spacer--xlarge', $this->render($definition, $section));

        // A value from outside the list, written behind the CMS's back, is the default.
        Database::connection()->prepare('UPDATE spacers SET size = ? WHERE id = ?')->execute(['huge', $id]);
        SpacerContent::clearCache();
        self::assertStringContainsString('spacer--medium', $this->render($definition, $section));

        Database::connection()->prepare('UPDATE spacers SET is_active = 0 WHERE id = ?')->execute([$id]);
        SpacerContent::clearCache();
        self::assertSame('', trim($this->render($definition, $section)));
    }

    // ------------------------------------------------------------ Kaarten-carrousel

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function carouselAlignments(): iterable
    {
        yield 'left' => ['left', '<div class="section-head" data-reveal>'];
        yield 'center' => ['center', '<div class="section-head card-carousel__head--center" data-reveal>'];
        yield 'right' => ['right', '<div class="section-head card-carousel__head--right" data-reveal>'];
        yield 'unknown is left' => ['middle', '<div class="section-head" data-reveal>'];
    }

    /**
     * @dataProvider carouselAlignments
     */
    public function testTheCarouselHeadingFollowsTheAlignmentAndTheCardsDoNot(string $align, string $head): void
    {
        $html = $this->carousel(['header_align' => CardCarouselContent::headerAlign($align)]);

        self::assertStringContainsString($head, $html);
        $cards = substr($html, (int) strpos($html, 'orbit-carousel__stage'));
        self::assertStringNotContainsString('card-carousel__head', $cards);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function carouselImageHeights(): iterable
    {
        yield 'small' => ['small', 'class="orbit-carousel orbit-carousel--media-small"'];
        yield 'medium' => ['medium', 'class="orbit-carousel"'];
        yield 'large' => ['large', 'class="orbit-carousel orbit-carousel--media-large"'];
        yield 'unknown is medium' => ['huge', 'class="orbit-carousel"'];
    }

    /**
     * @dataProvider carouselImageHeights
     */
    public function testOnePictureHeightHoldsForTheWholeCarousel(string $height, string $class): void
    {
        $html = $this->carousel(['image_height' => CardCarouselContent::imageHeight($height)]);

        self::assertStringContainsString($class, $html);
        self::assertStringNotContainsString('orbit-card__media--', str_replace('orbit-card__media--icon', '', $html), 'no height per card');
    }

    public function testTheCarouselDefaultsAreTodaysCarousel(): void
    {
        self::assertSame(['left', 'center', 'right'], CardCarouselContent::HEADER_ALIGNMENTS);
        self::assertSame(['small', 'medium', 'large'], CardCarouselContent::IMAGE_HEIGHTS);
        self::assertSame('left', CardCarouselContent::headerAlign(''));
        self::assertSame('medium', CardCarouselContent::imageHeight(''));
        self::assertSame('medium', CardCarouselContent::imageHeight('huge'));

        $sample = (new CardCarouselBlock())->sampleContent(new BlockSamples());
        self::assertSame(['left', 'medium'], [$sample['header_align'], $sample['image_height']]);
    }

    public function testThePictureHeightsKeepTheTextRoomAndLetThePhoneAndRowRulesWin(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/blocks/card-carousel.css');

        // Cropped, never stretched, whatever the picture's aspect ratio.
        self::assertStringContainsString('.orbit-card__media img{ width: 100%; height: 100%; object-fit: cover; }', $css);

        // Card minus picture is the same text room at every height.
        preg_match('/\.orbit-carousel\{[^}]*--orbit-card-h: (\d+)px;[^}]*--orbit-media-h: (\d+)px;/s', $css, $base);
        preg_match('/^\.orbit-carousel--media-small\{ --orbit-media-h: (\d+)px; --orbit-card-h: (\d+)px; \}/m', $css, $small);
        preg_match('/^\.orbit-carousel--media-large\{ --orbit-media-h: (\d+)px; --orbit-card-h: (\d+)px; \}/m', $css, $large);
        self::assertNotEmpty($base);
        self::assertNotEmpty($small);
        self::assertNotEmpty($large);
        $text = (int) $base[1] - (int) $base[2];
        self::assertSame($text, (int) $small[2] - (int) $small[1]);
        self::assertSame($text, (int) $large[2] - (int) $large[1]);
        self::assertLessThan((int) $base[2], (int) $small[1]);
        self::assertGreaterThan((int) $base[2], (int) $large[1]);

        // The phone's flat strip and "naast elkaar" set the card height to
        // auto; they come later in the file, so they win over the presets.
        $presets = strpos($css, '.orbit-carousel--media-small{');
        self::assertLessThan(strpos($css, '@media (max-width: 699px)'), $presets);
        self::assertLessThan(strpos($css, '.orbit-carousel--row{ --orbit-card-h: auto; }'), $presets);
        self::assertMatchesRegularExpression('/@media \(max-width: 900px\)\{\s*\.orbit-carousel--media-small\{/', $css, 'smaller on a tablet, like the medium height');
    }

    // ------------------------------------------------------------ helpers

    /** @param array<string, mixed> $extra */
    private function richText(array $extra, bool $tightTop = false): string
    {
        ob_start();
        render_section_rich_text(['state' => RichTextContent::STATE_ACTIVE, 'body' => '<p>Tekst</p>', 'align' => 'left'] + $extra, $tightTop);

        return (string) ob_get_clean();
    }

    private function formBlock(string $align): string
    {
        $definition = new FormBlock();
        $sample = $definition->sampleContent(new BlockSamples());
        $sample['header_align'] = $align;

        ob_start();
        $definition->renderSample($sample, 'form-0');

        return (string) ob_get_clean();
    }

    /** @param array{state: string, size: string} $content */
    private function spacer(array $content): string
    {
        ob_start();
        render_section_spacer($content);

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $pageSection */
    private function render(SpacerBlock $definition, array $pageSection): string
    {
        ob_start();
        $definition->render($pageSection, false, 'spacer-0');

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $extra */
    private function carousel(array $extra): string
    {
        $sample = (new CardCarouselBlock())->sampleContent(new BlockSamples());

        ob_start();
        render_section_card_carousel($extra + $sample);

        return (string) ob_get_clean();
    }
}
