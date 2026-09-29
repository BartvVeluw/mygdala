<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\DetailSectionRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\ContentOwners\ContentPages;
use App\Service\DetailSectionContent;
use App\Service\Media\MediaService;
use App\Service\Media\Usage\ContentBlockMediaUsage;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PortfolioContentOwner;
use App\Service\PortfolioLocalization;
use App\Service\ProductContentOwner;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\ShopStockFixture;

/**
 * Where the Mediabibliotheek says a picture is used, for the blocks of a page,
 * a product and a project (App\Service\Media\Usage\ContentBlockMediaUsage,
 * v0.1.13): the permission of each place is the one of its block list, by its
 * owner (ContentBlockAccess), never the holder page's pages.manage.
 *
 *   - a page's block asks pages.manage, a product's products.manage, a
 *     project's portfolio.manage;
 *   - on the item screen a Pages-only, a Shop-only and a Portfolio-only
 *     manager each get a link to their own place only and are told the other
 *     places exist without being named; a super admin sees all three;
 *   - a link a manager is not shown, requested by hand (a forged content key
 *     in the editor address), is refused.
 */
final class ContentBlockMediaUsageOwnerHttpTest extends TestCase
{
    private const KEY = 'zz-usage-owner-http';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private ShopStockFixture $shop;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $mediaIds = [];

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
        foreach ($this->mediaIds as $id) {
            Database::connection()->prepare('DELETE FROM media WHERE id = :id')->execute(['id' => $id]);
        }
        MediaService::clearCache();
        PageContent::clearCache();
        $this->accounts->forget();
        \App\Module\ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        \App\Service\ContentOwners\ContentOwners::reset();
    }

    public function testEachPlaceAsksThePermissionOfItsOwnerAndLinksOnlyWhoMayOpenIt(): void
    {
        $picture = $this->media('zz-usage-owner.jpg');

        $pageId = PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Gebruik pagina');
        $onPage = $this->detail((array) (new PageRepository())->findById($pageId), $picture);

        $productId = $this->shop->product('ZZ Gebruik product');
        $this->productIds[] = $productId;
        $onProduct = $this->detail(ContentPages::ensure(ProductContentOwner::KIND, $productId), $picture);

        $projectId = $this->project('ZZ Gebruik project');
        $onProject = $this->detail(ContentPages::ensure(PortfolioContentOwner::KIND, $projectId), $picture);
        PageContent::clearCache();

        // The provider: one place per list, each with its owner's permission.
        $usages = (new ContentBlockMediaUsage())->usagesFor([$picture])[$picture] ?? [];
        $byUrl = [];
        foreach ($usages as $usage) {
            $byUrl[(string) $usage->editUrl] = $usage->permission;
        }
        self::assertSame([
            $onPage => 'pages.manage',
            $onProduct => 'products.manage',
            $onProject => 'portfolio.manage',
        ], array_intersect_key($byUrl, [$onPage => 1, $onProduct => 1, $onProject => 1]));

        // The item screen, as four managers see it.
        $expect = [
            'pages' => [['pages.manage'], [$onPage], [$onProduct, $onProject]],
            'shop' => [['products.manage'], [$onProduct], [$onPage, $onProject]],
            'portfolio' => [['portfolio.manage'], [$onProject], [$onPage, $onProduct]],
        ];
        foreach ($expect as $who => [$permissions, $linked, $notLinked]) {
            [$session] = $this->accounts->signIn($permissions);
            $screen = self::$server->request('GET', '/admin/media.php?id=' . $picture, $session);
            self::assertSame(200, $screen['status'], $who . ': media.view comes with each of these rights');

            foreach ($linked as $url) {
                self::assertStringContainsString('href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"', $screen['body'], $who . ' may open ' . $url);
                self::assertSame(200, self::$server->request('GET', $url, $session)['status'], $who . ': the link works');
            }
            foreach ($notLinked as $url) {
                self::assertStringNotContainsString(htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), $screen['body'], $who . ' gets no link to ' . $url);
                self::assertSame(403, self::$server->request('GET', $url, $session)['status'], $who . ': typed by hand, a forged key is refused');
            }
            self::assertStringContainsString('2 plekken', $screen['body'], $who . ': told the other places exist, not where');
        }

        [$super] = $this->accounts->signIn([], true);
        $screen = self::$server->request('GET', '/admin/media.php?id=' . $picture, $super)['body'];
        foreach ([$onPage, $onProduct, $onProject] as $url) {
            self::assertStringContainsString('href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"', $screen, 'a super admin sees every place');
        }
        self::assertStringContainsString('Product: ZZ Gebruik product', $screen, 'named after its owner, not its holder key');
    }

    // ---------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $page
     *
     * @return string the editor URL of the new Detailsectie
     */
    private function detail(array $page, int $mediaId): string
    {
        [$sectionId, $sectionKey] = SectionRegistry::create('detail_section', (string) $page['content_key']);
        (new PageSectionRepository())->create((int) $page['id'], (string) $page['content_key'], 'detail_section', $sectionKey, $sectionId);
        BlockLocalization::save('detail_sections', (int) $sectionId, PageLocalization::defaultLanguage(), ['title' => 'ZZ Gebruik']);
        $media = MediaService::find($mediaId);
        (new DetailSectionRepository())->updateMainImage((int) $sectionId, ['main_media_id' => $mediaId, 'main_image_path' => (string) $media?->path]);
        DetailSectionContent::clearCache();

        return '/admin/detail-section.php?section=' . rawurlencode($page['content_key'] . ':' . $sectionKey);
    }

    private function project(string $title): int
    {
        $repository = new PortfolioGalleryRepository();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-usage-owner-' . bin2hex(random_bytes(3)) . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [PortfolioLocalization::TITLE => $title, PortfolioLocalization::ALT => $title]);

        return $id;
    }

    private function media(string $name): int
    {
        $db = Database::connection();
        $db->prepare(
            "INSERT INTO media (path, thumbnail_path, original_filename, display_name, mime_type, width, height, file_size, alt_text, created_at, updated_at)
             VALUES (:path, '', :name, :display, 'image/jpeg', 10, 10, 100, 'ZZ alt', NOW(), NOW())"
        )->execute(['path' => 'assets/media/' . bin2hex(random_bytes(3)) . '-' . $name, 'name' => $name, 'display' => $name]);
        $id = (int) $db->lastInsertId();
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    private function removePage(): void
    {
        $pages = new PageRepository();
        $page = $pages->findByContentKey(self::KEY);
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
