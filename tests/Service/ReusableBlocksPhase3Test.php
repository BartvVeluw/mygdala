<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CardCarouselRepository;
use App\Repository\DetailSectionRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\CardCarouselContent;
use App\Service\DetailSectionContent;
use App\Service\PageContent;
use App\Service\SectionRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * Phase 3 of the content-block refactor (docs/content-blocks/PHASE-3.md):
 * the two Diensten blocks become ordinary, reusable, editor-managed blocks.
 *
 * What this file owns, and what it leaves to its neighbours:
 * Tests\Service\ContentBlockArchitectureTest owns the phase 1 architecture
 * rules, Tests\Service\ReusableBlocksPhase2Test the phase 2 promises, and
 * Tests\Repository\PageSectionsBackfillTest "no attachment was lost". Here we
 * assert the phase 3 promises: both new types are addable and repeatable
 * anywhere, a carousel renders correctly with 0, 1 and n cards, two
 * instances keep independent content, image position left/right really
 * changes the markup, the quicknav follows the sections instead of a
 * hardcoded list, and every piece of the migrated Diensten content is still
 * on the public site.
 *
 * Blocks are created on a throwaway page of this test's own, so nothing here
 * can touch real site content; tearDown deletes each created block through
 * SectionRegistry::delete() — the same path the CMS uses — and then the page.
 */
final class ReusableBlocksPhase3Test extends TestCase
{
    private const TEST_KEY = '__test_phase3__';

    /** The four material sections, in the order the Diensten page renders them. */
    private const MIGRATED_SECTIONS = ['hout', 'metaal', 'acryl-glas', 'zakelijk'];

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

        $this->pageId = $this->pages->create([
            'content_key' => self::TEST_KEY,
            'slug' => self::TEST_KEY,
            'title' => 'Fase 3 blokkentest',
            'status' => 'draft',
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);
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

    /**
     * @return array{status: int, body: string}|null null when the request could not be made at all
     */
    private function request(string $path): ?array
    {
        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 5, 'follow_location' => 0],
        ]);

        $body = @file_get_contents(TestEnvironment::baseUrl() . $path, false, $context);
        if ($body === false && !isset($http_response_header)) {
            return null;
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => (string) $body];
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

        $repository->createCard($carouselId, ['title_nl' => 'Eerste kaart']);
        $html = $this->renderBlock($blockId);
        $this->assertSame(1, substr_count($html, 'data-orbit-card'));
        $this->assertStringContainsString('Eerste kaart', $html);
        $this->assertStringContainsString('orbit-card__media--icon', $html, 'a card without an image falls back to the icon');

        // Five is not four: nothing may assume the old fixed number of
        // material cards.
        foreach (['Twee', 'Drie', 'Vier', 'Vijf'] as $title) {
            $repository->createCard($carouselId, ['title_nl' => $title]);
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

        $repository->createCard($carouselId, ['title_nl' => 'Zichtbaar een']);
        $hiddenId = $repository->createCard($carouselId, ['title_nl' => 'Verborgen']);
        $repository->createCard($carouselId, ['title_nl' => 'Zichtbaar twee']);

        $repository->updateCard($hiddenId, ['title_nl' => 'Verborgen', 'is_active' => false]);

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

        $repository = new DetailSectionRepository();
        $repository->upsertSection(self::TEST_KEY, $firstKey, ['title_nl' => 'Eerste sectie', 'is_active' => true]);
        $repository->upsertSection(self::TEST_KEY, $secondKey, ['title_nl' => 'Tweede sectie', 'is_active' => true]);

        $firstHtml = $this->renderBlock($firstBlock);
        $secondHtml = $this->renderBlock($secondBlock);

        $this->assertStringContainsString('Eerste sectie', $firstHtml);
        $this->assertStringNotContainsString('Tweede sectie', $firstHtml);
        $this->assertStringContainsString('Tweede sectie', $secondHtml);
        $this->assertStringNotContainsString('Eerste sectie', $secondHtml);

        [$carouselBlock, $carouselKey] = $this->addBlock('card_carousel');
        [, $otherCarouselKey] = $this->addBlock('card_carousel');

        $carousels = new CardCarouselRepository();
        $carousels->upsertCarousel(self::TEST_KEY, $carouselKey, ['title_nl' => 'Eerste carrousel', 'is_active' => true]);
        $carousels->upsertCarousel(self::TEST_KEY, $otherCarouselKey, ['title_nl' => 'Tweede carrousel', 'is_active' => true]);
        $carousels->createCard((int) $carousels->findBySlugAndKey(self::TEST_KEY, $carouselKey)['id'], ['title_nl' => 'Kaart A']);

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
            ['main_image_path' => 'assets/images/hero-collage-a.webp', 'main_image_alt_nl' => 'Testbeeld']
        );

        $repository->upsertSection(self::TEST_KEY, $sectionKey, [
            'title_nl' => 'Met beeld',
            'image_position' => 'image_right',
            'is_active' => true,
        ]);
        $right = $this->renderBlock($blockId);

        $this->assertStringContainsString('service-detail__media', $right);
        $this->assertStringContainsString('alt="Testbeeld"', $right);
        $this->assertStringNotContainsString('service-detail__head--image-left', $right);

        $repository->upsertSection(self::TEST_KEY, $sectionKey, [
            'title_nl' => 'Met beeld',
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
        $repository->upsertSection(self::TEST_KEY, $firstKey, [
            'title_nl' => 'Lange titel over hout', 'anchor' => 'eerste', 'nav_label_nl' => 'Eerste', 'is_active' => true,
        ]);
        // No anchor: not linkable, so it must not appear in the nav.
        $repository->upsertSection(self::TEST_KEY, $secondKey, [
            'title_nl' => 'Zonder anker', 'anchor' => '', 'is_active' => true,
        ]);
        // No nav label: falls back to its own title.
        $repository->upsertSection(self::TEST_KEY, $thirdKey, [
            'title_nl' => 'Derde sectie', 'anchor' => 'derde', 'is_active' => true,
        ]);

        DetailSectionContent::clearCache();
        $items = DetailSectionContent::navItemsForPage(self::TEST_KEY);

        $this->assertSame(['eerste', 'derde'], array_column($items, 'anchor'));
        $this->assertSame(['Eerste', 'Derde sectie'], array_column($items, 'label_nl'));
    }

    // ------------------------------------------------- migrated content

    #[Group('migration-backfill')]
    public function testTheFourMaterialSectionsAreNowFourIndependentInstances(): void
    {
        $page = $this->pages->findByContentKey('diensten');
        $this->assertNotNull($page);

        // Only the FOUR keys the migration wrote. `detail_section` is a
        // repeatable block, so a fifth material section someone added in the
        // CMS is legitimate content — asserting the full list of instances
        // would turn normal use of the block into a migration failure.
        $attached = [];
        foreach ($this->sections->findForPage((int) $page['id']) as $row) {
            $key = (string) $row['section_key'];
            if ($row['section_type'] === 'detail_section' && in_array($key, self::MIGRATED_SECTIONS, true)) {
                $attached[] = $key;
            }
        }

        $this->assertSame(
            self::MIGRATED_SECTIONS,
            $attached,
            'the one service_details block became four ordinary blocks, once each, in the same order'
        );

        $repository = new DetailSectionRepository();
        foreach (self::MIGRATED_SECTIONS as $key) {
            $row = $repository->findBySlugAndKey('diensten', $key);
            $this->assertNotNull($row, "the \"{$key}\" section must have survived the migration");
            $this->assertSame($key, (string) $row['anchor'], 'the old service key stayed the anchor, so every #-link keeps working');
            $this->assertNotSame('', (string) $row['nav_label_nl'], 'the quicknav needs its short label');
            $this->assertNotSame('', trim((string) $row['content_html']), 'the paragraphs moved into the rich body');
        }
    }

    #[Group('migration-backfill')]
    public function testTheMigratedDienstenContentIsStillOnThePublicPage(): void
    {
        $response = $this->request('/diensten.php');
        if ($response === null) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        $this->assertSame(200, $response['status']);
        $body = $response['body'];

        foreach (self::MIGRATED_SECTIONS as $key) {
            $this->assertStringContainsString('id="' . $key . '"', $body, "the #{$key} anchor must keep resolving");
            $this->assertStringContainsString('href="#' . $key . '"', $body, 'the quicknav must still link to it');
        }

        foreach ([
            // One marker per kind of content the migration had to carry over.
            'Hout graveren',
            'Persoonlijke gravures op hout',
            'Een naam, tekst, tekening, logo of foto wordt met de laser',
            'Aluminium visitekaartjes',
            'Ook kleine, gevoelige stukken zoals een herinneringssieraad',
            'assets/images/snijplank-just-married.webp',
            'Bespreek jouw zakelijke aanvraag',
        ] as $marker) {
            $this->assertStringContainsString($marker, $body, "\"{$marker}\" disappeared from Diensten in the migration");
        }

        // AT LEAST the four migrated sections — each one is checked by
        // anchor above. A fifth material section added in the CMS is
        // ordinary content, exactly as for the carousel cards below.
        $this->assertGreaterThanOrEqual(
            4,
            substr_count($body, '<section class="service-detail'),
            'the four migrated material sections must all still render'
        );
    }

    #[Group('migration-backfill')]
    public function testTheMigratedCarouselStillShowsTheSameCardsOnTheHomepage(): void
    {
        $response = $this->request('/');
        if ($response === null) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        $this->assertSame(200, $response['status']);
        $body = $response['body'];

        $this->assertStringContainsString('orbit-carousel', $body);
        $this->assertStringContainsString('Vier materialen, eindeloos veel mogelijkheden', $body, 'the block heading became content and must still render');

        // AT LEAST the four migrated cards. Since phase 3 the card list is
        // ordinary, editor-managed content, so a fifth card someone added in
        // the CMS is legitimate — asserting an exact count would turn normal
        // use of the block into a test failure. What this test is actually
        // about is that the migration lost nothing, and that is checked card
        // by card below.
        $this->assertGreaterThanOrEqual(
            count(self::MIGRATED_SECTIONS),
            substr_count($body, 'data-orbit-card'),
            'the migrated carousel cards must all still be on the homepage'
        );

        foreach (self::MIGRATED_SECTIONS as $key) {
            $this->assertStringContainsString('diensten.php#' . $key, $body, "the {$key} card must still link to its section");
        }

        foreach ([
            'Snijplanken, onderzetters, kruidenkistjes',
            'Meer over hout graveren',
            'assets/images/visitekaartje-metaal-barbershop.webp',
        ] as $marker) {
            $this->assertStringContainsString($marker, $body, "\"{$marker}\" disappeared from the homepage carousel");
        }

        // Acryl & glas has never had a teaser photo — its card must still
        // fall back to the theme's icon.
        $this->assertStringContainsString('orbit-card__media--icon', $body);
    }
}
