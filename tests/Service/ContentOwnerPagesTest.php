<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\ContentOwners\ContentOwners;
use App\Service\ContentOwners\ContentPages;
use App\Service\CtaBandContent;
use App\Service\Media\MediaService;
use App\Service\Media\Usage\ContentBlockMediaUsage;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PortfolioContentOwner;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\ProductContentOwner;
use App\Service\ProductDeletionService;
use App\Service\RichTextContent;
use App\Service\Routing\RequestLanguage;
use App\Service\SectionRegistry;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\PageFixture;
use Tests\Support\ShopStockFixture;

/**
 * Product & Portfolio Content Pages 1.0: a product and a project carry
 * content blocks through the ONE block engine, on a content page of their own
 * (App\Service\ContentOwners\ContentPages).
 *
 *   - ownership: the content page is made with the first block, once, keyed
 *     "<kind>_<id>", linked through real foreign keys; two owners never share
 *     one, and a product and a project with the same id do not either;
 *   - isolation from pages: no listing of pages, no public lookup, no page
 *     editor endpoint ever sees a content page;
 *   - capabilities: the Paginakop is a page's own and is not offered on a
 *     product or project; Projectinformatie is offered on a project only;
 *   - rendering: the blocks render in their order, in the language of the
 *     request with the default language as fallback, links resolved per
 *     render;
 *   - deleting: the blocks, their words and the content page go with the
 *     owner, through the owner's own delete; the database refuses the other
 *     order; a library picture stays;
 *   - media usage: a picture on a product's block is reported where it is
 *     used, under the owner's name, and cannot be deleted from the library.
 *
 * Against the test database, everything marked zz- and removed in tearDown().
 */
final class ContentOwnerPagesTest extends TestCase
{
    private ShopStockFixture $shop;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $mediaIds = [];

    /** @var list<int> */
    private array $pageIds = [];

    protected function setUp(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        ContentOwners::reset();
        $this->shop = new ShopStockFixture();
        $this->clearCaches();
    }

    protected function tearDown(): void
    {
        foreach ($this->productIds as $productId) {
            ContentPages::deleteFor(ProductContentOwner::KIND, $productId);
        }
        $this->shop->cleanUp();

        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $itemId) {
            ContentPages::deleteFor(PortfolioContentOwner::KIND, $itemId);
            $gallery->deleteItem($itemId);
        }

        $pages = new PageRepository();
        foreach ($this->pageIds as $pageId) {
            if ($pages->findById($pageId) !== null) {
                $pages->delete($pageId);
            }
        }

        foreach ($this->mediaIds as $mediaId) {
            Database::connection()->prepare('DELETE FROM media WHERE id = :id')->execute(['id' => $mediaId]);
        }

        RequestLanguage::reset();
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        $this->clearCaches();
    }

    // ------------------------------------------------------------- ownership

    public function testAProductHasNoContentPageUntilItsFirstBlock(): void
    {
        $productId = $this->product('ZZ Plank');

        $this->assertNull(ContentPages::pageFor(ProductContentOwner::KIND, $productId));

        $page = ContentPages::ensure(ProductContentOwner::KIND, $productId);

        $this->assertSame('product_' . $productId, $page['content_key']);
        $this->assertSame('product', $page['owner_type']);
        $this->assertNull($page['slug'], 'a content page has no address');
        $this->assertSame('draft', $page['status']);
        $this->assertSame((int) $page['id'], (int) ContentPages::ensure(ProductContentOwner::KIND, $productId)['id'], 'made once');
        $this->assertSame(['owner' => ContentOwners::get('product'), 'id' => $productId], ContentPages::ownerOf($page));
    }

    public function testEveryOwnerHasItsOwnContentPage(): void
    {
        $first = $this->product('ZZ Plank');
        $second = $this->product('ZZ Schaal');
        $project = $this->project('ZZ Tafel');

        $pageA = ContentPages::ensure(ProductContentOwner::KIND, $first);
        $pageB = ContentPages::ensure(ProductContentOwner::KIND, $second);
        $pageC = ContentPages::ensure(PortfolioContentOwner::KIND, $project);

        $this->assertCount(3, array_unique([(int) $pageA['id'], (int) $pageB['id'], (int) $pageC['id']]));
        $this->assertSame('portfolio_project_' . $project, $pageC['content_key']);

        $this->addBlock($pageA, 'rich_text', ['body' => '<p>ZZ alleen A</p>']);

        $this->assertCount(1, (new PageSectionRepository())->findForPage((int) $pageA['id']));
        $this->assertSame([], (new PageSectionRepository())->findForPage((int) $pageB['id']), 'one owner\'s blocks never show on another');
        $this->assertSame([], (new PageSectionRepository())->findForPage((int) $pageC['id']));
    }

    public function testAnOwnerOfAModuleThatIsOffGetsNoNewContentPage(): void
    {
        $productId = $this->product('ZZ Plank');
        ModuleRegistry::overrideForTests(['shop' => false, 'portfolio' => true, 'blog' => true]);

        $this->expectException(\InvalidArgumentException::class);
        ContentPages::ensure(ProductContentOwner::KIND, $productId);
    }

    public function testAnUnknownOwnerOrIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContentPages::ensure(ProductContentOwner::KIND, 999999999);
    }

    public function testNoListingOfPagesAndNoPublicLookupSeesAContentPage(): void
    {
        $page = ContentPages::ensure(ProductContentOwner::KIND, $this->product('ZZ Plank'));
        $id = (int) $page['id'];
        $pages = new PageRepository();

        // Even while it were published, which it never is.
        Database::connection()->prepare("UPDATE pages SET status = 'published' WHERE id = :id")->execute(['id' => $id]);

        $ids = static fn (array $rows): array => array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $this->assertNotContains($id, $ids($pages->findAllForAdmin()));
        $this->assertNotContains($id, $ids($pages->findStructure()));
        $this->assertNotContains($id, $ids($pages->findAllPublished()));
        $this->assertNull($pages->findByIdPublished($id));
        $this->assertSame([], $pages->findPublishedByIds([$id]));

        // What the block editors use does find it.
        $this->assertSame($id, (int) $pages->findById($id)['id']);
        $this->assertSame($id, (int) $pages->findByContentKey((string) $page['content_key'])['id']);
    }

    public function testTheCmsNamesAContentPageByItsOwner(): void
    {
        $productId = $this->product('ZZ Eiken plank');
        $page = ContentPages::ensure(ProductContentOwner::KIND, $productId);

        $this->assertSame('Product: ZZ Eiken plank', PageLocalization::name((int) $page['id']));

        $project = $this->project('ZZ Walnoot kast');
        $projectPage = ContentPages::ensure(PortfolioContentOwner::KIND, $project);
        $this->assertSame('Project: ZZ Walnoot kast', PageLocalization::name((int) $projectPage['id']));
    }

    // ---------------------------------------------------------- capabilities

    public function testWhichBlocksAreOfferedDependsOnWhoseListItIs(): void
    {
        $repository = new PageSectionRepository();
        $product = array_keys(SectionRegistry::availableDefinitionsForPage(ContentPages::placeholder('product', 1), $repository));
        $project = array_keys(SectionRegistry::availableDefinitionsForPage(ContentPages::placeholder('portfolio_project', 1), $repository));
        $page = array_keys(SectionRegistry::availableDefinitionsForPage(['id' => 0, 'content_key' => 'zz-gewone-pagina'], $repository));

        foreach (['rich_text', 'text_image_split', 'detail_section', 'card_carousel', 'media_banner', 'faq', 'cta_band', 'item_gallery', 'featured_product'] as $type) {
            $this->assertContains($type, $product, $type . ' is ordinary content');
            $this->assertContains($type, $project, $type . ' is ordinary content');
        }

        $this->assertNotContains('page_hero', $product, 'a page\'s own head');
        $this->assertNotContains('page_hero', $project);
        $this->assertContains('page_hero', $page);

        // Portfolio 3.0: Projectinformatie is gone; Projectafbeeldingen is a
        // fixed block, placed with the project, never offered by hand.
        foreach ([$project, $product, $page] as $offered) {
            $this->assertNotContains('project_info', $offered);
            $this->assertNotContains('project_images', $offered);
        }
        $this->assertTrue(SectionRegistry::isAllowedOnPage('project_images', ContentPages::placeholder('portfolio_project', 1)));
        $this->assertFalse(SectionRegistry::isAllowedOnPage('project_images', ContentPages::placeholder('product', 1)), 'there are no project photos on a product');
        $this->assertFalse(SectionRegistry::isAllowedOnPage('project_images', ['id' => 0, 'content_key' => 'zz-gewone-pagina']));

        $this->assertNotContains('homepage_hero', $product);
        $this->assertNotContains('quicknav', $product, 'a fixed block is never added by hand');
    }

    // ------------------------------------------------------------- rendering

    public function testTheBlocksRenderInTheirOrderInTheLanguageOfTheRequest(): void
    {
        $page = ContentPages::ensure(ProductContentOwner::KIND, $this->product('ZZ Plank'));
        $first = $this->addBlock($page, 'rich_text', ['body' => '<p>ZZ eerste blok</p>']);
        $second = $this->addBlock($page, 'rich_text', ['body' => '<p>ZZ tweede blok</p>']);
        BlockLocalization::save('rich_text_sections', $this->sectionIdOf($first), 'en', [RichTextContent::BODY => '<p>ZZ first block</p>']);
        $this->clearCaches();

        $html = $this->render((string) $page['content_key']);
        $this->assertLessThan(strpos($html, 'ZZ tweede blok'), strpos($html, 'ZZ eerste blok'));

        (new PageSectionRepository())->reorder((int) $page['id'], [$second, $first]);
        $this->clearCaches();
        $html = $this->render((string) $page['content_key']);
        $this->assertLessThan(strpos($html, 'ZZ eerste blok'), strpos($html, 'ZZ tweede blok'), 'reordered');

        RequestLanguage::set('en', true);
        $this->clearCaches();
        $html = $this->render((string) $page['content_key']);
        $this->assertStringContainsString('ZZ first block', $html);
        $this->assertStringContainsString('ZZ tweede blok', $html, 'no English words: the default language\'s');
        $this->assertStringNotContainsString('ZZ eerste blok', $html);
    }

    public function testAHiddenBlockDoesNotRender(): void
    {
        $page = ContentPages::ensure(PortfolioContentOwner::KIND, $this->project('ZZ Tafel'));
        $id = $this->addBlock($page, 'rich_text', ['body' => '<p>ZZ verborgen</p>']);
        (new PageSectionRepository())->setActive($id, false);
        $this->clearCaches();

        $this->assertStringNotContainsString('ZZ verborgen', $this->render((string) $page['content_key']));
    }

    public function testAButtonOnAProductBlockUsesTheDestinationPicker(): void
    {
        $target = PageFixture::create(['content_key' => 'zz-doelpagina-' . bin2hex(random_bytes(3)), 'slug' => 'zz-doel-' . bin2hex(random_bytes(3)), 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Doel');
        $this->pageIds[] = $target;
        $page = ContentPages::ensure(ProductContentOwner::KIND, $this->product('ZZ Plank'));
        $sectionId = $this->addBlock($page, 'cta_band', ['title' => 'ZZ Oproep', 'primary_label' => 'ZZ Naar doel']);
        $rowId = $this->sectionIdOf($sectionId);
        Database::connection()->prepare("UPDATE cta_bands SET primary_link_type = 'page', primary_link_target_id = :target, primary_url = '' WHERE id = :id")
            ->execute(['target' => $target, 'id' => $rowId]);
        $this->clearCaches();

        $html = $this->render((string) $page['content_key']);
        $path = PageContent::publicUrl((new PageRepository())->findById($target));

        $this->assertStringContainsString('ZZ Oproep', $html);
        $this->assertStringContainsString('href="' . $path . '"', $html);
    }

    // -------------------------------------------------------------- deleting

    public function testDeletingAProductTakesItsBlocksWordsAndContentPageAlong(): void
    {
        $productId = $this->product('ZZ Plank');
        $page = ContentPages::ensure(ProductContentOwner::KIND, $productId);
        $section = $this->addBlock($page, 'rich_text', ['body' => '<p>ZZ weg</p>']);
        $rowId = $this->sectionIdOf($section);

        $this->assertTrue((new ProductDeletionService())->delete($productId));

        $db = Database::connection();
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM pages WHERE id = ' . (int) $page['id'])->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM page_sections WHERE page_id = ' . (int) $page['id'])->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM rich_text_sections WHERE id = ' . $rowId)->fetchColumn());
        $this->assertSame(0, (int) $db->query("SELECT COUNT(*) FROM block_translations WHERE owner_table = 'rich_text_sections' AND owner_id = " . $rowId)->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM product_content_pages WHERE product_id = ' . $productId)->fetchColumn());
    }

    public function testTheDatabaseRefusesToDropAnOwnerOrItsPageWhileTheyAreLinked(): void
    {
        $productId = $this->product('ZZ Plank');
        $page = ContentPages::ensure(ProductContentOwner::KIND, $productId);
        $db = Database::connection();

        foreach (['DELETE FROM products WHERE id = ' . $productId, 'DELETE FROM pages WHERE id = ' . (int) $page['id']] as $sql) {
            try {
                $db->exec($sql);
                $this->fail('refused: ' . $sql);
            } catch (\PDOException $e) {
                $this->assertSame('23000', $e->getCode(), $sql);
            }
        }
    }

    public function testDeletingAProjectsContentPageLeavesTheProject(): void
    {
        $project = $this->project('ZZ Tafel');
        $page = ContentPages::ensure(PortfolioContentOwner::KIND, $project);
        $this->addBlock($page, 'rich_text', []);
        \App\Service\ProjectImagesPlacement::ensure($project);

        ContentPages::deleteFor(PortfolioContentOwner::KIND, $project);

        // The fixed Projectafbeeldingen row goes with the list, as every row does.
        $this->assertNull((new PageSectionRepository())->findBySectionTypeAndId('project_images', $project));

        $this->assertNull(ContentPages::pageFor(PortfolioContentOwner::KIND, $project));
        $this->assertNotNull((new PortfolioGalleryRepository())->findItemById($project));
    }

    public function testAContentPageIsNeverDeletedOrSavedAsAPage(): void
    {
        foreach (['api/admin/update-page.php', 'api/admin/delete-page.php', 'admin/page-preview.php'] as $file) {
            $this->assertStringContainsString(
                'ContentPages::isContentPage($page)',
                (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file),
                $file . ' treats a content page as no page'
            );
        }
    }

    // ----------------------------------------------------------- media usage

    public function testAPictureOnAProductBlockIsInUseUnderTheProductsName(): void
    {
        $mediaId = $this->media();
        $page = ContentPages::ensure(ProductContentOwner::KIND, $this->product('ZZ Eiken plank'));
        $section = $this->addBlock($page, 'cta_band', ['title' => 'ZZ Met foto']);
        Database::connection()->prepare('UPDATE cta_bands SET background_media_id = :media WHERE id = :id')
            ->execute(['media' => $mediaId, 'id' => $this->sectionIdOf($section)]);

        $usages = (new ContentBlockMediaUsage())->usagesFor([$mediaId])[$mediaId] ?? [];

        $this->assertCount(1, $usages);
        $this->assertStringContainsString('Product: ZZ Eiken plank', $usages[0]->label);
        $this->assertStringContainsString('/admin/cta-band.php?section=' . rawurlencode('product_'), (string) $usages[0]->editUrl);

        $result = (new MediaService())->delete($mediaId);
        $this->assertFalse($result['deleted']);
        $this->assertSame('in_use', $result['reason']);
    }

    public function testAPictureOnAProjectBlockIsInUseToo(): void
    {
        $mediaId = $this->media();
        $page = ContentPages::ensure(PortfolioContentOwner::KIND, $this->project('ZZ Walnoot kast'));
        $section = $this->addBlock($page, 'cta_band', ['title' => 'ZZ Project oproep']);
        Database::connection()->prepare('UPDATE cta_bands SET background_media_id = :media WHERE id = :id')
            ->execute(['media' => $mediaId, 'id' => $this->sectionIdOf($section)]);

        $usages = (new ContentBlockMediaUsage())->usagesFor([$mediaId])[$mediaId] ?? [];

        $this->assertCount(1, $usages);
        $this->assertStringContainsString('Project: ZZ Walnoot kast', $usages[0]->label);
    }

    // --------------------------------------------------------------- helpers

    private function product(string $name): int
    {
        $id = $this->shop->product($name);
        $this->productIds[] = $id;
        ShopLocalization::clearCache();

        return $id;
    }

    private function project(string $title): int
    {
        $repository = new PortfolioGalleryRepository();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-content-owner-' . bin2hex(random_bytes(4)) . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::TITLE => $title,
        ]);
        $repository->setItemProjectPage($id, true, 'zz-content-owner-' . bin2hex(random_bytes(4)));
        PortfolioGalleryContent::clearCache();

        return $id;
    }

    /**
     * One block on the page, the way api/admin/add-page-section.php adds it,
     * with its words in the default language.
     *
     * @param array<string, mixed> $page
     * @param array<string, string> $words
     *
     * @return int the page_sections id
     */
    private function addBlock(array $page, string $type, array $words): int
    {
        [$sectionId, $sectionKey] = SectionRegistry::create($type, (string) $page['content_key']);
        $id = (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], $type, $sectionKey, $sectionId);

        $table = BlockDefinitions::get($type)?->contentTable();
        if ($words !== [] && $table !== null) {
            BlockLocalization::save($table, $sectionId, PageLocalization::defaultLanguage(), $words);
        }
        $this->clearCaches();

        return $id;
    }

    private function sectionIdOf(int $pageSectionId): int
    {
        return (int) (new PageSectionRepository())->findById($pageSectionId)['section_id'];
    }

    private function media(): int
    {
        $db = Database::connection();
        $db->prepare(
            "INSERT INTO media (path, thumbnail_path, original_filename, display_name, mime_type, width, height, file_size, alt_text, created_at, updated_at)
             VALUES (:path, '', 'zz.jpg', 'ZZ content owner', 'image/jpeg', 10, 10, 100, '', NOW(), NOW())"
        )->execute(['path' => 'assets/media/zz-content-owner-' . bin2hex(random_bytes(4)) . '.jpg']);
        $id = (int) $db->lastInsertId();
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    private function render(string $contentKey): string
    {
        ob_start();
        SectionRegistry::renderPage($contentKey);

        return (string) ob_get_clean();
    }

    private function clearCaches(): void
    {
        PageContent::clearCache();
        BlockLocalization::clearCache();
        CtaBandContent::clearCache();
        RichTextContent::clearCache();
        PageLocalization::clearCache();
        PortfolioGalleryContent::clearCache();
        MediaService::clearCache();
    }
}
