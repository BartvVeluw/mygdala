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
use App\Service\Blocks\AnchorName;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blog\BlogLocalization;
use App\Service\DetailSectionContent;
use App\Service\Media\LinkedImages;
use App\Service\Media\MediaService;
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
 * Detailsectie 2.0, as a page renders it, on the test database:
 *
 *   - THE ANCHOR has one shape (AnchorName) and puts the section in the
 *     page's anchor navigation — the existing Snelnavigatie — directly under
 *     the page's head, on every page, with the section's navigation label
 *     (else its title) in the language of the request; a page without a head
 *     gets it above its first block, a page that places the Snelnavigatie
 *     itself gets no second one, a page without anchors none at all;
 *   - A GALLERY ITEM is a library picture or an item of the site (product,
 *     project, blog post), read LIVE: its current picture, name and address,
 *     linked; an item a visitor cannot open (inactive, hidden, gone, its
 *     module off) is left out, and only the kind and the id are stored;
 *   - THE STRIP: a gallery of more than one item carries the phone strip's
 *     hooks and its two arrow buttons; a single picture has neither.
 */
final class DetailSectionTwoTest extends TestCase
{
    private const KEY = 'zz-detail-two';

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
        $this->pageId = PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Detail twee');
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

    // ----------------------------------------------------------------- anchor

    public function testAnAnchorHasOneShape(): void
    {
        $this->assertSame('hout', AnchorName::normalise('hout'));
        $this->assertSame('hout', AnchorName::normalise('#hout'));
        $this->assertSame('hout', AnchorName::normalise('  ##Hout '));
        $this->assertSame('hout-metaal', AnchorName::normalise('Hout & Metaal'));
        $this->assertSame('eenmalig', AnchorName::normalise('Éénmalig'));
        $this->assertSame('a_b-c', AnchorName::normalise('a_b--c-'));
        $this->assertSame('', AnchorName::normalise('###'));
        $this->assertTrue(AnchorName::isUnusable('!!!'));
        $this->assertFalse(AnchorName::isUnusable(''));
        $this->assertFalse(AnchorName::isUnusable('#hout'));
        $this->assertSame(AnchorName::MAX_LENGTH, strlen(AnchorName::normalise(str_repeat('a', 300))));
    }

    public function testAnchoredSectionsGetTheirNavigationRightUnderThePageHead(): void
    {
        $this->block('page_hero', ['title' => 'ZZ Kop']);
        $this->block('rich_text', ['body' => '<p>ZZ tekst</p>']);
        $wood = $this->detail(['title' => 'ZZ Hout graveren', 'nav_label' => 'ZZ Hout'], '#Hout');
        $this->detail(['title' => 'ZZ Metaal'], 'metaal');
        $this->detail(['title' => 'ZZ Zonder anker'], '');

        $html = $this->render();

        $this->assertSame(1, substr_count($html, 'class="quicknav"'), 'one navigation');
        $nav = $this->between($html, 'class="quicknav"', '</nav>');
        $this->assertStringContainsString('href="#hout">ZZ Hout</a>', $nav, 'the navigation label');
        $this->assertStringContainsString('href="#metaal">ZZ Metaal</a>', $nav, 'else the title');
        $this->assertStringNotContainsString('Zonder anker', $nav);
        $this->assertStringContainsString('id="hout"', $html, 'the stored "#Hout" is the id "hout"');
        $this->assertStringContainsString('id="metaal"', $html);

        // Right under the head, before the next block.
        $this->assertLessThan(strpos($html, 'class="quicknav"'), strpos($html, 'ZZ Kop'));
        $this->assertLessThan(strpos($html, 'ZZ tekst'), strpos($html, 'class="quicknav"'));

        // A hidden section leaves the navigation.
        (new PageSectionRepository())->setActive($wood, false);
        $this->clearCaches();
        $this->assertStringNotContainsString('#hout', $this->render());
    }

    public function testTheLabelFollowsTheLanguageOfTheRequest(): void
    {
        $this->block('page_hero', ['title' => 'ZZ Kop']);
        $section = $this->detail(['title' => 'ZZ Houtbewerking', 'nav_label' => 'ZZ Hout'], 'hout');
        BlockLocalization::save('detail_sections', $this->sectionId($section), 'en', ['title' => 'ZZ Woodwork', 'nav_label' => 'ZZ Wood']);

        RequestLanguage::set('en', true);
        $this->clearCaches();

        $this->assertStringContainsString('href="#hout">ZZ Wood</a>', $this->render(), 'the anchor is the same in every language, the label is not');
    }

    public function testAPageWithoutAHeadGetsItsNavigationFirst(): void
    {
        $this->block('rich_text', ['body' => '<p>ZZ eerst</p>']);
        $this->detail(['title' => 'ZZ Hout'], 'hout');

        $html = $this->render();

        $this->assertLessThan(strpos($html, 'ZZ eerst'), strpos($html, 'class="quicknav"'));
    }

    public function testNoAnchorsNoNavigationAndThePlacedSnelnavigatieIsTheOnlyOne(): void
    {
        $this->block('page_hero', ['title' => 'ZZ Kop']);
        $this->detail(['title' => 'ZZ Zonder'], '');
        $this->assertStringNotContainsString('class="quicknav"', $this->render());

        $this->detail(['title' => 'ZZ Hout'], 'hout');
        // The Snelnavigatie placed as a block (what the Diensten page has).
        (new PageSectionRepository())->create($this->pageId, self::KEY, 'quicknav', null, 0);
        $this->clearCaches();

        $this->assertSame(1, substr_count($this->render(), 'class="quicknav"'), 'never twice');
    }

    // --------------------------------------------------------- gallery sources

    public function testEveryKindOfGalleryItemRendersLiveAndLinked(): void
    {
        $library = $this->media('zz-library.jpg');
        $productPicture = $this->media('zz-product.jpg');
        $projectPicture = $this->media('zz-project.jpg');
        $postPicture = $this->media('zz-post.jpg');

        $product = $this->shop->product('ZZ Eiken plank');
        (new ProductImageRepository())->addFromMedia($product, $productPicture, (string) MediaService::find($productPicture)->path);
        $project = $this->project('ZZ Walnoot kast', $projectPicture);
        $post = $this->post('ZZ Houtsoorten', $postPicture);

        $section = $this->detail(['title' => 'ZZ Galerij'], '');
        $sectionId = $this->sectionId($section);
        $repository = new DetailSectionRepository();
        $repository->createImage($sectionId, ['media_id' => $library, 'image_path' => 'assets/media/zz-library.jpg']);
        $repository->createImage($sectionId, ['source_type' => 'product', 'source_id' => $product]);
        $repository->createImage($sectionId, ['source_type' => 'portfolio_project', 'source_id' => $project]);
        $repository->createImage($sectionId, ['source_type' => 'blog_post', 'source_id' => $post]);
        $this->clearCaches();

        $images = DetailSectionContent::forSection(self::KEY, $this->sectionKey($section))['images'];

        $this->assertCount(4, $images);
        $this->assertSame('', $images[0]['href'], 'a library picture links nowhere');
        $this->assertSame(LinkTargets::href('product', $product), $images[1]['href']);
        $this->assertStringContainsString('zz-product.jpg', $images[1]['image_path']);
        $this->assertSame('ZZ Eiken plank', $images[1]['title']);
        $this->assertSame(LinkTargets::href('portfolio_project', $project), $images[2]['href']);
        $this->assertStringContainsString('zz-project.jpg', $images[2]['image_path']);
        $this->assertSame(LinkTargets::href('blog_post', $post), $images[3]['href']);
        $this->assertStringContainsString('zz-post.jpg', $images[3]['image_path']);

        // Only the kind and the id are stored: no address, name or picture.
        $rows = $repository->findImagesBySectionId($sectionId);
        $this->assertSame('product', $rows[1]['source_type']);
        $this->assertNull($rows[1]['media_id']);
        $this->assertSame('', (string) $rows[1]['image_path']);

        $html = $this->render();
        $this->assertStringContainsString('<a class="service-detail__gallery-link" href="' . $images[2]['href'] . '">', $html);
        $this->assertStringContainsString('<span class="service-detail__gallery-caption">ZZ Walnoot kast</span>', $html);
        $this->assertStringContainsString('data-detail-gallery-strip tabindex="0" role="region"', $html, 'the phone strip');
        $this->assertStringContainsString('data-detail-gallery-prev', $html);
        $this->assertStringContainsString('data-detail-gallery-next', $html);
    }

    public function testANewSlugAndANewPictureFollowAtOnce(): void
    {
        $first = $this->media('zz-eerste.jpg');
        $second = $this->media('zz-tweede.jpg');
        $project = $this->project('ZZ Tafel', $first);
        $section = $this->detail(['title' => 'ZZ Live'], '');
        (new DetailSectionRepository())->createImage($this->sectionId($section), ['source_type' => 'portfolio_project', 'source_id' => $project]);

        $gallery = new PortfolioGalleryRepository();
        $gallery->setItemProjectPage($project, true, 'zz-tafel-nieuw-' . $project);
        $item = MediaService::find($second);
        $gallery->updateItem($project, ['media_id' => $second, 'image_path' => $item->path, 'thumbnail_path' => null, 'is_active' => true]);
        $this->clearCaches();

        $image = DetailSectionContent::forSection(self::KEY, $this->sectionKey($section))['images'][0];

        $this->assertStringEndsWith('/portfolio/zz-tafel-nieuw-' . $project, $image['href']);
        $this->assertStringContainsString('zz-tweede.jpg', $image['image_path']);
    }

    public function testWhatAVisitorCannotOpenIsLeftOut(): void
    {
        $product = $this->shop->product('ZZ Weg product');
        $wegPicture = $this->media('zz-weg.jpg');
        (new ProductImageRepository())->addFromMedia($product, $wegPicture, (string) MediaService::find($wegPicture)->path);
        $project = $this->project('ZZ Verborgen', $this->media('zz-verborgen.jpg'));
        $section = $this->detail(['title' => 'ZZ Onzichtbaar'], '');
        $repository = new DetailSectionRepository();
        $repository->createImage($this->sectionId($section), ['source_type' => 'product', 'source_id' => $product]);
        $repository->createImage($this->sectionId($section), ['source_type' => 'portfolio_project', 'source_id' => $project]);
        $repository->createImage($this->sectionId($section), ['source_type' => 'blog_post', 'source_id' => 999999999]);
        $this->clearCaches();
        $images = fn (): array => DetailSectionContent::forSection(self::KEY, $this->sectionKey($section))['images'];

        $this->assertCount(2, $images(), 'a post that is gone is left out');

        Database::connection()->prepare('UPDATE products SET active = 0 WHERE id = :id')->execute(['id' => $product]);
        Database::connection()->prepare('UPDATE portfolio_gallery_items SET is_active = 0 WHERE id = :id')->execute(['id' => $project]);
        $this->clearCaches();
        $this->assertSame([], $images(), 'an inactive product and a hidden project are left out');

        Database::connection()->prepare('UPDATE products SET active = 1 WHERE id = :id')->execute(['id' => $product]);
        ModuleRegistry::overrideForTests(['shop' => false, 'portfolio' => false, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        LinkTargets::reset();
        $this->clearCaches();
        $this->assertSame([], $images(), 'nothing of a module that is off');
        $this->assertFalse(LinkedImages::isAvailable('product'));
        $this->assertSame('Shop', LinkedImages::disabledModuleOf('product'));

        // The choices stay stored, untouched.
        $this->assertSame(['product', 'portfolio_project', 'blog_post'], array_column($repository->findImagesBySectionId($this->sectionId($section)), 'source_type'));
    }

    public function testASinglePictureHasNoStripControls(): void
    {
        $section = $this->detail(['title' => 'ZZ Eén'], '');
        (new DetailSectionRepository())->createImage($this->sectionId($section), ['media_id' => $this->media('zz-een.jpg'), 'image_path' => 'assets/media/zz-een.jpg']);
        $this->clearCaches();

        $html = $this->render();

        $this->assertStringContainsString('data-detail-gallery-item', $html);
        $this->assertStringNotContainsString('data-detail-gallery-prev', $html);
        $this->assertStringNotContainsString('tabindex="0" role="region"', $html);
    }

    public function testTheKindsAreTheDestinationsThatHaveAPicture(): void
    {
        $this->assertSame(['blog_post', 'product', 'portfolio_project'], array_keys(LinkedImages::kinds()));
        $this->assertNotContains('page', array_keys(LinkedImages::kinds()), 'a page has no picture of its own');
        $this->assertNotContains('collection', array_keys(LinkedImages::kinds()), 'no picture contributed');
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, string> $words */
    private function block(string $type, array $words): int
    {
        [$sectionId, $sectionKey] = SectionRegistry::create($type, self::KEY);
        $id = (new PageSectionRepository())->create($this->pageId, self::KEY, $type, $sectionKey, $sectionId);

        $table = BlockDefinitions::get($type)?->contentTable();
        if ($words !== [] && $table !== null) {
            BlockLocalization::save($table, $sectionId, PageLocalization::defaultLanguage(), $words);
        }
        $this->clearCaches();

        return $id;
    }

    /** @param array<string, string> $words */
    private function detail(array $words, string $anchor): int
    {
        $id = $this->block('detail_section', $words);
        Database::connection()->prepare('UPDATE detail_sections SET anchor = :anchor WHERE id = :id')
            ->execute(['anchor' => $anchor === '' ? null : $anchor, 'id' => $this->sectionId($id)]);
        $this->clearCaches();

        return $id;
    }

    private function sectionId(int $pageSectionId): int
    {
        return (int) (new PageSectionRepository())->findById($pageSectionId)['section_id'];
    }

    private function sectionKey(int $pageSectionId): string
    {
        return (string) (new PageSectionRepository())->findById($pageSectionId)['section_key'];
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
        $repository->setItemProjectPage($id, true, 'zz-detail-two-' . $id);

        return $id;
    }

    private function post(string $title, int $mediaId): int
    {
        $id = (new BlogPostRepository())->create([
            'slug' => 'zz-detail-two-' . bin2hex(random_bytes(3)),
            'featured_media_id' => $mediaId,
            'status' => 'published',
            'published_at' => '2026-01-01 10:00:00',
            'author_name' => 'ZZ',
            'noindex' => 0,
        ]);
        $this->postIds[] = $id;
        BlogLocalization::savePost($id, BlogLocalization::defaultLanguage(), [
            BlogLocalization::TITLE => $title,
            BlogLocalization::SLUG => 'zz-detail-two-post-' . $id,
        ]);

        return $id;
    }

    private function render(): string
    {
        ob_start();
        SectionRegistry::renderPage(self::KEY);

        return (string) ob_get_clean();
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = (int) strpos($html, $start);

        return substr($html, $from, (int) strpos($html, $end, $from) - $from);
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
            if (SectionRegistry::isDeletable((string) $row['section_type'])) {
                SectionRegistry::delete($row, $sections);
            } else {
                $sections->delete((int) $row['id']);
            }
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
        \App\Service\PageHeroContent::clearCache();
        \App\Service\RichTextContent::clearCache();
    }
}
