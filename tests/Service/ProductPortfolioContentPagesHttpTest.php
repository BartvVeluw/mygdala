<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\SiteSettingRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\ContentOwners\ContentPages;
use App\Service\PageLocalization;
use App\Service\PortfolioContentOwner;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\PortfolioProjectLayout;
use App\Service\ProductContentOwner;
use App\Service\SectionRegistry;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\ShopStockFixture;
use Tests\Support\SavedRedirect;

/**
 * Product & Portfolio Content Pages 1.0 and Portfolio layout 2.0, over real
 * HTTP (Tests\Support\BuiltInServer): the product page and the project page
 * as a visitor gets them, and the admin screens and endpoints an editor uses.
 *
 * Product: without blocks exactly the page it was; with blocks, the blocks
 * after the product detail and before the related products, in their order;
 * the Shop off, a 404 whatever blocks the product has.
 *
 * Project: the layout of its fixed head, from the Portfolio default (picture
 * left unless chosen otherwise, which prints no modifier class), right, top;
 * a project that follows the default changes with it, one with its own
 * layout does not; the blocks below the head; related projects still below;
 * the Portfolio off, a 404. (Its photos as a block: ProjectImagesBlockHttpTest.)
 *
 * Admin: a content page's own id sends the editor to its owner; the first
 * block makes the content page; a block that is not offered is refused; the
 * product editor's Pagina-inhoud tab; the project's layout saved and a wrong
 * one ("free" too, since Portfolio 3.0) refused; the Portfolio default saved.
 */
final class ProductPortfolioContentPagesHttpTest extends TestCase
{
    private static ?BuiltInServer $on = null;

    private static ?BuiltInServer $off = null;

    private AdminTestSession $accounts;

    private ShopStockFixture $shop;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $itemIds = [];

    private ?string $storedDefault = null;

    public static function setUpBeforeClass(): void
    {
        self::$on = BuiltInServer::start([
            'MODULE_SHOP_ENABLED' => 'true',
            'MODULE_PORTFOLIO_ENABLED' => 'true',
        ]);
        self::$off = BuiltInServer::start([
            'MODULE_SHOP_ENABLED' => 'false',
            'MODULE_PORTFOLIO_ENABLED' => 'false',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$on?->stop();
        self::$off?->stop();
        self::$on = null;
        self::$off = null;
    }

    protected function setUp(): void
    {
        if (self::$on === null || self::$off === null || !self::$on->answers() || !self::$off->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        \App\Module\ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        $this->accounts = new AdminTestSession();
        $this->shop = new ShopStockFixture();

        $stored = Database::connection()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :key');
        $stored->execute(['key' => PortfolioProjectLayout::SETTING]);
        $value = $stored->fetchColumn();
        $this->storedDefault = $value === false ? null : (string) $value;
    }

    protected function tearDown(): void
    {
        foreach ($this->productIds as $id) {
            ContentPages::deleteFor(ProductContentOwner::KIND, $id);
        }
        $this->shop->cleanUp();

        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $id) {
            ContentPages::deleteFor(PortfolioContentOwner::KIND, $id);
            $gallery->deleteItem($id);
        }

        $db = Database::connection();
        if ($this->storedDefault === null) {
            $db->prepare('DELETE FROM site_settings WHERE setting_key = :key')->execute(['key' => PortfolioProjectLayout::SETTING]);
        } else {
            (new SiteSettingRepository())->upsertMany([PortfolioProjectLayout::SETTING => $this->storedDefault]);
        }
        SiteSettings::clearCache();

        $this->accounts->forget();
        \App\Module\ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
    }

    // --------------------------------------------------------------- product

    public function testAProductWithoutBlocksHasNoBlockMarkup(): void
    {
        $id = $this->product('ZZ Kaal product');

        $body = $this->get(self::$on, '/product.php?id=' . $id);

        $this->assertStringContainsString('data-product-detail', $body);
        $this->assertStringNotContainsString('data-reveal-group="rich_text-', $body);
        $this->assertStringNotContainsString('data-reveal-group="cta_band-', $body);
    }

    public function testAProductsBlocksFollowTheProductDetailInTheirOrder(): void
    {
        $id = $this->product('ZZ Plank met blokken');
        $page = ContentPages::ensure(ProductContentOwner::KIND, $id);
        $this->block($page, 'rich_text', ['body' => '<p>ZZ eerste productblok</p>']);
        $this->block($page, 'cta_band', ['title' => 'ZZ tweede productblok']);

        $body = $this->get(self::$on, '/product.php?id=' . $id);

        $detail = strpos($body, 'data-product-content');
        $first = strpos($body, 'ZZ eerste productblok');
        $second = strpos($body, 'ZZ tweede productblok');
        $this->assertIsInt($detail);
        $this->assertIsInt($first);
        $this->assertIsInt($second);
        $this->assertLessThan($first, $detail, 'the product detail stays on top');
        $this->assertLessThan($second, $first, 'the blocks in their order');
        $this->assertLessThan(strpos($body, '</main>'), $second);

        // The product's own head: its canonical and Product data, not a block's.
        $this->assertStringContainsString('"@type":"Product"', str_replace(' ', '', $body));
    }

    public function testWithTheShopOffAProductWithBlocksIsA404(): void
    {
        $id = $this->product('ZZ Plank uit');
        $this->block(ContentPages::ensure(ProductContentOwner::KIND, $id), 'rich_text', ['body' => '<p>ZZ niet te zien</p>']);

        $response = self::$off->request('GET', '/product.php?id=' . $id);

        $this->assertSame(404, $response['status']);
        $this->assertStringNotContainsString('ZZ niet te zien', $response['body']);
        $this->assertNotNull(ContentPages::pageFor(ProductContentOwner::KIND, $id), 'the blocks are kept');
    }

    // ------------------------------------------------------------- portfolio

    public function testAProjectWithoutSettingsKeepsThePictureOnTheLeft(): void
    {
        $this->setDefault(null);
        $slug = $this->projectSlug($this->project('ZZ Standaard'));

        $body = $this->get(self::$on, '/portfolio-detail.php?slug=' . $slug);

        $this->assertStringContainsString('<section class="project-hero" data-lightbox-group="project-' . $this->lastProject . '">', $body, 'left prints no modifier');
        $this->assertStringContainsString('data-reveal-group="project-hero"', $body);
    }

    public function testTheDefaultDecidesForEveryProjectThatFollowsIt(): void
    {
        $follows = $this->project('ZZ Volgt');
        $own = $this->project('ZZ Eigen');
        (new PortfolioGalleryRepository())->setItemProjectLayout($own, PortfolioProjectLayout::IMAGE_TOP);

        foreach ([PortfolioProjectLayout::IMAGE_RIGHT => 'project-hero--image-right', PortfolioProjectLayout::IMAGE_TOP => 'project-hero--image-top', PortfolioProjectLayout::IMAGE_LEFT => null] as $default => $class) {
            $this->setDefault($default);

            $followsBody = $this->get(self::$on, '/portfolio-detail.php?slug=' . $this->projectSlug($follows));
            if ($class === null) {
                $this->assertStringContainsString('<section class="project-hero" data-lightbox-group="project-' . $follows . '">', $followsBody, $default);
            } else {
                $this->assertStringContainsString('<section class="project-hero ' . $class . '" data-lightbox-group="project-' . $follows . '">', $followsBody, $default);
            }

            $ownBody = $this->get(self::$on, '/portfolio-detail.php?slug=' . $this->projectSlug($own));
            $this->assertStringContainsString('<section class="project-hero project-hero--image-top" data-lightbox-group="project-' . $own . '">', $ownBody, 'its own choice stays with default ' . $default);
        }
    }

    public function testAProjectsBlocksFollowItsHeadAndRelatedProjectsStayBelow(): void
    {
        $this->setDefault(null);
        $id = $this->project('ZZ Met blokken');
        $other = $this->project('ZZ Verwant');
        $gallery = new PortfolioGalleryRepository();
        $gallery->updateRelatedSettings($id, [
            'related_enabled' => true, 'related_mode' => 'manual', 'related_max' => 3, 'related_sort' => 'relevance',
            'related_fallback' => 'available', 'related_layout' => 'normal', 'related_show_text' => true,
        ]);
        $gallery->replaceRelatedItems($id, [$other]);
        $this->block(ContentPages::ensure(PortfolioContentOwner::KIND, $id), 'rich_text', ['body' => '<p>ZZ projectblok</p>']);

        $body = $this->get(self::$on, '/portfolio-detail.php?slug=' . $this->projectSlug($id));

        $head = strpos($body, 'project-hero__title');
        $block = strpos($body, 'ZZ projectblok');
        $related = strpos($body, 'related-projects');
        $this->assertIsInt($head);
        $this->assertIsInt($block);
        $this->assertIsInt($related, 'related projects still render');
        $this->assertLessThan($block, $head);
        $this->assertLessThan($related, $block);
    }

    public function testFixedLayoutsKeepTheirHeadAboveTheBlocksInEachPosition(): void
    {
        $this->setDefault(null);
        $id = $this->project('ZZ Vast');
        $this->block(ContentPages::ensure(PortfolioContentOwner::KIND, $id), 'rich_text', ['body' => '<p>ZZ vast blok</p>']);

        foreach ([PortfolioProjectLayout::IMAGE_LEFT => 'project-hero', PortfolioProjectLayout::IMAGE_RIGHT => 'project-hero project-hero--image-right', PortfolioProjectLayout::IMAGE_TOP => 'project-hero project-hero--image-top'] as $layout => $class) {
            (new PortfolioGalleryRepository())->setItemProjectLayout($id, $layout);
            $body = $this->get(self::$on, '/portfolio-detail.php?slug=' . $this->projectSlug($id));

            $this->assertStringContainsString('<section class="' . $class . '" data-lightbox-group="project-' . $id . '">', $body, $layout);
            $this->assertSame(1, substr_count($body, 'project-hero__title'), $layout);
            $this->assertLessThan(strpos($body, 'ZZ vast blok'), strpos($body, 'project-hero__title'), $layout);
        }
    }

    public function testWithThePortfolioOffAProjectWithBlocksIsA404(): void
    {
        $id = $this->project('ZZ Uit');
        $this->block(ContentPages::ensure(PortfolioContentOwner::KIND, $id), 'rich_text', ['body' => '<p>ZZ project niet te zien</p>']);

        $response = self::$off->request('GET', '/portfolio-detail.php?slug=' . $this->projectSlug($id));

        $this->assertSame(404, $response['status']);
        $this->assertStringNotContainsString('ZZ project niet te zien', $response['body']);
    }

    // ----------------------------------------------------------------- admin

    public function testAContentPagesOwnIdSendsTheEditorToTheOwner(): void
    {
        $id = $this->product('ZZ Doorsturen');
        $page = ContentPages::ensure(ProductContentOwner::KIND, $id);
        [$session] = $this->accounts->signIn(['pages.manage', 'products.manage']);

        $response = self::$on->request('GET', '/admin/page.php?id=' . (int) $page['id'] . '&added=7', $session);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/admin/product-form.php?id=' . $id . '&tab=inhoud&added=7', $response['location']);

        foreach (['/admin/page-preview.php?id=' . (int) $page['id']] as $path) {
            $this->assertSame(404, self::$on->request('GET', $path, $session)['status'], $path);
        }
    }

    public function testTheFirstBlockMakesTheContentPageAndAWrongBlockIsRefused(): void
    {
        $id = $this->product('ZZ Eerste blok');
        [$session, $csrf] = $this->accounts->signIn(['pages.manage', 'products.manage']);

        $refused = self::$on->request('POST', '/api/admin/add-page-section.php', $session, [
            'csrf_token' => $csrf, 'page_id' => '0', 'content_owner' => 'product', 'content_owner_id' => (string) $id, 'section_type' => 'page_hero',
        ]);
        $this->assertSame(400, $refused['status']);
        $this->assertNull(ContentPages::pageFor(ProductContentOwner::KIND, $id), 'a refused block makes no page');

        $unknown = self::$on->request('POST', '/api/admin/add-page-section.php', $session, [
            'csrf_token' => $csrf, 'page_id' => '0', 'content_owner' => 'App\\Service\\ProductContentOwner', 'content_owner_id' => (string) $id, 'section_type' => 'rich_text',
        ]);
        $this->assertSame(404, $unknown['status'], 'a kind, never a class');

        $added = self::$on->request('POST', '/api/admin/add-page-section.php', $session, [
            'csrf_token' => $csrf, 'page_id' => '0', 'content_owner' => 'product', 'content_owner_id' => (string) $id, 'section_type' => 'rich_text',
        ]);
        $this->assertSame(302, $added['status']);
        $this->assertStringStartsWith('/admin/rich-text.php?section=' . urlencode('product_' . $id . ':'), $added['location']);

        // The page exists for the editor; the block joins it on its first
        // save (Content Blocks Lifecycle 1.0, ContentBlockLifecycleHttpTest).
        $page = ContentPages::pageFor(ProductContentOwner::KIND, $id);
        $this->assertNotNull($page);
        $this->assertCount(0, (new PageSectionRepository())->findForPage((int) $page['id']));
        $this->assertCount(1, (new \App\Repository\ContentBlockDraftRepository())->findForPage((int) $page['id']));
    }

    public function testTheProductEditorHasAPaginaInhoudTabForWhoMayEditBlocks(): void
    {
        $id = $this->product('ZZ Tabblad');

        [$withBlocks] = $this->accounts->signIn(['pages.manage', 'products.manage']);
        $body = self::$on->request('GET', '/admin/product-form.php?id=' . $id . '&tab=inhoud', $withBlocks)['body'];
        $this->assertStringContainsString('name="content_owner" value="product"', $body);
        $this->assertStringContainsString('name="content_owner_id" value="' . $id . '"', $body);
        $this->assertStringContainsString('data-page-section-zone', $body);

        // A product's blocks ask the product's own permission, not pages.manage
        // (ContentBlockOwnerAccessHttpTest has the rest).
        [$productsOnly] = $this->accounts->signIn(['products.manage']);
        $body = self::$on->request('GET', '/admin/product-form.php?id=' . $id, $productsOnly)['body'];
        $this->assertStringContainsString('name="content_owner" value="product"', $body, 'the block list without pages.manage');
    }

    public function testTheProjectsLayoutIsSavedAndAWrongOneRefused(): void
    {
        $id = $this->project('ZZ Opslaan');
        [$session, $csrf] = $this->accounts->signIn(['portfolio.manage', 'pages.manage']);
        $post = static fn (string $layout): array => [
            'csrf_token' => $csrf, 'item_id' => (string) $id, 'language_code' => PortfolioLocalization::defaultLanguage(),
            'title' => 'ZZ Opslaan', 'is_active' => '1', 'project_page_submitted' => '1', 'has_detail_page' => '1',
            'slug' => self::projectSlugStatic($id), 'project_layout_submitted' => '1', 'project_layout' => $layout,
        ];

        $this->assertSame(302, self::$on->request('POST', '/api/admin/update-portfolio-item.php', $session, $post('image_top'))['status']);
        $this->assertSame('image_top', $this->storedLayout($id));

        $this->assertSame(302, self::$on->request('POST', '/api/admin/update-portfolio-item.php', $session, $post(''))['status']);
        $this->assertNull($this->storedLayout($id), 'follow the default again');

        foreach (['diagonal', 'free'] as $retired) {
            self::$on->request('POST', '/api/admin/update-portfolio-item.php', $session, $post($retired));
            $this->assertNull($this->storedLayout($id), $retired . ': not a layout, nothing stored');
        }

        $screen = self::$on->request('GET', '/admin/portfolio-item.php?id=' . $id . '&tab=inhoud', $session)['body'];
        $this->assertStringContainsString('name="project_layout"', $screen);
        $this->assertStringNotContainsString('value="free"', $screen, 'no free layout to choose');
        // A save puts the project's Projectafbeeldingen block on a project
        // that has none (App\Service\ProjectImagesPlacement), so the picker
        // now posts that page rather than the owner.
        $this->assertStringContainsString('data-page-section-zone', $screen);
        $this->assertSame(['project_images'], array_column($this->rows($id), 'section_type'));
    }

    public function testThePortfolioDefaultIsSaved(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['portfolio.manage']);

        $saved = self::$on->request('POST', '/api/admin/update-portfolio-settings.php', $session, ['csrf_token' => $csrf, 'project_layout' => 'image_right']);
        $this->assertSame(302, $saved['status']);
        $this->assertMatchesRegularExpression(SavedRedirect::PATTERN, $saved['location']);
        SiteSettings::clearCache();
        $this->assertSame('image_right', PortfolioProjectLayout::siteDefault());

        self::$on->request('POST', '/api/admin/update-portfolio-settings.php', $session, ['csrf_token' => $csrf, 'project_layout' => 'sideways']);
        SiteSettings::clearCache();
        $this->assertSame('image_right', PortfolioProjectLayout::siteDefault(), 'an unknown word changes nothing');

        self::$on->request('POST', '/api/admin/update-portfolio-settings.php', $session, ['csrf_token' => $csrf, 'project_layout' => 'free']);
        SiteSettings::clearCache();
        $this->assertSame('image_right', PortfolioProjectLayout::siteDefault(), 'the free layout is retired');

        [$noPortfolio, $csrfNo] = $this->accounts->signIn(['pages.manage']);
        $this->assertSame(403, self::$on->request('POST', '/api/admin/update-portfolio-settings.php', $noPortfolio, ['csrf_token' => $csrfNo, 'project_layout' => 'free'])['status']);
    }

    // --------------------------------------------------------------- helpers

    /** @return list<array<string, mixed>> the project's blocks, in order */
    private function rows(int $projectId): array
    {
        $page = ContentPages::pageFor(PortfolioContentOwner::KIND, $projectId);

        return $page === null ? [] : (new PageSectionRepository())->findForPage((int) $page['id']);
    }

    private function get(BuiltInServer $server, string $path): string
    {
        $response = $server->request('GET', $path);
        $this->assertSame(200, $response['status'], $path);
        $this->assertStringNotContainsString('Fatal error', $response['body']);

        return $response['body'];
    }

    private function product(string $name): int
    {
        $id = $this->shop->product($name);
        $this->productIds[] = $id;

        return $id;
    }

    private int $lastProject = 0;

    private function project(string $title): int
    {
        $repository = new PortfolioGalleryRepository();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-cp-http-' . bin2hex(random_bytes(4)) . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::TITLE => $title,
            PortfolioLocalization::ALT => $title,
        ]);
        $repository->setItemProjectPage($id, true, self::projectSlugStatic($id));
        PortfolioGalleryContent::clearCache();
        $this->lastProject = $id;

        return $id;
    }

    private function projectSlug(int $id): string
    {
        return self::projectSlugStatic($id);
    }

    private static function projectSlugStatic(int $id): string
    {
        return 'zz-cp-http-project-' . $id;
    }

    private function storedLayout(int $id): ?string
    {
        $row = (new PortfolioGalleryRepository())->findItemById($id);

        return $row['project_layout'] ?? null;
    }

    private function setDefault(?string $layout): void
    {
        if ($layout === null) {
            Database::connection()->prepare('DELETE FROM site_settings WHERE setting_key = :key')->execute(['key' => PortfolioProjectLayout::SETTING]);
        } else {
            (new SiteSettingRepository())->upsertMany([PortfolioProjectLayout::SETTING => $layout]);
        }
        SiteSettings::clearCache();
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, string> $words
     *
     * @return int the page_sections id
     */
    private function block(array $page, string $type, array $words): int
    {
        [$sectionId, $sectionKey] = SectionRegistry::create($type, (string) $page['content_key']);
        $id = (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], $type, $sectionKey, $sectionId);

        $table = BlockDefinitions::get($type)?->contentTable();
        if ($words !== [] && $table !== null) {
            BlockLocalization::save($table, $sectionId, PageLocalization::defaultLanguage(), $words);
        }

        return $id;
    }
}
