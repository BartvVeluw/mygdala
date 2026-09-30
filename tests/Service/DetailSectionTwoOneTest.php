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
use App\Repository\ResponsiveImageRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blog\BlogLocalization;
use App\Service\DetailSectionContent;
use App\Service\Media\LinkedImages;
use App\Service\Media\MediaService;
use App\Service\Media\ResponsiveImage;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\Routing\LinkTargets;
use App\Service\Routing\RequestLanguage;
use App\Service\SectionRegistry;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\PageFixture;
use Tests\Support\ShopStockFixture;

/**
 * Detailsectie 2.1, as a page renders it, on the test database: a gallery
 * item whose "Afbeeldingsbron" is a product or a project shows that item's
 * MAIN picture by itself — no second picture stored — and links to its page
 * in the language of the request; a new main picture shows at once; one
 * without a main picture yet is a name tile, still linked, never a broken
 * <img>; the item's own focus point and zoom stay the item's; and what a
 * visitor cannot open (a module that is off, an id that is no item) is left
 * out without taking the section down. A library picture and a blog post
 * work as before.
 */
final class DetailSectionTwoOneTest extends TestCase
{
    private const KEY = 'zz-detail-two-one';

    private ShopStockFixture $shop;

    private int $pageId = 0;

    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $postIds = [];

    /** @var list<int> */
    private array $mediaIds = [];

    protected function setUp(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        LinkTargets::reset();
        $this->shop = new ShopStockFixture();
        $this->removePage();
        $this->pageId = PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Detail twee één');
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

        RequestLanguage::reset();
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        LinkTargets::reset();
        $this->clearCaches();
    }

    public function testAProductShowsItsMainPictureByItselfAndLinksToItsPage(): void
    {
        $picture = $this->media('zz-naambordje.jpg');
        $product = $this->shop->product('ZZ Houten naambordje');
        (new ProductImageRepository())->addFromMedia($product, $picture, (string) MediaService::find($picture)->path);
        $row = $this->item(['source_type' => 'product', 'source_id' => $product]);

        $image = $this->images()[0];

        $this->assertStringContainsString('zz-naambordje.jpg', $image['image_path'], '7. the main picture, automatically');
        $this->assertSame(LinkTargets::href('product', $product), $image['href'], '10. the product page');
        $this->assertNotSame('', $image['href']);
        $this->assertSame('ZZ Houten naambordje', $image['title']);

        $stored = $this->row($row);
        $this->assertNull($stored['media_id'], '9. no second picture is stored');
        $this->assertSame('', (string) $stored['image_path']);

        $html = $this->render();
        $this->assertStringContainsString('<a class="service-detail__gallery-link" href="' . htmlspecialchars($image['href'], ENT_QUOTES) . '">', $html);
        $this->assertSame(0, substr_count($html, '<a class="service-detail__gallery-link"', (int) strpos($html, '<a class="service-detail__gallery-link"') + 1), 'one link, no nested anchor');
    }

    public function testAProjectShowsItsMainPictureByItselfAndLinksToItsPage(): void
    {
        $project = $this->project('ZZ Nijmegen', $this->media('zz-nijmegen.jpg'));
        $this->item(['source_type' => 'portfolio_project', 'source_id' => $project]);

        $image = $this->images()[0];

        $this->assertStringContainsString('zz-nijmegen.jpg', $image['image_path'], '8. the main picture, automatically');
        $this->assertStringEndsWith(PortfolioGalleryContent::publicPath('zz-detail-two-one-' . $project), $image['href'], '11. the project page');
        $this->assertSame('ZZ Nijmegen', $image['title']);
    }

    public function testTheLinkAndTheNameFollowTheLanguageOfTheRequest(): void
    {
        $product = $this->shop->product('ZZ Naambordje');
        $picture = $this->media('zz-taal.jpg');
        (new ProductImageRepository())->addFromMedia($product, $picture, (string) MediaService::find($picture)->path);
        ShopLocalization::saveProduct($product, 'en', [ShopLocalization::NAME => 'ZZ Name plate']);
        $project = $this->project('ZZ Kast', $this->media('zz-kast.jpg'));
        $this->item(['source_type' => 'product', 'source_id' => $product]);
        $this->item(['source_type' => 'portfolio_project', 'source_id' => $project]);

        $dutch = $this->images();
        RequestLanguage::set('en', true);
        $this->clearCaches();
        $english = $this->images();

        $this->assertStringStartsWith('/en/', $english[0]['href'], '12. the English product address');
        $this->assertStringStartsWith('/en/', $english[1]['href'], '12. the English project address');
        $this->assertStringNotContainsString('/en/', $dutch[0]['href']);
        $this->assertSame(LinkTargets::href('product', $product), $english[0]['href']);
        $this->assertSame('ZZ Name plate', $english[0]['title']);
    }

    public function testANewMainPictureShowsAtOnce(): void
    {
        $first = $this->media('zz-oud.jpg');
        $second = $this->media('zz-nieuw.jpg');
        $product = $this->shop->product('ZZ Wisselt');
        $images = new ProductImageRepository();
        $old = $images->addFromMedia($product, $first, (string) MediaService::find($first)->path);
        $this->item(['source_type' => 'product', 'source_id' => $product]);
        $this->assertStringContainsString('zz-oud.jpg', $this->images()[0]['image_path']);

        $images->addFromMedia($product, $second, (string) MediaService::find($second)->path);
        $images->deleteExcept($product, array_values(array_filter(array_map(static fn (array $row): int => (int) $row['id'], $images->findByProductId($product)), static fn (int $id): bool => $id !== $old)));
        $this->clearCaches();

        $this->assertStringContainsString('zz-nieuw.jpg', $this->images()[0]['image_path'], '13. the source changed, the website follows');
    }

    public function testAnItemWithoutAMainPictureIsALinkedNameTile(): void
    {
        $product = $this->shop->product('ZZ Nog zonder foto');
        $this->item(['source_type' => 'product', 'source_id' => $product]);

        $this->assertNull(LinkedImages::resolve('product', $product), 'no picture to show');
        $item = LinkedImages::item('product', $product);
        $this->assertNotNull($item, 'but a public item');
        $this->assertSame('', $item['image_path']);
        $this->assertSame(LinkTargets::href('product', $product), $item['href']);

        $images = $this->images();
        $this->assertCount(1, $images, '14. not left out silently');
        $this->assertNull($images[0]['picture']);

        $html = $this->render();
        $gallery = substr($html, (int) strpos($html, 'service-detail__gallery-wrap'));
        $this->assertStringContainsString('service-detail__gallery-item--name', $gallery);
        $this->assertStringContainsString('<span class="service-detail__gallery-caption">ZZ Nog zonder foto</span>', $gallery);
        $this->assertStringContainsString('href="' . htmlspecialchars((string) $item['href'], ENT_QUOTES) . '"', $gallery, 'still linked');
        $this->assertStringNotContainsString('<img', substr($gallery, 0, (int) strpos($gallery, '</figure>')), 'never an image without a source');
        $this->assertStringNotContainsString('src=""', $html);
    }

    public function testALibraryPictureKeepsWorkingWithItsFocusAndZoom(): void
    {
        $picture = $this->media('zz-handmatig.jpg');
        $row = $this->item(['media_id' => $picture, 'image_path' => (string) MediaService::find($picture)->path]);
        (new ResponsiveImageRepository())->save('detail_section_images', $row, DetailSectionContent::imageSlot(), new ResponsiveImage(focusX: 20, focusY: 80, zoom: 150));

        $image = $this->images()[0];

        $this->assertStringContainsString('zz-handmatig.jpg', $image['image_path'], '15. a hand-picked picture');
        $this->assertSame('', $image['href'], 'links nowhere, as before');
        $this->assertSame('20% 80%', $image['picture']['position'], '16. focus');
        $this->assertSame(150, $image['picture']['zoom'], '17. zoom');
    }

    public function testFocusAndZoomBelongToTheItemNotToTheSource(): void
    {
        $picture = $this->media('zz-focus.jpg');
        $product = $this->shop->product('ZZ Focus');
        (new ProductImageRepository())->addFromMedia($product, $picture, (string) MediaService::find($picture)->path);
        $row = $this->item(['source_type' => 'product', 'source_id' => $product]);
        (new ResponsiveImageRepository())->save('detail_section_images', $row, DetailSectionContent::imageSlot(), new ResponsiveImage(focusX: 10, focusY: 30, zoom: 200));

        $image = $this->images()[0];

        $this->assertSame('10% 30%', $image['picture']['position']);
        $this->assertSame(200, $image['picture']['zoom']);
        $this->assertNull($image['picture']['mobile_position'], '18. a phone follows the same point');
        $this->assertSame(10, ResponsiveImage::fromRow($this->row($row), DetailSectionContent::imageSlot())->focusX, 'stored on the gallery row');
        $html = $this->render();
        $link = substr($html, (int) strpos($html, '<a class="service-detail__gallery-link"'));
        $this->assertLessThan(strpos($link, '</a>'), strpos($link, '<img'), 'the zoomed picture sits inside the one link');
    }

    public function testAModuleThatIsOffAndAnIdThatIsNoItemLeaveTheItemOutAndTheRestStands(): void
    {
        $picture = $this->media('zz-blijft.jpg');
        $this->item(['media_id' => $picture, 'image_path' => (string) MediaService::find($picture)->path]);
        $product = $this->shop->product('ZZ Uit');
        $this->item(['source_type' => 'product', 'source_id' => $product]);
        $this->item(['source_type' => 'portfolio_project', 'source_id' => 999999999]);
        $this->item(['source_type' => 'App\\Service\\Page', 'source_id' => 1]);

        $this->assertCount(2, $this->images(), '20. a forged kind and an id that is no item are left out');
        $this->assertNull(LinkedImages::item('App\\Service\\Page', 1));
        $this->assertNull(LinkedImages::item('product', -4));

        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => true, 'portfolio' => false, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        LinkTargets::reset();
        $this->clearCaches();

        $images = $this->images();
        $this->assertCount(1, $images, '19. nothing of a module that is off');
        $this->assertStringContainsString('zz-blijft.jpg', $images[0]['image_path']);
        $this->assertStringContainsString('service-detail', $this->render(), 'the section still renders');
    }

    public function testABlogPostStillWorks(): void
    {
        $post = $this->post('ZZ Houtsoorten', $this->media('zz-blog.jpg'));
        $this->item(['source_type' => 'blog_post', 'source_id' => $post]);

        $image = $this->images()[0];

        $this->assertStringContainsString('zz-blog.jpg', $image['image_path'], '22. the blog source is kept');
        $this->assertSame(LinkTargets::href('blog_post', $post), $image['href']);
        $this->assertArrayHasKey('blog_post', LinkedImages::kinds());
    }

    public function testNamesAreEscaped(): void
    {
        $product = $this->shop->product('ZZ <script>alert(1)</script> & co');
        $this->item(['source_type' => 'product', 'source_id' => $product]);

        $html = $this->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('ZZ &lt;script&gt;alert(1)&lt;/script&gt; &amp; co', $html);
    }

    // ---------------------------------------------------------------- helpers

    private int $sectionId = 0;

    private string $sectionKey = '';

    /** @param array<string, mixed> $values */
    private function item(array $values): int
    {
        if ($this->sectionId === 0) {
            [$sectionId, $sectionKey] = SectionRegistry::create('detail_section', self::KEY);
            (new PageSectionRepository())->create($this->pageId, self::KEY, 'detail_section', $sectionKey, $sectionId);
            BlockLocalization::save('detail_sections', $sectionId, PageLocalization::defaultLanguage(), ['title' => 'ZZ Galerij']);
            $this->sectionId = $sectionId;
            $this->sectionKey = $sectionKey;
        }

        $id = (new DetailSectionRepository())->createImage($this->sectionId, $values);
        $this->clearCaches();

        return $id;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        foreach ((new DetailSectionRepository())->findImagesBySectionId($this->sectionId) as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        $this->fail('no gallery row ' . $id);
    }

    /** @return list<array<string, mixed>> */
    private function images(): array
    {
        return DetailSectionContent::forSection(self::KEY, $this->sectionKey)['images'];
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
        $repository->setItemProjectPage($id, true, 'zz-detail-two-one-' . $id);

        return $id;
    }

    private function post(string $title, int $mediaId): int
    {
        $id = (new BlogPostRepository())->create([
            'slug' => 'zz-detail-two-one-' . bin2hex(random_bytes(3)),
            'featured_media_id' => $mediaId,
            'status' => 'published',
            'published_at' => '2026-01-01 10:00:00',
            'author_name' => 'ZZ',
            'noindex' => 0,
        ]);
        $this->postIds[] = $id;
        BlogLocalization::savePost($id, BlogLocalization::defaultLanguage(), [
            BlogLocalization::TITLE => $title,
            BlogLocalization::SLUG => 'zz-detail-two-one-post-' . $id,
        ]);

        return $id;
    }

    private function render(): string
    {
        ob_start();
        SectionRegistry::renderPage(self::KEY);

        return (string) ob_get_clean();
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
        $this->sectionId = 0;
        $this->sectionKey = '';
    }

    private function clearCaches(): void
    {
        PageContent::clearCache();
        BlockLocalization::clearCache();
        DetailSectionContent::clearCache();
        PageLocalization::clearCache();
        PortfolioGalleryContent::clearCache();
        PortfolioLocalization::clearCache();
        ShopLocalization::clearCache();
        MediaService::clearCache();
    }
}
