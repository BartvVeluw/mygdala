<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\FaqRepository;
use App\Repository\FeatureGridRepository;
use App\Repository\HomepageHeroRepository;
use App\Repository\PageHeroRepository;
use App\Repository\StatStripRepository;
use App\Repository\StepListRepository;
use App\Repository\TextImageSplitRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\FaqContent;
use App\Service\FeatureGridContent;
use App\Service\HomepageHeroContent;
use App\Service\PageHeroContent;
use App\Service\SiteSettings;
use App\Service\StatStripContent;
use App\Service\StepListContent;
use App\Service\TextImageSplitContent;
use PHPUnit\Framework\TestCase;

/**
 * An active block with nothing in it renders nothing.
 *
 * A row that exists is not yet content. Adding a FAQ, a step list, a stat
 * strip, a feature grid or a text + image block through the block picker
 * writes an active row with no heading and no items, and until someone fills
 * it in the page must not show an empty band with its vertical padding —
 * the rule CONTENT-BLOCKS.md states and partials/section-marquee.php and
 * partials/section-cta-band.php already follow.
 *
 * Per type, what counts as something to render:
 *
 *   faq, step_list      eyebrow or title, or at least one active item
 *   feature_grid        eyebrow, title or lead, or at least one active card
 *   stat_strip          at least one active stat
 *   text_image_split    eyebrow, title, a paragraph, an image or a button —
 *                       `layout` is not content
 *   page_hero,
 *   homepage_hero       a title
 *
 * Everything goes through the real block definitions — create() exactly as
 * the block picker calls it, render() exactly as SectionRegistry::renderPage()
 * does — inside a transaction that is rolled back afterwards. The heroes'
 * create() writes an editable starting title, so for them the empty case is
 * an active row without one.
 *
 * Hidden and missing rows are asked again here, with content in them, so each
 * type's whole contract sits in one place; Tests\Service\NoFallbackCopyTest
 * asks the same of the legacy addresses.
 */
final class NoEmptyActiveBlockTest extends TestCase
{
    /** Where new instances of this test are created; never a real page. */
    private const TEST_SLUG = '__test_no_empty_active__';

    private bool $inTransaction = false;

    protected function setUp(): void
    {
        // The partials resolve the primary language through SiteSettings.
        SiteSettings::all();
        self::clearContentCaches();

        Database::connection()->beginTransaction();
        $this->inTransaction = true;
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            Database::connection()->rollBack();
            $this->inTransaction = false;
        }

        self::clearContentCaches();
    }

    /** @return array<string, array{string}> */
    public static function allTypes(): array
    {
        return self::cases(['faq', 'feature_grid', 'step_list', 'stat_strip', 'text_image_split', 'page_hero', 'homepage_hero']);
    }

    /** @return array<string, array{string}> the types whose create() writes an empty row */
    public static function typesThatStartEmpty(): array
    {
        return self::cases(['faq', 'feature_grid', 'step_list', 'stat_strip', 'text_image_split']);
    }

    /** @return array<string, array{string}> */
    public static function heroes(): array
    {
        return self::cases(['page_hero', 'homepage_hero']);
    }

    /** @return array<string, array{string}> the types with items that can be hidden one by one */
    public static function typesWithItems(): array
    {
        return self::cases(['faq', 'feature_grid', 'step_list', 'stat_strip']);
    }

    /** @return array<string, array{string, string}> */
    public static function meaningfulContent(): array
    {
        $cases = [
            ['faq', 'eyebrow'], ['faq', 'title'], ['faq', 'item'],
            ['step_list', 'eyebrow'], ['step_list', 'title'], ['step_list', 'item'],
            ['stat_strip', 'item'],
            ['feature_grid', 'eyebrow'], ['feature_grid', 'title'], ['feature_grid', 'lead'], ['feature_grid', 'item'],
            ['text_image_split', 'eyebrow'], ['text_image_split', 'title'], ['text_image_split', 'paragraph'],
            ['text_image_split', 'image'], ['text_image_split', 'button'],
            ['page_hero', 'title'],
            ['homepage_hero', 'title'],
        ];

        $named = [];
        foreach ($cases as [$type, $what]) {
            $named[$type . ': ' . $what] = [$type, $what];
        }

        return $named;
    }

    /**
     * @dataProvider typesThatStartEmpty
     */
    public function testANewlyAddedBlockRendersNothingUntilItHasContent(string $type): void
    {
        [$pageSection, $row] = $this->addThroughThePicker($type);

        // The editor still gets what it needs: a real, active row to open.
        $this->assertNotNull($row, "Adding a {$type} must still create its content row.");
        $this->assertTrue((bool) $row['is_active'], "A newly added {$type} must still start out active.");

        $this->assertRendersNothing($this->render($pageSection), "a newly added {$type}");
    }

    /**
     * @dataProvider heroes
     */
    public function testANewlyAddedHeroStartsWithItsEditableTitle(string $type): void
    {
        if ($type === 'homepage_hero') {
            $this->deleteHomepageHero();
        }

        [$pageSection] = $this->addThroughThePicker($type);

        $html = $this->render($pageSection);

        $this->assertStringContainsString('pas deze titel aan', $html, "A newly added {$type} renders the title it starts with.");
        $this->assertStringContainsString('<section', $html);
    }

    /**
     * @dataProvider allTypes
     */
    public function testStructuralValuesAloneRenderNothing(string $type): void
    {
        $pageSection = $this->storeOnlyStructuralValues($type);

        $this->assertRendersNothing($this->render($pageSection), "a {$type} with only structural values");
    }

    /**
     * @dataProvider heroes
     */
    public function testAHeroWithoutATitleRendersNothingWhateverElseItHas(string $type): void
    {
        if ($type === 'page_hero') {
            // An image and every choice away from its default too: none of
            // them is what a page hero is for.
            $mediaId = (new \App\Repository\MediaRepository())->create([
                'path' => 'assets/media/__test_no_empty_active__.webp',
                'original_filename' => 'test-no-empty-active.webp',
                'mime_type' => 'image/webp',
                'width' => 1600,
                'height' => 900,
                'file_size' => 100,
                'alt_text' => 'Beeld',
                'checksum' => null,
            ]);
            (new PageHeroRepository())->upsert(self::TEST_SLUG, self::pageHeroValues([
                'media_id' => $mediaId,
                'content_position' => PageHeroContent::POSITION_CENTER,
                'title_size' => PageHeroContent::SIZE_LARGE,
                'text_size' => PageHeroContent::SIZE_LARGE,
            ]));
            self::pageHeroWords(['eyebrow' => 'Bovenschrift', 'lead' => 'Een inleiding']);
            $pageSection = self::pageSection($type, self::TEST_SLUG, null, 0);
        } else {
            $this->deleteHomepageHero();
            $repository = new HomepageHeroRepository();
            $repository->upsert(HomepageHeroContent::PAGE_SLUG, self::homepageHeroValues([
                'primary_url' => '/',
                'image_path' => 'assets/images/test-no-empty-active.webp',
            ]));
            $heroId = (int) $repository->findBySlug(HomepageHeroContent::PAGE_SLUG)['id'];
            BlockLocalization::save('homepage_hero', $heroId, 'nl', [
                'eyebrow' => 'Bovenschrift',
                'lead' => 'Een inleiding',
                'primary_label' => 'Knop',
                'image_alt' => 'Beeld',
                'badge_title' => 'Badge',
                'badge_text' => 'Badgetekst',
            ]);
            BlockLocalization::save('homepage_hero_stats', $repository->createStat($heroId), 'nl', ['primary_text' => 'Cijfer', 'secondary_text' => 'uitleg']);
            $pageSection = self::pageSection($type, HomepageHeroContent::PAGE_SLUG, null, 0);
        }

        $this->assertRendersNothing($this->render($pageSection), "a {$type} without a title");
    }

    /**
     * @dataProvider typesWithItems
     */
    public function testOnlyHiddenItemsRenderNothing(string $type): void
    {
        [$pageSection] = $this->addThroughThePicker($type);
        $sectionId = (int) $pageSection['section_id'];

        // A hidden item with all its words in the default language: hidden
        // is what keeps it off the page, not a missing word.
        switch ($type) {
            case 'faq':
                $repository = new FaqRepository();
                $itemId = $repository->createItem($sectionId);
                BlockLocalization::save('faq_items', $itemId, 'nl', ['question' => 'Verborgen vraag', 'answer' => 'antwoord']);
                $repository->updateItem($itemId, ['is_active' => false]);
                break;
            case 'feature_grid':
                $repository = new FeatureGridRepository();
                $itemId = $repository->createItem($sectionId, ['icon_key' => 'heart']);
                BlockLocalization::save('feature_grid_items', $itemId, 'nl', ['title' => 'Verborgen kaart', 'body' => 'kaart']);
                $repository->updateItem($itemId, ['icon_key' => 'heart', 'is_active' => false]);
                break;
            case 'step_list':
                $repository = new StepListRepository();
                $itemId = $repository->createItem($sectionId);
                BlockLocalization::save('step_list_items', $itemId, 'nl', ['title' => 'Verborgen stap', 'body' => 'stap']);
                $repository->updateItem($itemId, ['is_active' => false]);
                break;
            case 'stat_strip':
                $repository = new StatStripRepository();
                $itemId = $repository->createItem($sectionId);
                BlockLocalization::save('stat_strip_items', $itemId, 'nl', ['primary_text' => 'Verborgen cijfer', 'secondary_text' => 'uitleg']);
                $repository->updateItem($itemId, ['is_active' => false]);
                break;
        }

        $this->assertRendersNothing($this->render($pageSection), "a {$type} whose only item is hidden");
    }

    /**
     * @dataProvider meaningfulContent
     */
    public function testOneMeaningfulThingIsEnoughToRender(string $type, string $what): void
    {
        [$pageSection, $words] = $this->storeOneThing($type, $what);

        $html = $this->render($pageSection);

        $this->assertStringContainsString($words, $html, "A {$type} with only its {$what} filled in must render it.");
        $this->assertStringContainsString('<section', $html);
    }

    /**
     * @dataProvider allTypes
     */
    public function testAHiddenBlockRendersNothingEvenWithContent(string $type): void
    {
        [$pageSection, $words] = $this->storeOneThing($type, self::primaryContentOf($type));

        // Proves the row really has something to show before it is hidden.
        $this->assertStringContainsString($words, $this->render($pageSection));

        $this->hide($type, $pageSection);

        $this->assertRendersNothing($this->render($pageSection), "a hidden {$type}");
    }

    /**
     * @dataProvider allTypes
     */
    public function testAMissingRowRendersNothing(string $type): void
    {
        $pageSection = match ($type) {
            'page_hero' => self::pageSection($type, self::TEST_SLUG, null, 0),
            'homepage_hero' => self::pageSection($type, HomepageHeroContent::PAGE_SLUG, null, 0),
            default => self::pageSection($type, self::TEST_SLUG, 'custom-missing', 0),
        };

        if ($type === 'homepage_hero') {
            $this->deleteHomepageHero();
        }

        $this->assertRendersNothing($this->render($pageSection), "a {$type} without a row");
    }

    /* ------------------------------------------------------------------ */

    /**
     * Adds an instance exactly as the block picker does and returns the
     * page_sections row that would point at it, plus the content row.
     *
     * @return array{array<string, mixed>, array<string, mixed>|null}
     */
    private function addThroughThePicker(string $type): array
    {
        $definition = BlockDefinitions::get($type);
        $this->assertNotNull($definition, "Unknown block type {$type}");

        $pageSlug = $type === 'homepage_hero' ? HomepageHeroContent::PAGE_SLUG : self::TEST_SLUG;
        [$sectionId, $sectionKey] = $definition->create($pageSlug);

        $row = match ($type) {
            'faq' => (new FaqRepository())->findBySlugAndKey($pageSlug, (string) $sectionKey),
            'feature_grid' => (new FeatureGridRepository())->findBySlugAndKey($pageSlug, (string) $sectionKey),
            'step_list' => (new StepListRepository())->findBySlugAndKey($pageSlug, (string) $sectionKey),
            'stat_strip' => (new StatStripRepository())->findBySlugAndKey($pageSlug, (string) $sectionKey),
            'text_image_split' => (new TextImageSplitRepository())->findBySlugAndKey($pageSlug, (string) $sectionKey),
            'page_hero' => (new PageHeroRepository())->findBySlug($pageSlug),
            'homepage_hero' => (new HomepageHeroRepository())->findBySlug($pageSlug),
        };

        return [self::pageSection($type, $pageSlug, $sectionKey, (int) $sectionId), $row];
    }

    /**
     * An active row carrying the structural values its type has, and no
     * content.
     *
     * @return array<string, mixed>
     */
    private function storeOnlyStructuralValues(string $type): array
    {
        if ($type === 'page_hero') {
            // Every choice away from its default, so no choice can pass for
            // content.
            (new PageHeroRepository())->upsert(self::TEST_SLUG, self::pageHeroValues([
                'content_position' => PageHeroContent::POSITION_RIGHT,
                'title_size' => PageHeroContent::SIZE_LARGE,
                'text_size' => PageHeroContent::SIZE_SMALL,
            ]));

            return self::pageSection($type, self::TEST_SLUG, null, 0);
        }

        if ($type === 'homepage_hero') {
            $this->deleteHomepageHero();
            (new HomepageHeroRepository())->upsert(HomepageHeroContent::PAGE_SLUG, self::homepageHeroValues([
                'media_type' => HomepageHeroContent::MEDIA_TYPE_VIDEO,
                'layout' => HomepageHeroContent::LAYOUT_BACKGROUND,
                'title_highlight_size' => HomepageHeroContent::HIGHLIGHT_SIZE_MAX,
            ]));

            return self::pageSection($type, HomepageHeroContent::PAGE_SLUG, null, 0);
        }

        [$pageSection] = $this->addThroughThePicker($type);

        if ($type === 'text_image_split') {
            // The one structural value this type has, set to the
            // non-default branch so it cannot pass for the create() row.
            (new TextImageSplitRepository())->upsertSection(self::TEST_SLUG, (string) $pageSection['section_key'], [
                'layout' => 'image_left',
                'is_active' => true,
            ]);
        }

        return $pageSection;
    }

    /**
     * One piece of content, and nothing else, on a fresh instance.
     *
     * @return array{array<string, mixed>, string} the page_sections row and the words that must appear
     */
    private function storeOneThing(string $type, string $what): array
    {
        $words = 'Zichtbaar ' . $what;

        if ($type === 'page_hero') {
            (new PageHeroRepository())->upsert(self::TEST_SLUG, self::pageHeroValues([]));
            self::pageHeroWords(['title' => $words]);

            return [self::pageSection($type, self::TEST_SLUG, null, 0), $words];
        }

        if ($type === 'homepage_hero') {
            $this->deleteHomepageHero();
            $heroes = new HomepageHeroRepository();
            $heroes->upsert(HomepageHeroContent::PAGE_SLUG, self::homepageHeroValues([]));
            BlockLocalization::save('homepage_hero', (int) $heroes->findBySlug(HomepageHeroContent::PAGE_SLUG)['id'], 'nl', ['title' => $words]);

            return [self::pageSection($type, HomepageHeroContent::PAGE_SLUG, null, 0), $words];
        }

        [$pageSection] = $this->addThroughThePicker($type);
        $sectionId = (int) $pageSection['section_id'];
        $sectionKey = (string) $pageSection['section_key'];

        match ($type . ':' . $what) {
            'faq:eyebrow' => BlockLocalization::save('faq_sections', $sectionId, 'nl', ['eyebrow' => $words]),
            'faq:title' => BlockLocalization::save('faq_sections', $sectionId, 'nl', ['title' => $words]),
            'faq:item' => BlockLocalization::save('faq_items', (new FaqRepository())->createItem($sectionId), 'nl', ['question' => $words, 'answer' => 'Een antwoord']),
            'step_list:eyebrow' => BlockLocalization::save('step_list_sections', $sectionId, 'nl', ['eyebrow' => $words]),
            'step_list:title' => BlockLocalization::save('step_list_sections', $sectionId, 'nl', ['title' => $words]),
            'step_list:item' => BlockLocalization::save('step_list_items', (new StepListRepository())->createItem($sectionId), 'nl', ['title' => $words, 'body' => 'Een stap']),
            'stat_strip:item' => BlockLocalization::save('stat_strip_items', (new StatStripRepository())->createItem($sectionId), 'nl', ['primary_text' => $words, 'secondary_text' => 'uitleg']),
            'feature_grid:eyebrow' => BlockLocalization::save('feature_grids', $sectionId, 'nl', ['eyebrow' => $words]),
            'feature_grid:title' => BlockLocalization::save('feature_grids', $sectionId, 'nl', ['title' => $words]),
            'feature_grid:lead' => BlockLocalization::save('feature_grids', $sectionId, 'nl', ['lead' => $words]),
            'feature_grid:item' => BlockLocalization::save('feature_grid_items', (new FeatureGridRepository())->createItem($sectionId, ['icon_key' => 'heart']), 'nl', ['title' => $words, 'body' => 'Een kaart']),
            'text_image_split:eyebrow' => (new TextImageSplitRepository())->upsertSection(self::TEST_SLUG, $sectionKey, ['eyebrow_nl' => $words]),
            'text_image_split:title' => (new TextImageSplitRepository())->upsertSection(self::TEST_SLUG, $sectionKey, ['title_nl' => $words]),
            'text_image_split:paragraph' => (new TextImageSplitRepository())->createParagraph($sectionId, ['content_nl' => $words]),
            'text_image_split:image' => (new TextImageSplitRepository())->createImage($sectionId, [
                'image_path' => 'assets/images/test-no-empty-active.webp',
                'alt_nl' => $words,
            ]),
            'text_image_split:button' => (new TextImageSplitRepository())->upsertSection(self::TEST_SLUG, $sectionKey, [
                'button_label_nl' => $words,
                'button_url' => '/',
            ]),
        };

        return [$pageSection, $words];
    }

    /** @param array<string, mixed> $pageSection */
    private function hide(string $type, array $pageSection): void
    {
        $sectionKey = (string) $pageSection['section_key'];

        match ($type) {
            'faq' => (new FaqRepository())->upsertSection(self::TEST_SLUG, $sectionKey, ['is_active' => false]),
            'feature_grid' => (new FeatureGridRepository())->upsertGrid(self::TEST_SLUG, $sectionKey, ['is_active' => false]),
            'step_list' => (new StepListRepository())->upsertSection(self::TEST_SLUG, $sectionKey, ['is_active' => false]),
            'stat_strip' => (new StatStripRepository())->upsertStrip(self::TEST_SLUG, $sectionKey, ['is_active' => false]),
            'text_image_split' => (new TextImageSplitRepository())->upsertSection(self::TEST_SLUG, $sectionKey, ['is_active' => false]),
            'page_hero' => (new PageHeroRepository())->upsert(self::TEST_SLUG, self::pageHeroValues([
                'is_active' => false,
            ])),
            'homepage_hero' => (new HomepageHeroRepository())->upsert(HomepageHeroContent::PAGE_SLUG, self::homepageHeroValues([
                'is_active' => false,
            ])),
        };
    }

    /** The content a hidden instance of each type is given before it is hidden. */
    private static function primaryContentOf(string $type): string
    {
        return match ($type) {
            'text_image_split' => 'paragraph',
            'page_hero', 'homepage_hero' => 'title',
            default => 'item',
        };
    }

    /** @param list<string> $types */
    private static function cases(array $types): array
    {
        $cases = [];
        foreach ($types as $type) {
            $cases[$type] = [$type];
        }

        return $cases;
    }

    /** @param array<string, mixed> $pageSection */
    private function render(array $pageSection): string
    {
        $definition = BlockDefinitions::get((string) $pageSection['section_type']);
        $this->assertNotNull($definition);

        self::clearContentCaches();

        ob_start();
        try {
            $definition->render($pageSection, false, $pageSection['section_type'] . '-' . $pageSection['id']);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private function assertRendersNothing(string $html, string $what): void
    {
        // Exactly nothing: not an empty <section>, not a heading wrapper, and
        // not the whitespace a partial prints before its first tag either.
        $this->assertStringNotContainsString('<section', $html, "{$what} rendered a section.");
        $this->assertStringNotContainsString('section-head', $html, "{$what} rendered a heading wrapper.");
        $this->assertSame('', $html, "{$what} must render nothing at all.");
    }

    private function deleteHomepageHero(): void
    {
        Database::connection()
            ->prepare('DELETE FROM homepage_hero WHERE page_slug = :slug')
            ->execute(['slug' => HomepageHeroContent::PAGE_SLUG]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function pageSection(string $type, string $pageSlug, ?string $sectionKey, int $sectionId): array
    {
        return [
            'id' => $sectionId,
            'section_type' => $type,
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'section_id' => $sectionId,
        ];
    }

    /**
     * The Dutch words of the test page's Paginakop, in block_translations
     * (Multilingual 2.0 phase 3B); the header row must exist.
     *
     * @param array<string, string> $words field => words
     */
    private static function pageHeroWords(array $words): void
    {
        $row = (new PageHeroRepository())->findBySlug(self::TEST_SLUG);
        BlockLocalization::save('page_heroes', (int) $row['id'], 'nl', $words);
    }

    /**
     * Every column PageHeroRepository::upsert() writes, empty unless
     * overridden. The words are pageHeroWords().
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function pageHeroValues(array $overrides): array
    {
        return array_merge([
            'media_id' => null,
            'content_position' => PageHeroContent::POSITION_LEFT,
            'title_size' => PageHeroContent::SIZE_NORMAL,
            'text_size' => PageHeroContent::SIZE_NORMAL,
            'is_active' => true,
        ], $overrides);
    }

    /**
     * Every column HomepageHeroRepository::upsert() writes, empty unless
     * overridden, with the structural values a valid row needs.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function homepageHeroValues(array $overrides): array
    {
        return array_merge([
            'title_highlight_size' => HomepageHeroContent::HIGHLIGHT_SIZE_DEFAULT,
            'primary_url' => '',
            'secondary_url' => '',
            'image_path' => '',
            'media_type' => HomepageHeroContent::MEDIA_TYPE_IMAGE,
            'video_path' => '',
            'layout' => HomepageHeroContent::LAYOUT_MEDIA_RIGHT,
            'is_active' => true,
        ], $overrides);
    }

    private static function clearContentCaches(): void
    {
        FaqContent::clearCache();
        FeatureGridContent::clearCache();
        HomepageHeroContent::clearCache();
        PageHeroContent::clearCache();
        StatStripContent::clearCache();
        StepListContent::clearCache();
        TextImageSplitContent::clearCache();
    }
}
