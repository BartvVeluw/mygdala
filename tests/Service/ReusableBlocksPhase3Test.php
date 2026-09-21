<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CardCarouselRepository;
use App\Repository\DetailSectionRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\CardCarouselContent;
use App\Service\DetailSectionContent;
use App\Service\PageContent;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 of the content-block refactor (docs/content-blocks/PHASE-3.md):
 * the two Diensten blocks become ordinary, reusable, editor-managed blocks.
 *
 * What this file owns, and what it leaves to its neighbours:
 * Tests\Service\ContentBlockArchitectureTest owns the phase 1 architecture
 * rules and Tests\Service\ReusableBlocksPhase2Test the phase 2 promises. Here
 * we assert the phase 3 promises: both new types are addable and repeatable
 * anywhere, a carousel renders correctly with 0, 1 and n cards, two
 * instances keep independent content, image position left/right really
 * changes the markup, and the quicknav follows the sections instead of a
 * hardcoded list. That an existing installation lost no block in the
 * migration is Tests\Install\LegacyUpgradeTest's to prove.
 *
 * Blocks are created on a throwaway page of this test's own, so nothing here
 * can touch real site content; tearDown deletes each created block through
 * SectionRegistry::delete() — the same path the CMS uses — and then the page.
 */
final class ReusableBlocksPhase3Test extends TestCase
{
    private const TEST_KEY = '__test_phase3__';

    private PageRepository $pages;
    private PageSectionRepository $sections;
    private int $pageId;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->pages = new PageRepository();
        $this->sections = new PageSectionRepository();

        $this->removeTestPage();

        $this->pageId = \Tests\Support\PageFixture::create([
            'content_key' => self::TEST_KEY,
            'slug' => self::TEST_KEY,
            'status' => 'draft',
        ], 'Fase 3 blokkentest');
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $row = $this->sections->findById($id);
            if ($row === null) {
                continue;
            }
            SectionRegistry::delete($row, $this->sections);
        }
        $this->created = [];

        $this->removeTestPage();

        DetailSectionContent::clearCache();
        CardCarouselContent::clearCache();
    }

    private function removeTestPage(): void
    {
        $db = Database::connection();

        $stmt = $db->prepare('SELECT id FROM pages WHERE content_key = :key');
        $stmt->execute(['key' => self::TEST_KEY]);
        $row = $stmt->fetch();

        if ($row !== false) {
            $del = $db->prepare('DELETE FROM page_sections WHERE page_id = :id');
            $del->execute(['id' => (int) $row['id']]);

            $del = $db->prepare('DELETE FROM pages WHERE id = :id');
            $del->execute(['id' => (int) $row['id']]);
        }

        // Only ever this test's own throwaway page_slug.
        foreach (['detail_sections', 'card_carousels'] as $table) {
            // The block's words per language first, its child rows' included, or they stay behind as orphans.
            \Tests\Support\BlockTextFixture::removeForPage($table, self::TEST_KEY);
            $del = $db->prepare("DELETE FROM {$table} WHERE page_slug = :key");
            $del->execute(['key' => self::TEST_KEY]);
        }

        PageContent::clearCache();
    }

    /**
     * @return array{0: int, 1: string} [page_sections id, section_key]
     */
    private function addBlock(string $type): array
    {
        [$sectionId, $sectionKey] = SectionRegistry::create($type, self::TEST_KEY);
        $id = $this->sections->create($this->pageId, self::TEST_KEY, $type, $sectionKey, $sectionId);
        $this->created[] = $id;

        return [$id, (string) $sectionKey];
    }

    /**
     * @return array<string, mixed>
     */
    private function testPage(): array
    {
        $page = $this->pages->findById($this->pageId);
        $this->assertNotNull($page);

        return $page;
    }

    private function renderBlock(int $pageSectionId): string
    {
        DetailSectionContent::clearCache();
        CardCarouselContent::clearCache();

        $row = $this->sections->findById($pageSectionId);
        $this->assertNotNull($row);

        ob_start();
        SectionRegistry::render($row);

        return (string) ob_get_clean();
    }

    // -------------------------------------------------------- capabilities

    public function testBothPhaseThreeBlocksAreAddableAndRepeatableAnywhere(): void
    {
        $available = SectionRegistry::availableForPage($this->testPage(), $this->sections);

        foreach (['detail_section', 'card_carousel'] as $type) {
            $this->assertArrayHasKey(
                $type,
                $available,
                "\"{$type}\" must be addable on an ordinary page — that is what \"reusable\" means"
            );
            $this->assertTrue(SectionRegistry::isManuallyAddable($type));
            $this->assertTrue(SectionRegistry::isDeletable($type));
            $this->assertFalse(SectionRegistry::isFixed($type), "\"{$type}\" is no longer a fixed block");
            $this->assertTrue(SectionRegistry::allowMultiple($type), "\"{$type}\" must be repeatable");
            $this->assertNull(SectionRegistry::maxInstances($type), "\"{$type}\" must have no instance cap");
            $this->assertNull(SectionRegistry::types()[$type]['allowed_pages'], "\"{$type}\" must not be tied to one page");
        }
    }

    public function testNoServiceSpecificImplementationIsLeftBehind(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ([
            'src/Service/ServiceContent.php',
            'src/Repository/ServiceRepository.php',
            'admin/service-detail.php',
            'partials/section-services-carousel.php',
            'partials/section-service-details.php',
            'api/admin/update-service-section.php',
        ] as $path) {
            $this->assertFileDoesNotExist(
                $root . '/' . $path,
                "{$path} implemented the four hardcoded material keys and has no reason to exist any more"
            );
        }

        foreach (['services_carousel', 'service_details', 'service_quicknav'] as $type) {
            $this->assertFalse(SectionRegistry::exists($type), "\"{$type}\" was replaced in phase 3");
        }

        $tables = Database::connection()->query("SHOW TABLES LIKE 'service%'")->fetchAll();
        $this->assertSame([], $tables, 'the services* tables were copied into the new block tables and dropped');
    }

    // ------------------------------------------------- variable card counts

    public function testACarouselRendersNothingWithoutCardsAndGrowsWithThem(): void
    {
        [$blockId, $sectionKey] = $this->addBlock('card_carousel');

        $repository = new CardCarouselRepository();
        $carousel = $repository->findBySlugAndKey(self::TEST_KEY, $sectionKey);
        $this->assertNotNull($carousel);
        $carouselId = (int) $carousel['id'];

        $this->assertSame(
            '',
            trim($this->renderBlock($blockId)),
            'a carousel with no cards must render nothing at all, not an empty stage'
        );

        $this->card($repository, $carouselId, 'Eerste kaart');
        $html = $this->renderBlock($blockId);
        $this->assertSame(1, substr_count($html, 'data-orbit-card'));
        $this->assertStringContainsString('Eerste kaart', $html);
        $this->assertStringContainsString('orbit-card__media--icon', $html, 'a card without an image falls back to the icon');

        // Five is not four: nothing may assume the old fixed number of
        // material cards.
        foreach (['Twee', 'Drie', 'Vier', 'Vijf'] as $title) {
            $this->card($repository, $carouselId, $title);
        }

        $html = $this->renderBlock($blockId);
        $this->assertSame(5, substr_count($html, 'data-orbit-card'));
        $this->assertStringContainsString('<span class="service-row__index">05</span>', $html);
        $this->assertStringContainsString('Vijf', $html);

        $content = CardCarouselContent::forSection(self::TEST_KEY, $sectionKey);
        $this->assertCount(5, $content['cards']);
    }

    public function testAHiddenCardDropsOutAndTheRestStayNumberedContiguously(): void
    {
        [$blockId, $sectionKey] = $this->addBlock('card_carousel');

        $repository = new CardCarouselRepository();
        $carouselId = (int) $repository->findBySlugAndKey(self::TEST_KEY, $sectionKey)['id'];

        $this->card($repository, $carouselId, 'Zichtbaar een');
        $hiddenId = $this->card($repository, $carouselId, 'Verborgen');
        $this->card($repository, $carouselId, 'Zichtbaar twee');

        $repository->updateCard($hiddenId, ['is_active' => false]);

        $html = $this->renderBlock($blockId);

        $this->assertSame(2, substr_count($html, 'data-orbit-card'));
        $this->assertStringNotContainsString('Verborgen', $html);
        $this->assertStringContainsString('<span class="service-row__index">01</span>', $html);
        $this->assertStringContainsString('<span class="service-row__index">02</span>', $html);
        $this->assertStringNotContainsString('<span class="service-row__index">03</span>', $html);
    }

    // ------------------------------------------------- independent instances

    public function testTwoInstancesOfEitherTypeKeepSeparateContent(): void
    {
        [$firstBlock, $firstKey] = $this->addBlock('detail_section');
        [$secondBlock, $secondKey] = $this->addBlock('detail_section');

        $this->assertNotSame($firstKey, $secondKey, 'each instance gets its own section_key');

        $this->sectionWords($firstKey, ['title' => 'Eerste sectie']);
        $this->sectionWords($secondKey, ['title' => 'Tweede sectie']);

        $firstHtml = $this->renderBlock($firstBlock);
        $secondHtml = $this->renderBlock($secondBlock);

        $this->assertStringContainsString('Eerste sectie', $firstHtml);
        $this->assertStringNotContainsString('Tweede sectie', $firstHtml);
        $this->assertStringContainsString('Tweede sectie', $secondHtml);
        $this->assertStringNotContainsString('Eerste sectie', $secondHtml);

        [$carouselBlock, $carouselKey] = $this->addBlock('card_carousel');
        [, $otherCarouselKey] = $this->addBlock('card_carousel');

        $carousels = new CardCarouselRepository();
        $carouselId = (int) $carousels->findBySlugAndKey(self::TEST_KEY, $carouselKey)['id'];
        BlockLocalization::save('card_carousels', $carouselId, BlockLocalization::defaultLanguage(), ['title' => 'Eerste carrousel']);
        BlockLocalization::save('card_carousels', (int) $carousels->findBySlugAndKey(self::TEST_KEY, $otherCarouselKey)['id'], BlockLocalization::defaultLanguage(), ['title' => 'Tweede carrousel']);
        $this->card($carousels, $carouselId, 'Kaart A');

        $html = $this->renderBlock($carouselBlock);
        $this->assertStringContainsString('Eerste carrousel', $html);
        $this->assertStringNotContainsString('Tweede carrousel', $html);
    }

    // ------------------------------------------------------- image position

    public function testImagePositionLeftAndRightRenderDifferentMarkup(): void
    {
        [$blockId, $sectionKey] = $this->addBlock('detail_section');

        $repository = new DetailSectionRepository();
        // Deliberately NOT a path under assets/images/sections/, so the
        // uploader's delete() in tearDown treats it as a shared site asset
        // and leaves the filesystem alone.
        $repository->updateMainImage(
            (int) $repository->findBySlugAndKey(self::TEST_KEY, $sectionKey)['id'],
            ['main_image_path' => 'assets/images/hero-collage-a.webp']
        );
        $this->sectionWords($sectionKey, ['title' => 'Met beeld', 'main_image_alt' => 'Testbeeld']);

        $repository->upsertSection(self::TEST_KEY, $sectionKey, [
            'image_position' => 'image_right',
            'is_active' => true,
        ]);
        $right = $this->renderBlock($blockId);

        $this->assertStringContainsString('service-detail__media', $right);
        $this->assertStringContainsString('alt="Testbeeld"', $right);
        $this->assertStringNotContainsString('service-detail__head--image-left', $right);

        $repository->upsertSection(self::TEST_KEY, $sectionKey, [
            'image_position' => 'image_left',
            'is_active' => true,
        ]);
        $left = $this->renderBlock($blockId);

        $this->assertStringContainsString('service-detail__head--image-left', $left);

        // A section without a main image renders neither the media block nor
        // the flip modifier, whatever the stored position says — that is why
        // every migrated material section still looks exactly as before.
        $repository->clearMainImage((int) $repository->findBySlugAndKey(self::TEST_KEY, $sectionKey)['id']);
        $none = $this->renderBlock($blockId);

        $this->assertStringNotContainsString('service-detail__media', $none);
        $this->assertStringNotContainsString('service-detail__head--image-left', $none);
    }

    // ------------------------------------------------------------- quicknav

    public function testTheQuicknavFollowsTheSectionsInsteadOfAHardcodedList(): void
    {
        [, $firstKey] = $this->addBlock('detail_section');
        [, $secondKey] = $this->addBlock('detail_section');
        [, $thirdKey] = $this->addBlock('detail_section');

        $repository = new DetailSectionRepository();
        $repository->upsertSection(self::TEST_KEY, $firstKey, ['anchor' => 'eerste', 'is_active' => true]);
        $this->sectionWords($firstKey, ['title' => 'Lange titel over hout', 'nav_label' => 'Eerste']);
        // No anchor: not linkable, so it must not appear in the nav.
        $repository->upsertSection(self::TEST_KEY, $secondKey, ['anchor' => '', 'is_active' => true]);
        $this->sectionWords($secondKey, ['title' => 'Zonder anker']);
        // No nav label: falls back to its own title.
        $repository->upsertSection(self::TEST_KEY, $thirdKey, ['anchor' => 'derde', 'is_active' => true]);
        $this->sectionWords($thirdKey, ['title' => 'Derde sectie']);

        DetailSectionContent::clearCache();
        $items = DetailSectionContent::navItemsForPage(self::TEST_KEY);

        $this->assertSame(['eerste', 'derde'], array_column($items, 'anchor'));
        $this->assertSame(['Eerste', 'Derde sectie'], array_column($items, 'label'));
    }

    /** A new, visible card with its title in the default language, the way the CMS adds one. */
    private function card(CardCarouselRepository $repository, int $carouselId, string $title): int
    {
        $cardId = $repository->createCard($carouselId);
        BlockLocalization::save('carousel_cards', $cardId, BlockLocalization::defaultLanguage(), ['title' => $title]);

        return $cardId;
    }

    /**
     * The words of one of this page's detail sections, in the default
     * language; the fields left out are empty.
     *
     * @param array<string, string> $words field => words
     */
    private function sectionWords(string $sectionKey, array $words): void
    {
        $section = (new DetailSectionRepository())->findBySlugAndKey(self::TEST_KEY, $sectionKey);
        $this->assertNotNull($section);

        BlockLocalization::save('detail_sections', (int) $section['id'], BlockLocalization::defaultLanguage(), $words);
    }
}
