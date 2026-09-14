<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\PortfolioModule;
use App\Module\ShopModule;
use App\Service\ItemGallerySources;
use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\ItemGalleryRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\ProductRepository;
use App\Service\ItemGalleryContent;
use App\Service\PageContent;
use App\Service\PortfolioGalleryContent;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 of the content-block refactor (docs/content-blocks/PHASE-4.md):
 * the two Portfolio blocks become ONE reusable block with a selectable
 * content source, and the filter bar and lightbox become settings on that
 * block instead of page-level CMS screens.
 *
 * What this file owns, and what it leaves to its neighbours:
 * Tests\Service\ContentBlockArchitectureTest owns the phase 1 architecture
 * rules and ReusableBlocksPhase2Test/ReusableBlocksPhase3Test the earlier
 * phases' promises. Here we assert the phase 4 promises: the block is
 * addable and repeatable anywhere, each source yields the right items, an
 * invalid source is refused instead of executed, filter bar and lightbox
 * follow the block's own setting, and two instances on one page stay
 * independent. That an existing installation lost no block in the migration
 * is Tests\Install\LegacyUpgradeTest's to prove.
 *
 * Blocks are created on a throwaway page of this test's own and the
 * collection-source test creates a throwaway collection (existing products
 * are only linked to it, never modified), so nothing here can change real
 * site content; tearDown deletes each created block through
 * SectionRegistry::delete() — the same path the CMS uses — then the
 * collection and the page.
 */
final class ReusableBlocksPhase4Test extends TestCase
{
    private const TEST_KEY = '__test_phase4__';
    private const TEST_COLLECTION_SLUG = 'test-phase4-collectie';

    private PageRepository $pages;
    private PageSectionRepository $sections;
    private int $pageId;

    /** @var list<int> */
    private array $created = [];

    private ?int $collectionId = null;

    protected function setUp(): void
    {
        $this->pages = new PageRepository();
        $this->sections = new PageSectionRepository();

        $this->cleanUp();

        $this->pageId = $this->pages->create([
            'content_key' => self::TEST_KEY,
            'slug' => self::TEST_KEY,
            'title' => 'Fase 4 blokkentest',
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

        $this->cleanUp();

        ItemGalleryContent::clearCache();
        PortfolioGalleryContent::clearCache();
    }

    private function cleanUp(): void
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
        $del = $db->prepare('DELETE FROM item_galleries WHERE page_slug = :key');
        $del->execute(['key' => self::TEST_KEY]);

        // Its collection_products rows go with it (ON DELETE CASCADE); no
        // product row is ever touched.
        $del = $db->prepare('DELETE FROM collections WHERE slug = :slug');
        $del->execute(['slug' => self::TEST_COLLECTION_SLUG]);
        $this->collectionId = null;

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
     * @param array<string, mixed> $settings
     */
    private function configure(string $sectionKey, array $settings): void
    {
        (new ItemGalleryRepository())->upsertSection(self::TEST_KEY, $sectionKey, $settings + [
            'source_type' => PortfolioModule::GALLERY_SOURCE,
            'portfolio_scope' => ItemGalleryContent::SCOPE_ALL,
            'background' => 'default',
            'is_active' => true,
        ]);

        ItemGalleryContent::clearCache();
    }

    private function renderBlock(int $pageSectionId): string
    {
        ItemGalleryContent::clearCache();
        PortfolioGalleryContent::clearCache();

        $row = $this->sections->findById($pageSectionId);
        $this->assertNotNull($row);

        ob_start();
        SectionRegistry::render($row);

        return (string) ob_get_clean();
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

    /**
     * A throwaway collection holding the first few products the shop already
     * has. Returns null when the shop has no active products at all, so the
     * collection-source tests can skip instead of asserting on nothing.
     */
    private function collectionWithProducts(): ?int
    {
        if ($this->collectionId !== null) {
            return $this->collectionId;
        }

        $products = (new ProductRepository())->findAllActive();
        if ($products === []) {
            return null;
        }

        $repository = new CollectionRepository();
        $id = $repository->create([
            'name' => 'Fase 4 testcollectie',
            'name_en' => null,
            'slug' => self::TEST_COLLECTION_SLUG,
            'description' => null,
            'description_en' => null,
            'image_path' => null,
            'is_active' => true,
        ]);

        $repository->setCollectionProducts($id, array_map(
            static fn (array $product): int => (int) $product['id'],
            array_slice($products, 0, 3)
        ));

        return $this->collectionId = $id;
    }

    private function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    // -------------------------------------------------------- capabilities

    public function testTheGalleryBlockIsAddableAndRepeatableAnywhere(): void
    {
        $page = $this->testPage();

        $this->assertTrue(SectionRegistry::isManuallyAddable('item_gallery'));
        $this->assertTrue(SectionRegistry::allowMultiple('item_gallery'));
        $this->assertNull(SectionRegistry::maxInstances('item_gallery'), 'a repeatable block has no cap');
        $this->assertTrue(SectionRegistry::isDeletable('item_gallery'));
        $this->assertFalse(SectionRegistry::isFixed('item_gallery'));
        $this->assertArrayHasKey('item_gallery', SectionRegistry::availableForPage($page, $this->sections));

        $this->addBlock('item_gallery');
        $this->addBlock('item_gallery');

        $this->assertArrayHasKey(
            'item_gallery',
            SectionRegistry::availableForPage($this->testPage(), $this->sections),
            'a second instance must not remove the type from "+ Sectie toevoegen"'
        );
        $this->assertCount(2, $this->sections->findForPage($this->pageId));
    }

    public function testNoPortfolioSpecificBlockOrPageSectionIsLeftBehind(): void
    {
        foreach (['portfolio_gallery', 'portfolio_teaser'] as $retired) {
            $this->assertFalse(
                SectionRegistry::exists($retired),
                "\"{$retired}\" was folded into item_gallery and must be gone from the registry"
            );
        }

        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root . '/partials/section-portfolio-gallery.php');
        $this->assertFileDoesNotExist($root . '/partials/section-portfolio-teaser.php');
        $this->assertFileDoesNotExist(
            $root . '/api/admin/update-gallery.php',
            'the page-level "hide the whole portfolio section" endpoint is the block\'s is_active now'
        );

        // The section-level visibility toggle is gone from the Portfolio
        // screen: that screen manages the catalogue, not where it is shown.
        $this->assertStringNotContainsString('update-gallery.php', $this->sourceOf('admin/portfolio.php'));
        $this->assertStringNotContainsString('portfolio:gallery', $this->sourceOf('admin/portfolio.php'));

        // ... and the catalogue table no longer pretends to be a page section.
        $catalogue = (new PortfolioGalleryRepository())->findCatalogue();
        $this->assertNotNull($catalogue, 'expected the portfolio catalogue row — run phinx migrate');
        foreach (['page_slug', 'section_key', 'is_active'] as $retiredColumn) {
            $this->assertArrayNotHasKey($retiredColumn, $catalogue);
        }

        // The lightbox markup moved out of the page template into the block
        // that actually enables it.
        $this->assertStringNotContainsString('class="lightbox"', $this->sourceOf('portfolio.php'));
    }

    // ------------------------------------------------------------- sources

    public function testTheSourceModelIsAnExplicitClosedList(): void
    {
        // Two sources, and each one owned by whoever owns the content behind
        // it: portfolio items are the Portfolio's
        // (App\Module\PortfolioModule::itemGallerySources()), a collection of
        // products is the Shop's. Still a closed list, still not a query builder.
        $this->assertSame(
            ['portfolio', 'collection'],
            array_keys(ItemGallerySources::available()),
            'this block ships exactly two sources; a third is one entry in its owner, not a query builder'
        );

        $this->assertSame('portfolio', ItemGallerySources::moduleOwnerOf(PortfolioModule::GALLERY_SOURCE));
        $this->assertSame('shop', ItemGallerySources::moduleOwnerOf(ShopModule::GALLERY_SOURCE_COLLECTION));

        $this->assertTrue(ItemGalleryContent::isSource(PortfolioModule::GALLERY_SOURCE));
        $this->assertTrue(ItemGalleryContent::isSource(ShopModule::GALLERY_SOURCE_COLLECTION));
        $this->assertFalse(ItemGalleryContent::isSource('products'));
        $this->assertFalse(ItemGalleryContent::isSource('__nope__'));
        $this->assertFalse(ItemGalleryContent::isPortfolioScope('__nope__'));
        $this->assertFalse(ItemGalleryContent::isBackground('__nope__'));
    }

    public function testThePortfolioSourceYieldsTheCatalogueItems(): void
    {
        [$blockId, $sectionKey] = $this->addBlock('item_gallery');
        $this->configure($sectionKey, ['source_type' => PortfolioModule::GALLERY_SOURCE]);

        $expected = PortfolioGalleryContent::catalogueItems(false);
        if ($expected === []) {
            $this->markTestSkipped('no visible portfolio items in this database');
        }

        $content = ItemGalleryContent::forSection(self::TEST_KEY, $sectionKey);
        $this->assertSame(ItemGalleryContent::STATE_ACTIVE, $content['state']);
        $this->assertSame(
            array_column($expected, 'title_nl'),
            array_column($content['items'], 'title_nl'),
            'the portfolio source must yield the catalogue items, in the catalogue order'
        );

        $html = $this->renderBlock($blockId);
        $this->assertStringContainsString('gallery-grid', $html);
        $this->assertStringContainsString((string) $expected[0]['title_nl'], $html);
    }

    public function testTheFeaturedScopeYieldsOnlyTheHomepageSelection(): void
    {
        [, $sectionKey] = $this->addBlock('item_gallery');
        $this->configure($sectionKey, ['portfolio_scope' => ItemGalleryContent::SCOPE_FEATURED]);

        $all = PortfolioGalleryContent::catalogueItems(false);
        $featured = PortfolioGalleryContent::catalogueItems(true);
        if ($featured === []) {
            $this->markTestSkipped('no portfolio items are flagged "Toon op homepage" in this database');
        }

        $content = ItemGalleryContent::forSection(self::TEST_KEY, $sectionKey);

        $this->assertSame(array_column($featured, 'title_nl'), array_column($content['items'], 'title_nl'));
        $this->assertLessThanOrEqual(count($all), count($content['items']));
    }

    public function testTheCollectionSourceYieldsThatCollectionsProducts(): void
    {
        $collectionId = $this->collectionWithProducts();
        if ($collectionId === null) {
            $this->markTestSkipped('no active products in this database');
        }

        [$blockId, $sectionKey] = $this->addBlock('item_gallery');
        $this->configure($sectionKey, [
            'source_type' => ShopModule::GALLERY_SOURCE_COLLECTION,
            'collection_id' => $collectionId,
        ]);

        $expected = (new ProductRepository())->findAllActive($collectionId);
        $this->assertNotSame([], $expected, 'the throwaway collection should hold products');

        $content = ItemGalleryContent::forSection(self::TEST_KEY, $sectionKey);

        $this->assertSame(
            array_map(static fn (array $p): string => (string) $p['name'], $expected),
            array_column($content['items'], 'title_nl'),
            'the collection source must yield that collection\'s active products, in its own order'
        );

        // Every product card links to the product\'s one canonical page ...
        foreach ($content['items'] as $index => $item) {
            $this->assertSame('/product.php?id=' . (int) $expected[$index]['id'], $item['url']);
            $this->assertTrue($item['is_detail_link']);
        }

        // ... and a collection has no taxonomy, so no filter bar is drawn
        // even with the setting on.
        $this->configure($sectionKey, [
            'source_type' => ShopModule::GALLERY_SOURCE_COLLECTION,
            'collection_id' => $collectionId,
            'show_filter_bar' => true,
        ]);
        $html = $this->renderBlock($blockId);

        $this->assertStringContainsString('/product.php?id=', $html);
        $this->assertStringNotContainsString('filter-bar', $html);
    }

    public function testAnUnpickedOrUnpublishedCollectionYieldsNothingInsteadOfEverything(): void
    {
        [, $sectionKey] = $this->addBlock('item_gallery');

        // Source set to "collection" but no collection chosen: an empty
        // block, never a fallback to some other source's items.
        $this->configure($sectionKey, ['source_type' => ShopModule::GALLERY_SOURCE_COLLECTION]);
        $this->assertSame([], ItemGalleryContent::forSection(self::TEST_KEY, $sectionKey)['items']);

        $collectionId = $this->collectionWithProducts();
        if ($collectionId === null) {
            $this->markTestSkipped('no active products in this database');
        }

        $repository = new CollectionRepository();
        $collection = $repository->findById($collectionId);
        $repository->update($collectionId, [
            'name' => (string) $collection['name'],
            'name_en' => $collection['name_en'],
            'slug' => (string) $collection['slug'],
            'description' => $collection['description'],
            'description_en' => $collection['description_en'],
            'is_active' => false,
        ]);

        $this->configure($sectionKey, [
            'source_type' => ShopModule::GALLERY_SOURCE_COLLECTION,
            'collection_id' => $collectionId,
        ]);

        $this->assertSame(
            [],
            ItemGalleryContent::forSection(self::TEST_KEY, $sectionKey)['items'],
            'an unpublished collection must not leak its products through a gallery block'
        );
    }

    public function testAnInvalidSourceIsRefusedInsteadOfExecuted(): void
    {
        // The save endpoint validates against the closed list BEFORE writing.
        $endpoint = $this->sourceOf('api/admin/update-item-gallery.php');
        $this->assertStringContainsString('ItemGalleryContent::isSource(', $endpoint);
        $this->assertStringContainsString('ItemGalleryContent::isPortfolioScope(', $endpoint);
        $this->assertLessThan(
            strpos($endpoint, '->upsertSection('),
            strpos($endpoint, 'ItemGalleryContent::isSource('),
            'the source must be validated before it is stored'
        );

        // And a value that got into the database anyway (a hand-edited row)
        // degrades to the documented default instead of being used to reach
        // anything: no exception, no items from another source.
        [$blockId, $sectionKey] = $this->addBlock('item_gallery');
        $galleryId = (int) (new ItemGalleryRepository())->findBySlugAndKey(self::TEST_KEY, $sectionKey)['id'];

        $stmt = Database::connection()->prepare(
            'UPDATE item_galleries SET source_type = :source, portfolio_scope = :scope, background = :bg WHERE id = :id'
        );
        $stmt->execute([
            'source' => 'products; DROP TABLE pages',
            'scope' => '__nope__',
            'bg' => '__nope__',
            'id' => $galleryId,
        ]);

        $content = ItemGalleryContent::forSection(self::TEST_KEY, $sectionKey);

        // The first source an enabled module offers: portfolio items, here.
        $this->assertSame(PortfolioModule::GALLERY_SOURCE, $content['source_type']);
        $this->assertSame(ItemGalleryContent::SCOPE_ALL, $content['portfolio_scope']);
        $this->assertSame('default', $content['background']);
        $this->assertIsString($this->renderBlock($blockId));
    }

    // -------------------------------------------------- display settings

    public function testTheFilterBarFollowsTheBlockSetting(): void
    {
        if (PortfolioGalleryContent::catalogueItems(false) === []) {
            $this->markTestSkipped('no visible portfolio items in this database');
        }

        [$blockId, $sectionKey] = $this->addBlock('item_gallery');

        $this->configure($sectionKey, ['show_filter_bar' => true]);
        $this->assertStringContainsString('filter-bar', $this->renderBlock($blockId));

        $this->configure($sectionKey, ['show_filter_bar' => false]);
        $this->assertStringNotContainsString('filter-bar', $this->renderBlock($blockId));
    }

    public function testTheLightboxFollowsTheBlockSetting(): void
    {
        if (PortfolioGalleryContent::catalogueItems(false) === []) {
            $this->markTestSkipped('no visible portfolio items in this database');
        }

        [$blockId, $sectionKey] = $this->addBlock('item_gallery');

        $this->configure($sectionKey, ['enable_lightbox' => true]);
        $on = $this->renderBlock($blockId);
        $this->assertStringContainsString('data-gallery-lightbox', $on);
        $this->assertStringContainsString('data-item-lightbox', $on, 'the shared overlay comes with the block that enables it');
        $this->assertStringContainsString('data-lightbox-item', $on);

        $this->configure($sectionKey, ['enable_lightbox' => false]);
        $off = $this->renderBlock($blockId);
        $this->assertStringNotContainsString('data-gallery-lightbox', $off);
        $this->assertStringNotContainsString('data-item-lightbox', $off);
        $this->assertStringNotContainsString('data-lightbox-item', $off);
    }

    public function testACardWithAFallbackLinkNavigatesInsteadOfZooming(): void
    {
        if (PortfolioGalleryContent::catalogueItems(false) === []) {
            $this->markTestSkipped('no visible portfolio items in this database');
        }

        [$blockId, $sectionKey] = $this->addBlock('item_gallery');

        $this->configure($sectionKey, ['enable_lightbox' => true, 'fallback_link_url' => '/portfolio.php']);
        $html = $this->renderBlock($blockId);

        $this->assertStringContainsString('href="/portfolio.php"', $html);
        $this->assertStringNotContainsString(
            'data-lightbox-item',
            $html,
            'a card that is a real link must navigate, never zoom'
        );
    }

    public function testTheMaximumItemCountIsRespected(): void
    {
        $available = PortfolioGalleryContent::catalogueItems(false);
        if (count($available) < 2) {
            $this->markTestSkipped('needs at least two visible portfolio items');
        }

        [, $sectionKey] = $this->addBlock('item_gallery');
        $this->configure($sectionKey, ['max_items' => 2]);

        $this->assertCount(2, ItemGalleryContent::forSection(self::TEST_KEY, $sectionKey)['items']);
    }

    public function testTwoInstancesOnOnePageKeepIndependentSettings(): void
    {
        if (PortfolioGalleryContent::catalogueItems(false) === []) {
            $this->markTestSkipped('no visible portfolio items in this database');
        }

        [$firstId, $firstKey] = $this->addBlock('item_gallery');
        [$secondId, $secondKey] = $this->addBlock('item_gallery');

        $this->configure($firstKey, [
            'title_nl' => 'Eerste galerij',
            'show_filter_bar' => true,
            'enable_lightbox' => true,
            'background' => 'default',
        ]);
        $this->configure($secondKey, [
            'title_nl' => 'Tweede galerij',
            'show_filter_bar' => false,
            'enable_lightbox' => false,
            'background' => 'soft',
            'max_items' => 1,
        ]);

        $first = ItemGalleryContent::forSection(self::TEST_KEY, $firstKey);
        $second = ItemGalleryContent::forSection(self::TEST_KEY, $secondKey);

        $this->assertSame('Eerste galerij', $first['title_nl']);
        $this->assertSame('Tweede galerij', $second['title_nl']);
        $this->assertTrue($first['show_filter_bar']);
        $this->assertFalse($second['show_filter_bar']);
        $this->assertTrue($first['enable_lightbox']);
        $this->assertFalse($second['enable_lightbox']);
        $this->assertSame('default', $first['background']);
        $this->assertSame('soft', $second['background']);
        $this->assertCount(1, $second['items']);

        $firstHtml = $this->renderBlock($firstId);
        $secondHtml = $this->renderBlock($secondId);

        $this->assertStringContainsString('filter-bar', $firstHtml);
        $this->assertStringNotContainsString('filter-bar', $secondHtml);
        $this->assertStringNotContainsString('bg-soft', $firstHtml);
        $this->assertStringContainsString('bg-soft', $secondHtml);

        // Each block's own editor URL, so the CMS never edits the wrong one.
        $firstRow = $this->sections->findById($firstId);
        $secondRow = $this->sections->findById($secondId);
        $this->assertNotSame(SectionRegistry::editUrl($firstRow), SectionRegistry::editUrl($secondRow));
        $this->assertStringContainsString('/admin/item-gallery.php?section=', (string) SectionRegistry::editUrl($firstRow));
    }

    public function testAnEmptySourceLeavesNoGapOnThePage(): void
    {
        [$blockId, $sectionKey] = $this->addBlock('item_gallery');
        $this->configure($sectionKey, [
            'source_type' => ShopModule::GALLERY_SOURCE_COLLECTION,
            'title_nl' => 'Kop zonder items',
        ]);

        $this->assertSame('', $this->renderBlock($blockId), 'a block with no items must render nothing at all');
    }

    public function testHidingTheBlockHidesTheSection(): void
    {
        if (PortfolioGalleryContent::catalogueItems(false) === []) {
            $this->markTestSkipped('no visible portfolio items in this database');
        }

        [$blockId, $sectionKey] = $this->addBlock('item_gallery');

        $this->configure($sectionKey, ['is_active' => false]);
        $this->assertSame(
            ItemGalleryContent::STATE_HIDDEN,
            ItemGalleryContent::forSection(self::TEST_KEY, $sectionKey)['state']
        );
        $this->assertSame('', $this->renderBlock($blockId));
    }
}
