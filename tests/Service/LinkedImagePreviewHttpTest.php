<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\BlogPostRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\ProductImageRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blog\BlogLocalization;
use App\Service\DetailSectionContent;
use App\Service\Media\MediaService;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\Routing\LinkTargets;
use App\Service\SectionRegistry;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\ShopStockFixture;

/**
 * The live picture of a gallery source for the Detailsectie's focus frame
 * (api/admin/linked-image-preview.php, admin/assets/gallery-source.js,
 * v0.1.13), over real HTTP:
 *
 *   - a product, a Portfolio project and a blog post answer their own picture
 *     at once, through the website's own LinkedImages resolution, before any
 *     save; nothing is stored;
 *   - a new picture of the item answers at once too;
 *   - what a visitor cannot see (an inactive product, a gone id) answers no
 *     picture and "not available", never an admin-only one; an unknown kind
 *     is a 404;
 *   - only who may edit the block list asks: a Shop manager on a page's list
 *     is refused, a forged key is a 404;
 *   - the editor wires it: the row list names the endpoint, the script is on
 *     the screen, and every row has its (hidden) "no preview" line.
 */
final class LinkedImagePreviewHttpTest extends TestCase
{
    private const KEY = 'zz-linked-preview-http';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private ShopStockFixture $shop;

    private string $section = '';

    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $postIds = [];

    /** @var list<int> */
    private array $mediaIds = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true', 'MODULE_PORTFOLIO_ENABLED' => 'true', 'MODULE_BLOG_ENABLED' => 'true']);
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

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        LinkTargets::reset();
        $this->accounts = new AdminTestSession();
        $this->shop = new ShopStockFixture();
        $this->removePage();
        $pageId = PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Voorbeeld');
        [$sectionId, $sectionKey] = SectionRegistry::create('detail_section', self::KEY);
        (new PageSectionRepository())->create($pageId, self::KEY, 'detail_section', $sectionKey, $sectionId);
        BlockLocalization::save('detail_sections', (int) $sectionId, PageLocalization::defaultLanguage(), ['title' => 'ZZ Voorbeeld']);
        $this->section = self::KEY . ':' . $sectionKey;
    }

    protected function tearDown(): void
    {
        $this->removePage();
        $this->shop->cleanUp();
        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $id) {
            $gallery->deleteItem($id);
        }
        $db = Database::connection();
        foreach ($this->postIds as $id) {
            $db->prepare('DELETE FROM blog_posts WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->mediaIds as $id) {
            $db->prepare('DELETE FROM media WHERE id = :id')->execute(['id' => $id]);
        }
        $this->accounts->forget();
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        LinkTargets::reset();
        MediaService::clearCache();
    }

    public function testEachKindAnswersItsOwnPictureAtOnceAndANewOneToo(): void
    {
        $product = $this->shop->product('ZZ Voorbeeld plank');
        $productPicture = $this->media('zz-preview-product.jpg');
        (new ProductImageRepository())->addFromMedia($product, $productPicture, (string) MediaService::find($productPicture)->path);
        $project = $this->project('ZZ Voorbeeld kast', $this->media('zz-preview-project.jpg'));
        $post = $this->post('ZZ Voorbeeld bericht', $this->media('zz-preview-post.jpg'));
        [$session, $csrf] = $this->accounts->signIn(['pages.manage']);

        foreach (['product' => [$product, 'zz-preview-product.jpg'], 'portfolio_project' => [$project, 'zz-preview-project.jpg'], 'blog_post' => [$post, 'zz-preview-post.jpg']] as $kind => [$id, $file]) {
            $answer = $this->ask($session, $csrf, $kind, $id);
            self::assertSame(200, $answer['status'], $kind);
            self::assertTrue($answer['json']['available'], $kind);
            self::assertStringEndsWith($file, $answer['json']['src'], $kind . ': its picture, before any save');
            self::assertStringStartsWith('/', $answer['json']['src'], $kind . ': root-relative');
        }

        // Nothing was stored for any of it.
        $section = (new \App\Repository\DetailSectionRepository())->findBySlugAndKey(self::KEY, explode(':', $this->section)[1]);
        self::assertSame([], (new \App\Repository\DetailSectionRepository())->findImagesBySectionId((int) $section['id']));

        // The product gets another picture: the answer follows at once.
        $second = $this->media('zz-preview-product-nieuw.jpg');
        Database::connection()->prepare('DELETE FROM product_images WHERE product_id = :id')->execute(['id' => $product]);
        (new ProductImageRepository())->addFromMedia($product, $second, (string) MediaService::find($second)->path);
        self::assertStringEndsWith('zz-preview-product-nieuw.jpg', $this->ask($session, $csrf, 'product', $product)['json']['src']);
    }

    public function testWhatAVisitorCannotSeeAnswersNoPictureAndOnlyAnEditorOfTheListMayAsk(): void
    {
        $product = $this->shop->product('ZZ Voorbeeld inactief');
        $picture = $this->media('zz-preview-inactief.jpg');
        (new ProductImageRepository())->addFromMedia($product, $picture, (string) MediaService::find($picture)->path);
        Database::connection()->prepare('UPDATE products SET active = 0 WHERE id = :id')->execute(['id' => $product]);
        [$session, $csrf] = $this->accounts->signIn(['pages.manage']);

        foreach (['an inactive product' => $product, 'a gone id' => 999999999] as $what => $id) {
            $answer = $this->ask($session, $csrf, 'product', $id);
            self::assertSame(200, $answer['status'], $what);
            self::assertSame(['src' => '', 'available' => false, 'picture' => false], $answer['json'], $what . ': no picture, not an admin-only one');
        }
        self::assertSame(404, $this->ask($session, $csrf, 'App\\Service\\Page', 1)['status'], 'a kind, never a class');

        // Who may not edit the list, and a forged key.
        [$shop, $shopCsrf] = $this->accounts->signIn(['products.manage']);
        self::assertSame(403, $this->ask($shop, $shopCsrf, 'product', $product)['status'], 'a page\'s list is not a Shop manager\'s');
        self::assertSame(404, $this->ask($session, $csrf, 'product', $product, 'zz-bestaat-niet:custom-x')['status']);
        self::assertSame(403, self::$server->request('POST', '/api/admin/linked-image-preview.php', $session, ['section' => $this->section, 'kind' => 'product', 'id' => (string) $product])['status'], 'no CSRF token');
    }

    public function testTheEditorWiresTheLivePreview(): void
    {
        [$session] = $this->accounts->signIn(['pages.manage']);
        $screen = self::$server->request('GET', '/admin/detail-section.php?section=' . urlencode($this->section), $session)['body'];

        self::assertStringContainsString('data-row-list="detail-section-images" data-linked-image-preview="/api/admin/linked-image-preview.php"', $screen);
        self::assertStringContainsString('/admin/assets/gallery-source.js', $screen);
        self::assertMatchesRegularExpression('/<p class="admin-text-muted" data-linked-image-missing hidden>/', $screen, 'in the template for a new row');

        $script = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/gallery-source.js'));
        foreach (['product', 'portfolio', 'blog'] as $module) {
            self::assertStringNotContainsString($module, strtolower($script), 'no module is named in the script');
        }
        self::assertStringContainsString('new CustomEvent("rm:picture"', $script);
        self::assertStringContainsString('data-linked-image-ticket', $script, 'a slower answer never overwrites a later choice');
    }

    // ---------------------------------------------------------- helpers

    /** @return array{status: int, json: mixed} */
    private function ask(string $session, string $csrf, string $kind, int $id, ?string $section = null): array
    {
        $response = self::$server->request('POST', '/api/admin/linked-image-preview.php', $session, [
            'csrf_token' => $csrf, 'section' => $section ?? $this->section, 'kind' => $kind, 'id' => (string) $id,
        ]);

        return ['status' => $response['status'], 'json' => json_decode($response['body'], true)];
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

    private function project(string $title, int $mediaId): int
    {
        $repository = new PortfolioGalleryRepository();
        $media = MediaService::find($mediaId);
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], ['media_id' => $mediaId, 'image_path' => $media->path, 'thumbnail_path' => null]);
        $this->itemIds[] = $id;
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [PortfolioLocalization::TITLE => $title]);
        $repository->setItemProjectPage($id, true, 'zz-linked-preview-' . $id);
        PortfolioGalleryContent::clearCache();

        return $id;
    }

    private function post(string $title, int $mediaId): int
    {
        $id = (new BlogPostRepository())->create([
            'slug' => 'zz-linked-preview-' . bin2hex(random_bytes(3)),
            'featured_media_id' => $mediaId,
            'status' => 'published',
            'published_at' => '2026-01-01 10:00:00',
            'author_name' => 'ZZ',
            'noindex' => 0,
        ]);
        $this->postIds[] = $id;
        BlogLocalization::savePost($id, BlogLocalization::defaultLanguage(), [
            BlogLocalization::TITLE => $title,
            BlogLocalization::SLUG => 'zz-linked-preview-post-' . $id,
        ]);

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
        DetailSectionContent::clearCache();
        ShopLocalization::clearCache();
    }
}
