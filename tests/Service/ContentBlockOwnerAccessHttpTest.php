<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\SpacerRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\ContentOwners\ContentPages;
use App\Service\PageContent;
use App\Service\PortfolioContentOwner;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\ProductContentOwner;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\ShopStockFixture;

/**
 * Owner-aware block permissions (App\Service\ContentOwners\ContentBlockAccess,
 * CONTENT-BLOCKS.md "Wie mag welke blokken beheren"), over real HTTP: the
 * holder page of a product's or a project's blocks never decides who may
 * change them, the owner does.
 *
 *   - a page's blocks: pages.manage, and nobody without it;
 *   - a product's: products.manage without pages.manage, and pages.manage
 *     alone is refused;
 *   - a project's: portfolio.manage, and neither of the others;
 *   - a forged page_sections id, page id, content key or card id reaches
 *     only a list its sender may manage anyway;
 *   - the block's `owners` capability still holds: a Shop manager cannot put
 *     a Paginakop on a product;
 *   - the way back from a block editor and from every list endpoint is the
 *     owner's editor, never admin/page.php.
 */
final class ContentBlockOwnerAccessHttpTest extends TestCase
{
    private const PAGE_KEY = 'zz-owner-access-http';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private ShopStockFixture $shop;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $itemIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true', 'MODULE_PORTFOLIO_ENABLED' => 'true']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        \App\Module\ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        \App\Service\ContentOwners\ContentOwners::reset();
        $this->accounts = new AdminTestSession();
        $this->shop = new ShopStockFixture();
        $this->removePage();
    }

    protected function tearDown(): void
    {
        $this->removePage();

        foreach ($this->productIds as $id) {
            ContentPages::deleteFor(ProductContentOwner::KIND, $id);
        }
        $this->shop->cleanUp();

        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $id) {
            ContentPages::deleteFor(PortfolioContentOwner::KIND, $id);
            $gallery->deleteItem($id);
        }

        $this->accounts->forget();
        \App\Module\ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        \App\Service\ContentOwners\ContentOwners::reset();
    }

    // ------------------------------------------------------------ pages

    public function testAPagesBlocksNeedPagesManage(): void
    {
        [$pageSectionId, $key] = $this->spacerOnPage();

        [$pages, $pagesCsrf] = $this->accounts->signIn(['pages.manage']);
        $this->assertSame(200, $this->get('/admin/spacer.php?section=' . urlencode($key), $pages)['status']);
        $saved = $this->post('/api/admin/update-spacer.php', $pages, ['csrf_token' => $pagesCsrf, 'section' => $key, 'size' => 'large']);
        $this->assertSame(302, $saved['status']);
        $this->assertSame('large', $this->spacerSize($key));

        foreach ([['products.manage'], ['portfolio.manage'], ['products.manage', 'portfolio.manage']] as $permissions) {
            [$other, $otherCsrf] = $this->accounts->signIn($permissions);
            $label = implode('+', $permissions);

            $this->assertSame(403, $this->get('/admin/spacer.php?section=' . urlencode($key), $other)['status'], $label);
            $this->assertSame(403, $this->post('/api/admin/update-spacer.php', $other, ['csrf_token' => $otherCsrf, 'section' => $key, 'size' => 'small'])['status'], $label);
            $this->assertSame(403, $this->post('/api/admin/toggle-page-section.php', $other, ['csrf_token' => $otherCsrf, 'id' => (string) $pageSectionId, 'is_active' => '0'])['status'], $label);
            $this->assertSame(403, $this->post('/api/admin/delete-page-section.php', $other, ['csrf_token' => $otherCsrf, 'id' => (string) $pageSectionId])['status'], $label);
            $this->assertSame(403, $this->post('/api/admin/reorder-page-sections.php', $other, ['csrf_token' => $otherCsrf, 'page_id' => (string) $this->pageId(), 'section_ids' => (string) $pageSectionId])['status'], $label);
            $this->assertSame(403, $this->post('/api/admin/add-page-section.php', $other, ['csrf_token' => $otherCsrf, 'page_id' => (string) $this->pageId(), 'section_type' => 'spacer'])['status'], $label);
        }

        $this->assertSame('large', $this->spacerSize($key), 'nothing a refused request sent was written');
        $this->assertNotNull((new PageSectionRepository())->findById($pageSectionId));

        [$nobody, $nobodyCsrf] = $this->accounts->signIn(['orders.view']);
        $this->assertSame(403, $this->post('/api/admin/update-spacer.php', $nobody, ['csrf_token' => $nobodyCsrf, 'section' => $key, 'size' => 'small'])['status'], 'no block permission at all: step 1');
        $this->assertSame(403, $this->get('/admin/block-preview.php?type=spacer', $nobody)['status']);
    }

    // --------------------------------------------------------- products

    public function testAShopManagerManagesAProductsBlocksWithoutPagesManage(): void
    {
        $productId = $this->product('ZZ Rechten product');
        [$shop, $csrf] = $this->accounts->signIn(['products.manage']);

        $tab = $this->get('/admin/product-form.php?id=' . $productId . '&tab=inhoud', $shop);
        $this->assertSame(200, $tab['status']);
        $this->assertStringContainsString('name="content_owner" value="product"', $tab['body'], 'the Pagina-inhoud tab and its picker');
        $this->assertSame(200, $this->get('/admin/block-preview.php?type=spacer', $shop)['status'], 'the picker\'s previews');

        $added = $this->post('/api/admin/add-page-section.php', $shop, [
            'csrf_token' => $csrf, 'page_id' => '0', 'content_owner' => 'product', 'content_owner_id' => (string) $productId, 'section_type' => 'spacer',
        ]);
        $this->assertSame(302, $added['status']);
        $page = ContentPages::pageFor(ProductContentOwner::KIND, $productId);
        $this->assertNotNull($page);
        [$pageSection] = (new PageSectionRepository())->findForPage((int) $page['id']);
        $key = (string) $page['content_key'] . ':' . (string) $pageSection['section_key'];
        $this->assertStringStartsWith('/admin/spacer.php?section=' . urlencode($key), $added['location']);

        $editor = $this->get('/admin/spacer.php?section=' . urlencode($key), $shop);
        $this->assertSame(200, $editor['status']);
        $this->assertStringContainsString('href="/admin/product-form.php?id=' . $productId . '&amp;tab=inhoud"', $editor['body'], 'back to the product, not to page.php');
        $this->assertStringNotContainsString('/admin/page.php?id=', $editor['body']);

        $this->assertSame(302, $this->post('/api/admin/update-spacer.php', $shop, ['csrf_token' => $csrf, 'section' => $key, 'size' => 'xlarge'])['status']);
        $this->assertSame('xlarge', $this->spacerSize($key));

        $hidden = $this->post('/api/admin/toggle-page-section.php', $shop, ['csrf_token' => $csrf, 'id' => (string) $pageSection['id'], 'is_active' => '0']);
        $this->assertSame(302, $hidden['status']);
        $this->assertSame('/admin/product-form.php?id=' . $productId . '&tab=inhoud#blok-' . $pageSection['id'], $hidden['location']);

        $second = $this->post('/api/admin/add-page-section.php', $shop, [
            'csrf_token' => $csrf, 'page_id' => (string) $page['id'], 'section_type' => 'spacer',
        ]);
        $this->assertSame(302, $second['status'], 'a list that exists is added to by its page id');
        $rows = (new PageSectionRepository())->findForPage((int) $page['id']);
        $this->assertCount(2, $rows);

        $reordered = $this->post('/api/admin/reorder-page-sections.php', $shop, [
            'csrf_token' => $csrf, 'page_id' => (string) $page['id'], 'section_ids' => $rows[1]['id'] . ',' . $rows[0]['id'],
        ]);
        $this->assertSame(200, $reordered['status']);
        $this->assertSame((int) $rows[1]['id'], (int) (new PageSectionRepository())->findForPage((int) $page['id'])[0]['id']);

        $deleted = $this->post('/api/admin/delete-page-section.php', $shop, ['csrf_token' => $csrf, 'id' => (string) $rows[1]['id']]);
        $this->assertSame(302, $deleted['status']);
        $this->assertSame('/admin/product-form.php?id=' . $productId . '&tab=inhoud&deleted=1', $deleted['location']);
        $this->assertCount(1, (new PageSectionRepository())->findForPage((int) $page['id']));

        // pages.manage opens no CMS page for this account.
        [$pageSectionOnPage, $pageKey] = $this->spacerOnPage();
        $this->assertSame(403, $this->get('/admin/page.php?id=' . $this->pageId(), $shop)['status']);
        $this->assertSame(403, $this->post('/api/admin/update-spacer.php', $shop, ['csrf_token' => $csrf, 'section' => $pageKey, 'size' => 'small'])['status']);
        $this->assertSame(403, $this->post('/api/admin/delete-page-section.php', $shop, ['csrf_token' => $csrf, 'id' => (string) $pageSectionOnPage])['status']);
    }

    public function testPagesManageAloneOpensNoProductsBlocks(): void
    {
        $productId = $this->product('ZZ Rechten zonder shop');
        $page = ContentPages::ensure(ProductContentOwner::KIND, $productId);
        [$pageSectionId, $key] = $this->spacer($page);
        [$pages, $csrf] = $this->accounts->signIn(['pages.manage', 'portfolio.manage']);

        $this->assertSame(403, $this->get('/admin/spacer.php?section=' . urlencode($key), $pages)['status']);
        $this->assertSame(403, $this->post('/api/admin/update-spacer.php', $pages, ['csrf_token' => $csrf, 'section' => $key, 'size' => 'small'])['status'], 'a forged content key');
        $this->assertSame(403, $this->post('/api/admin/toggle-page-section.php', $pages, ['csrf_token' => $csrf, 'id' => (string) $pageSectionId, 'is_active' => '0'])['status'], 'a forged block id');
        $this->assertSame(403, $this->post('/api/admin/delete-page-section.php', $pages, ['csrf_token' => $csrf, 'id' => (string) $pageSectionId])['status']);
        $this->assertSame(403, $this->post('/api/admin/reorder-page-sections.php', $pages, ['csrf_token' => $csrf, 'page_id' => (string) $page['id'], 'section_ids' => (string) $pageSectionId])['status'], 'a forged holder id');
        $this->assertSame(403, $this->post('/api/admin/add-page-section.php', $pages, ['csrf_token' => $csrf, 'page_id' => (string) $page['id'], 'section_type' => 'spacer'])['status'], 'the holder page by its id');
        $this->assertSame(403, $this->post('/api/admin/add-page-section.php', $pages, [
            'csrf_token' => $csrf, 'page_id' => '0', 'content_owner' => 'product', 'content_owner_id' => (string) $productId, 'section_type' => 'spacer',
        ])['status'], 'the owner by its kind');

        $this->assertNotSame('small', $this->spacerSize($key));
        $this->assertCount(1, (new PageSectionRepository())->findForPage((int) $page['id']));
        $this->assertTrue((bool) (new PageSectionRepository())->findById($pageSectionId)['is_active']);
    }

    public function testABlocksOwnerCapabilityStillHoldsForWhoMayManageTheProduct(): void
    {
        $productId = $this->product('ZZ Rechten paginakop');
        [$shop, $csrf] = $this->accounts->signIn(['products.manage']);

        foreach (['page_hero', 'project_info'] as $type) {
            $refused = $this->post('/api/admin/add-page-section.php', $shop, [
                'csrf_token' => $csrf, 'page_id' => '0', 'content_owner' => 'product', 'content_owner_id' => (string) $productId, 'section_type' => $type,
            ]);
            $this->assertSame(400, $refused['status'], "{$type}: permission is not availability");
        }

        $this->assertNull(ContentPages::pageFor(ProductContentOwner::KIND, $productId));
    }

    // --------------------------------------------------------- projects

    public function testAPortfolioManagerManagesAProjectsBlocksAndNothingElse(): void
    {
        $itemId = $this->project('ZZ Rechten project');
        $productId = $this->product('ZZ Rechten ander product');
        $productPage = ContentPages::ensure(ProductContentOwner::KIND, $productId);
        [$productBlockId, $productKey] = $this->spacer($productPage);
        [, $pageKey] = $this->spacerOnPage();
        [$portfolio, $csrf] = $this->accounts->signIn(['portfolio.manage']);

        $this->assertStringContainsString('name="content_owner" value="portfolio_project"', $this->get('/admin/portfolio-item.php?id=' . $itemId . '&tab=inhoud', $portfolio)['body']);

        $added = $this->post('/api/admin/add-page-section.php', $portfolio, [
            'csrf_token' => $csrf, 'page_id' => '0', 'content_owner' => 'portfolio_project', 'content_owner_id' => (string) $itemId, 'section_type' => 'spacer',
        ]);
        $this->assertSame(302, $added['status']);
        $page = ContentPages::pageFor(PortfolioContentOwner::KIND, $itemId);
        $this->assertNotNull($page);
        [$row] = (new PageSectionRepository())->findForPage((int) $page['id']);
        $key = (string) $page['content_key'] . ':' . (string) $row['section_key'];

        $editor = $this->get('/admin/spacer.php?section=' . urlencode($key), $portfolio);
        $this->assertSame(200, $editor['status']);
        $this->assertStringContainsString('href="/admin/portfolio-item.php?id=' . $itemId . '&amp;tab=inhoud"', $editor['body']);
        $this->assertSame(302, $this->post('/api/admin/update-spacer.php', $portfolio, ['csrf_token' => $csrf, 'section' => $key, 'size' => 'small'])['status']);
        $this->assertSame('small', $this->spacerSize($key));

        // Not a product's, not a page's.
        $this->assertSame(403, $this->post('/api/admin/update-spacer.php', $portfolio, ['csrf_token' => $csrf, 'section' => $productKey, 'size' => 'small'])['status']);
        $this->assertSame(403, $this->post('/api/admin/toggle-page-section.php', $portfolio, ['csrf_token' => $csrf, 'id' => (string) $productBlockId, 'is_active' => '0'])['status']);
        $this->assertSame(403, $this->post('/api/admin/update-spacer.php', $portfolio, ['csrf_token' => $csrf, 'section' => $pageKey, 'size' => 'small'])['status']);

        // And the project's blocks are not a Shop manager's.
        [$shop, $shopCsrf] = $this->accounts->signIn(['products.manage', 'pages.manage']);
        $this->assertSame(403, $this->get('/admin/spacer.php?section=' . urlencode($key), $shop)['status']);
        $this->assertSame(403, $this->post('/api/admin/update-spacer.php', $shop, ['csrf_token' => $shopCsrf, 'section' => $key, 'size' => 'large'])['status']);
        $this->assertSame('small', $this->spacerSize($key));
    }

    public function testAForgedCardIdReachesOnlyAListItsSenderMayManage(): void
    {
        $productId = $this->product('ZZ Rechten carrousel');
        $page = ContentPages::ensure(ProductContentOwner::KIND, $productId);
        [$sectionId, $sectionKey] = SectionRegistry::create('card_carousel', (string) $page['content_key']);
        (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], 'card_carousel', $sectionKey, $sectionId);
        $cardId = (new \App\Repository\CardCarouselRepository())->createCard($sectionId, true);

        [$pages, $csrf] = $this->accounts->signIn(['pages.manage']);
        $this->assertSame(403, $this->get('/admin/carousel-card.php?card_id=' . $cardId, $pages)['status']);
        $this->assertSame(403, $this->post('/api/admin/update-carousel-card.php', $pages, [
            'csrf_token' => $csrf, 'card_id' => (string) $cardId, 'language_code' => BlockLocalization::defaultLanguage(), 'title' => 'Vervalst',
        ])['status']);

        [$shop] = $this->accounts->signIn(['products.manage']);
        $this->assertSame(200, $this->get('/admin/carousel-card.php?card_id=' . $cardId, $shop)['status']);
    }

    // ---------------------------------------------------------- helpers

    /** @return array{0: int, 1: string} the page_sections id and the `<page>:<key>` of a spacer on the test page */
    private function spacerOnPage(): array
    {
        $page = (new PageRepository())->findByContentKey(self::PAGE_KEY);
        if ($page === null) {
            PageFixture::create(['content_key' => self::PAGE_KEY, 'slug' => self::PAGE_KEY, 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Rechten pagina');
            $page = (new PageRepository())->findByContentKey(self::PAGE_KEY);
        }

        return $this->spacer((array) $page);
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return array{0: int, 1: string}
     */
    private function spacer(array $page): array
    {
        [$sectionId, $sectionKey] = SectionRegistry::create('spacer', (string) $page['content_key']);
        $id = (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], 'spacer', $sectionKey, $sectionId);

        return [$id, (string) $page['content_key'] . ':' . $sectionKey];
    }

    private function spacerSize(string $key): ?string
    {
        [$pageSlug, $sectionKey] = explode(':', $key, 2);
        $row = (new SpacerRepository())->findBySlugAndKey($pageSlug, $sectionKey);

        return $row === null ? null : (string) $row['size'];
    }

    private function pageId(): int
    {
        return (int) ((new PageRepository())->findByContentKey(self::PAGE_KEY)['id'] ?? 0);
    }

    private function product(string $name): int
    {
        $id = $this->shop->product($name);
        $this->productIds[] = $id;

        return $id;
    }

    private function project(string $title): int
    {
        $repository = new PortfolioGalleryRepository();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-owner-access-' . bin2hex(random_bytes(4)) . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::TITLE => $title,
            PortfolioLocalization::ALT => $title,
        ]);
        PortfolioGalleryContent::clearCache();

        return $id;
    }

    /** @return array{status: int, body: string, location: string} */
    private function get(string $path, string $session): array
    {
        return self::$server->request('GET', $path, $session);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{status: int, body: string, location: string}
     */
    private function post(string $path, string $session, array $fields): array
    {
        return self::$server->request('POST', $path, $session, $fields);
    }

    private function removePage(): void
    {
        $pages = new PageRepository();
        $page = $pages->findByContentKey(self::PAGE_KEY);
        if ($page === null) {
            return;
        }

        $sections = new PageSectionRepository();
        foreach ($sections->findForPage((int) $page['id']) as $row) {
            SectionRegistry::delete($row, $sections);
        }
        $pages->delete((int) $page['id']);
        PageContent::clearCache();
    }
}
