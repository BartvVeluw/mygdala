<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\BlogPostRepository;
use App\Repository\DetailSectionRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\ProductImageRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blog\BlogLocalization;
use App\Service\DetailSectionContent;
use App\Service\Media\MediaService;
use App\Service\Media\Usage\ContentBlockMediaUsage;
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
 * A Detailsectie gallery item's focus point (Responsive Media 2.0 on a new
 * place, v0.1.13), over real HTTP through the editor's endpoint and on the
 * page:
 *
 *   - a new item sits in the middle; a chosen point is stored on the gallery
 *     row and printed as the picture's object-position, the same on a phone;
 *   - the editor shows the shared focus editor per item — nine points, two
 *     sliders, the square frame — without a phone part, and not for the main
 *     image, which is never cropped;
 *   - for a library picture, a product's, a project's and a blog post's own
 *     picture alike; when that item gets another picture, the new one shows
 *     with the point chosen here, and nothing of the item was changed;
 *   - a point that is no number is refused, one outside 0–100 clamped;
 *   - the library still knows the picture is used.
 */
final class DetailSectionGalleryFocusHttpTest extends TestCase
{
    private const KEY = 'zz-detail-focus-http';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private ShopStockFixture $shop;

    private int $pageId = 0;

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
        $this->pageId = PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Detail focus');
        $this->clearCaches();
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
        $this->clearCaches();
    }

    public function testAPointIsStoredOnTheGalleryRowAndPrintedTheSameOnAPhone(): void
    {
        $library = $this->media('zz-focus-bieb.jpg');
        $other = $this->media('zz-focus-midden.jpg');
        $section = $this->section();
        [$session, $csrf] = $this->accounts->signIn(['pages.manage']);

        $this->save($session, $csrf, $section, [
            'new0' => ['present' => '1', 'source' => 'media', 'media_id' => (string) $library, 'image_presentation' => '1', 'image_focus_x' => '20', 'image_focus_y' => '80'],
            'new1' => ['present' => '1', 'source' => 'media', 'media_id' => (string) $other],
        ]);

        $rows = $this->rows($section);
        self::assertSame([[20, 80], [50, 50]], array_map(static fn (array $row): array => [(int) $row['image_focus_x'], (int) $row['image_focus_y']], $rows), 'a new item without a point sits in the middle');
        self::assertNull($rows[0]['image_mobile_media_id']);
        self::assertNull($rows[0]['image_mobile_focus_x'], 'no phone point: a phone follows the same one');
        self::assertArrayNotHasKey('focus_x', (array) Database::connection()->query('SELECT * FROM media WHERE id = ' . $library)->fetch(), 'the library item has no point of its own');

        $html = $this->render();
        self::assertMatchesRegularExpression('#<img src="[^"]*zz-focus-bieb\.jpg"[^>]*style="object-position: 20% 80%;"#', $html);
        self::assertMatchesRegularExpression('#<img src="[^"]*zz-focus-midden\.jpg"(?![^>]*object-position)[^>]*>#', $html, 'the middle prints what it always did');
        self::assertStringNotContainsString('data-rm-mobile-position', $html);
        $styles = \App\Service\PageAssets::collected()['styles'];
        self::assertContains('assets/css/responsive-media.css', $styles, 'the shared stylesheet');
        self::assertLessThan(array_search('assets/css/blocks/detail-section.css', $styles, true), array_search('assets/css/responsive-media.css', $styles, true), 'before the block\'s own');

        // Save and reload: the editor shows the point, with the shared editor.
        $screen = self::$server->request('GET', '/admin/detail-section.php?section=' . urlencode(self::KEY . ':' . $this->key($section)), $session)['body'];
        $first = (string) $rows[0]['id'];
        self::assertMatchesRegularExpression('/name="images\[' . $first . '\]\[image_focus_x\]" min="0" max="100" step="1" value="20"/', $screen);
        self::assertMatchesRegularExpression('/name="images\[' . $first . '\]\[image_focus_y\]" min="0" max="100" step="1" value="80"/', $screen);
        self::assertSame(2 * 9 + 9, substr_count($screen, 'data-rm-preset '), 'nine points per item, and per item in the template for a new one');
        self::assertStringNotContainsString('data-rm-mobile', $screen, 'no phone part');
        self::assertStringContainsString('--admin-rm-desktop-ratio: 1 / 1', $screen, 'the square of the gallery');
        self::assertStringContainsString('<legend>Focuspunt', $screen);
        self::assertStringContainsString('/admin/assets/responsive-image.js', $screen);
        $mainImage = substr($screen, (int) strpos($screen, 'data-detail-main-image'), (int) strpos($screen, 'id="detail-points-title"') - (int) strpos($screen, 'data-detail-main-image'));
        self::assertStringNotContainsString('data-rm', $mainImage, 'the main image is never cropped: no point');

        // A save of the same form keeps it; the library still knows the use.
        $this->save($session, $csrf, $section, [
            $first => ['present' => '1', 'source' => 'media', 'media_id' => (string) $library, 'image_presentation' => '1', 'image_focus_x' => '20', 'image_focus_y' => '80'],
            (string) $rows[1]['id'] => ['present' => '1', 'source' => 'media', 'media_id' => (string) $other, 'image_presentation' => '1', 'image_focus_x' => '0', 'image_focus_y' => '100'],
        ]);
        self::assertSame([[20, 80], [0, 100]], array_map(static fn (array $row): array => [(int) $row['image_focus_x'], (int) $row['image_focus_y']], $this->rows($section)), 'a preset is a point like any other');
        self::assertNotSame([], (new ContentBlockMediaUsage())->usagesFor([$library])[$library] ?? []);
    }

    public function testAnItemsOwnPictureChangesAndThePointChosenHereStays(): void
    {
        $productPicture = $this->media('zz-focus-product.jpg');
        $projectPicture = $this->media('zz-focus-project.jpg');
        $postPicture = $this->media('zz-focus-post.jpg');
        $product = $this->shop->product('ZZ Focus plank');
        (new ProductImageRepository())->addFromMedia($product, $productPicture, (string) MediaService::find($productPicture)->path);
        $project = $this->project('ZZ Focus kast', $projectPicture);
        $post = $this->post('ZZ Focus bericht', $postPicture);
        $section = $this->section();
        [$session, $csrf] = $this->accounts->signIn(['pages.manage']);

        $this->save($session, $csrf, $section, [
            'new0' => ['present' => '1', 'source' => 'product', 'source_product' => (string) $product, 'image_presentation' => '1', 'image_focus_x' => '10', 'image_focus_y' => '20'],
            'new1' => ['present' => '1', 'source' => 'portfolio_project', 'source_portfolio_project' => (string) $project, 'image_presentation' => '1', 'image_focus_x' => '30', 'image_focus_y' => '40'],
            'new2' => ['present' => '1', 'source' => 'blog_post', 'source_blog_post' => (string) $post, 'image_presentation' => '1', 'image_focus_x' => '70', 'image_focus_y' => '90'],
        ]);
        $rows = $this->rows($section);
        self::assertSame(['product', 'portfolio_project', 'blog_post'], array_column($rows, 'source_type'));
        self::assertSame([[10, 20], [30, 40], [70, 90]], array_map(static fn (array $row): array => [(int) $row['image_focus_x'], (int) $row['image_focus_y']], $rows));

        $html = $this->render();
        foreach (['zz-focus-product.jpg' => '10% 20%', 'zz-focus-project.jpg' => '30% 40%', 'zz-focus-post.jpg' => '70% 90%'] as $file => $point) {
            self::assertMatchesRegularExpression('#<img src="[^"]*' . preg_quote($file, '#') . '"[^>]*style="object-position: ' . $point . ';"#', $html, $file);
        }

        // The editor's frame shows each item's own picture.
        $screen = self::$server->request('GET', '/admin/detail-section.php?section=' . urlencode(self::KEY . ':' . $this->key($section)), $session)['body'];
        self::assertMatchesRegularExpression('#<img src="[^"]*zz-focus-project\.jpg" alt="" draggable="false" loading="lazy" decoding="async" data-rm-preview style="object-position: 30% 40%;">#', $screen);

        // Each item gets another picture: the new one shows, with the same point.
        $newProduct = $this->media('zz-focus-product-nieuw.jpg');
        $newProject = $this->media('zz-focus-project-nieuw.jpg');
        $newPost = $this->media('zz-focus-post-nieuw.jpg');
        Database::connection()->prepare('DELETE FROM product_images WHERE product_id = :id')->execute(['id' => $product]);
        (new ProductImageRepository())->addFromMedia($product, $newProduct, (string) MediaService::find($newProduct)->path);
        (new PortfolioGalleryRepository())->updateItem($project, ['media_id' => $newProject, 'image_path' => (string) MediaService::find($newProject)->path, 'thumbnail_path' => null, 'is_active' => true]);
        Database::connection()->prepare('UPDATE blog_posts SET featured_media_id = :media WHERE id = :id')->execute(['media' => $newPost, 'id' => $post]);
        $this->clearCaches();

        $html = $this->render();
        foreach (['zz-focus-product-nieuw.jpg' => '10% 20%', 'zz-focus-project-nieuw.jpg' => '30% 40%', 'zz-focus-post-nieuw.jpg' => '70% 90%'] as $file => $point) {
            self::assertMatchesRegularExpression('#<img src="[^"]*' . preg_quote($file, '#') . '"[^>]*style="object-position: ' . $point . ';"#', $html, $file);
        }
        self::assertSame([[10, 20], [30, 40], [70, 90]], array_map(static fn (array $row): array => [(int) $row['image_focus_x'], (int) $row['image_focus_y']], $this->rows($section)));
    }

    public function testAPointThatIsNoNumberIsRefusedAndOneOutOfRangeClamped(): void
    {
        $library = $this->media('zz-focus-grens.jpg');
        $section = $this->section();
        [$session, $csrf] = $this->accounts->signIn(['pages.manage']);
        $this->save($session, $csrf, $section, ['new0' => ['present' => '1', 'source' => 'media', 'media_id' => (string) $library]]);
        $id = (string) $this->rows($section)[0]['id'];

        $this->save($session, $csrf, $section, [$id => ['present' => '1', 'source' => 'media', 'media_id' => (string) $library, 'image_presentation' => '1', 'image_focus_x' => 'links', 'image_focus_y' => '10']]);
        self::assertSame([50, 50], [(int) $this->rows($section)[0]['image_focus_x'], (int) $this->rows($section)[0]['image_focus_y']], 'refused: nothing stored');
        self::assertNotSame([], (array) $this->accounts->read($session, 'admin_detail_section_errors'));
        self::assertArrayHasKey('images.' . $id . '.presentation.focus', (array) $this->accounts->read($session, 'admin_detail_section_field_errors'), 'the message next to the item');

        $this->save($session, $csrf, $section, [$id => ['present' => '1', 'source' => 'media', 'media_id' => (string) $library, 'image_presentation' => '1', 'image_focus_x' => '150', 'image_focus_y' => '-5']]);
        self::assertSame([100, 0], [(int) $this->rows($section)[0]['image_focus_x'], (int) $this->rows($section)[0]['image_focus_y']], 'a hand-made number is clamped');
    }

    // ---------------------------------------------------------- helpers

    /** @param array<string, array<string, string>> $images */
    private function save(string $session, string $csrf, int $pageSectionId, array $images): void
    {
        $fields = [
            'csrf_token' => $csrf,
            'section' => self::KEY . ':' . $this->key($pageSectionId),
            'language_code' => PageLocalization::defaultLanguage(),
            'title' => 'ZZ Focus',
            'is_active' => '1',
            'image_position' => 'image_right',
            'images_present' => '1',
        ];
        foreach ($images as $key => $row) {
            foreach ($row as $name => $value) {
                $fields['images[' . $key . '][' . $name . ']'] = $value;
            }
        }

        $response = self::$server->request('POST', '/api/admin/update-detail-section.php', $session, $fields);
        self::assertSame(302, $response['status']);
        $this->clearCaches();
    }

    private function section(): int
    {
        [$sectionId, $sectionKey] = SectionRegistry::create('detail_section', self::KEY);
        BlockLocalization::save('detail_sections', (int) $sectionId, PageLocalization::defaultLanguage(), ['title' => 'ZZ Focus']);

        return (new PageSectionRepository())->create($this->pageId, self::KEY, 'detail_section', $sectionKey, $sectionId);
    }

    private function key(int $pageSectionId): string
    {
        return (string) (new PageSectionRepository())->findById($pageSectionId)['section_key'];
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $pageSectionId): array
    {
        return (new DetailSectionRepository())->findImagesBySectionId((int) (new PageSectionRepository())->findById($pageSectionId)['section_id']);
    }

    private function render(): string
    {
        $this->clearCaches();
        \App\Service\PageAssets::reset();
        ob_start();
        SectionRegistry::collectPageAssets(self::KEY);
        SectionRegistry::renderPage(self::KEY);

        return (string) ob_get_clean();
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
        $repository->setItemProjectPage($id, true, 'zz-detail-focus-' . $id);

        return $id;
    }

    private function post(string $title, int $mediaId): int
    {
        $id = (new BlogPostRepository())->create([
            'slug' => 'zz-detail-focus-' . bin2hex(random_bytes(3)),
            'featured_media_id' => $mediaId,
            'status' => 'published',
            'published_at' => '2026-01-01 10:00:00',
            'author_name' => 'ZZ',
            'noindex' => 0,
        ]);
        $this->postIds[] = $id;
        BlogLocalization::savePost($id, BlogLocalization::defaultLanguage(), [
            BlogLocalization::TITLE => $title,
            BlogLocalization::SLUG => 'zz-detail-focus-post-' . $id,
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
    }

    private function clearCaches(): void
    {
        PageContent::clearCache();
        DetailSectionContent::clearCache();
        PageLocalization::clearCache();
        PortfolioGalleryContent::clearCache();
        ShopLocalization::clearCache();
        MediaService::clearCache();
        BlockLocalization::clearCache();
        \App\Service\PageHeroContent::clearCache();
        \App\Service\RichTextContent::clearCache();
    }
}
