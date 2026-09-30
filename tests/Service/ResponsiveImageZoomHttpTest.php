<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\BlogPostRepository;
use App\Repository\CardCarouselRepository;
use App\Repository\DetailSectionRepository;
use App\Repository\HomepageHeroRepository;
use App\Repository\HoverCardGridRepository;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\ProductImageRepository;
use App\Repository\ResponsiveImageRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blog\BlogLocalization;
use App\Service\CardCarouselContent;
use App\Service\CtaBandContent;
use App\Service\DetailSectionContent;
use App\Service\HomepageHeroContent;
use App\Service\HoverCardGridContent;
use App\Service\Language\SiteLanguages;
use App\Service\Media\MediaService;
use App\Service\Media\ResponsiveImage;
use App\Service\Media\Usage\ContentBlockMediaUsage;
use App\Service\MediaBannerContent;
use App\Service\PageContent;
use App\Service\PageHeroContent;
use App\Service\PageLocalization;
use App\Service\PageService;
use App\Service\PortfolioLocalization;
use App\Service\Routing\LinkTargets;
use App\Service\SectionRegistry;
use App\Service\TextImageSplitContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\ShopStockFixture;

/**
 * Focus and zoom (Responsive Media 3.0) over real HTTP, through the editors'
 * own endpoints and screens, and on the page:
 *
 *   - a carousel card stores its zoom and the phone's own zoom, shows them
 *     back, refuses a zoom that is no number and clamps one out of range; a
 *     contained picture keeps its zoom and prints none, and cover brings it
 *     back;
 *   - EVERY PLACE (the eight of MEDIA.md) prints a stored zoom as the scale
 *     around its point, and its editor shows the slider at that value;
 *   - a Detailsectie gallery item keeps its point and zoom whatever it shows
 *     (a library picture, a product's, a project's, a blog post's) and when
 *     that item gets another picture; a linked one stays one link; the
 *     editor's frame shows a linked item's library thumbnail, never its
 *     full-size original;
 *   - a new row that only carries its sliders is no row;
 *   - a zoom is no use of a picture: the library's list of uses is the same.
 *
 * The pages, blocks, items, accounts and library rows are this test's own
 * and are removed in tearDown(); the homepage Hero is put back as it was.
 * Without a server the test skips itself.
 */
final class ResponsiveImageZoomHttpTest extends TestCase
{
    private const KEY = 'zz-rm-zoom-http';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private ShopStockFixture $shop;

    private int $pageId = 0;

    /** @var list<int> */
    private array $mediaIds = [];

    /** @var list<int> */
    private array $projectIds = [];

    /** @var list<int> */
    private array $postIds = [];

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
        if (BlockLocalization::defaultLanguage() !== 'nl' || !SiteLanguages::isActive('en')) {
            $this->markTestSkipped('this test expects the test database to publish nl (default) and en');
        }

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        LinkTargets::reset();
        $this->accounts = new AdminTestSession();
        $this->shop = new ShopStockFixture();
        $this->removePage();
        $this->pageId = PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Zoom');
        $this->clearCaches();
    }

    protected function tearDown(): void
    {
        // The blocks hold the library items (ON DELETE RESTRICT): page first.
        $this->removePage();
        $this->shop->cleanUp();

        $gallery = new PortfolioGalleryRepository();
        foreach ($this->projectIds as $id) {
            $gallery->deleteItem($id);
        }
        foreach ($this->postIds as $id) {
            Database::connection()->prepare('DELETE FROM blog_posts WHERE id = :id')->execute(['id' => $id]);
        }
        $media = new MediaRepository();
        foreach ($this->mediaIds as $id) {
            $media->delete($id);
        }

        $this->accounts->forget();
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        LinkTargets::reset();
        $this->clearCaches();
    }

    // ------------------------------------------------------ a carousel card

    public function testACardStoresItsZoomAndThePhonesOwnZoomAndShowsThemBack(): void
    {
        $picture = $this->libraryItem('zz-zoom-kaart');
        [$carouselId, $section] = $this->block('card_carousel');
        $card = (new CardCarouselRepository())->createCard($carouselId, true);
        BlockLocalization::save('carousel_cards', $card, 'nl', ['title' => 'Hout']);
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $this->assertSaved($this->saveCard($session, $csrf, $card, $picture, [
            'image_focus_x' => '0', 'image_focus_y' => '0', 'image_zoom' => '150',
            'image_mobile_focus_own' => '1', 'image_mobile_focus_x' => '100', 'image_mobile_focus_y' => '100', 'image_mobile_zoom' => '200',
        ]));
        $row = (new CardCarouselRepository())->findCardById($card);
        self::assertSame([0, 0, 150, 100, 100, 200], [(int) $row['image_focus_x'], (int) $row['image_focus_y'], (int) $row['image_zoom'], (int) $row['image_mobile_focus_x'], (int) $row['image_mobile_focus_y'], (int) $row['image_mobile_zoom']]);

        // Saved and reloaded: the sliders say it.
        $screen = self::$server->request('GET', '/admin/carousel-card.php?card_id=' . $card, $session)['body'];
        self::assertMatchesRegularExpression('/name="image_zoom" min="100" max="200" step="1" value="150"/', $screen);
        self::assertMatchesRegularExpression('/name="image_mobile_zoom" min="100" max="200" step="1" value="200"/', $screen);
        self::assertMatchesRegularExpression('/data-rm-preview style="object-position: 0% 0%; scale: 1\.5; transform-origin: 0% 0%;"/', $screen, 'the preview is the crop');
        self::assertSame(2, substr_count($screen, 'data-rm-reset>Afbeelding resetten</button>'), 'one reset per frame');

        // The page: the zoom around the point, the phone's own under the breakpoint.
        $html = $this->page();
        self::assertStringContainsString('style="object-position: 0% 0%; scale: 1.5; transform-origin: 0% 0%; --rm-mobile-position: 100% 100%; --rm-mobile-zoom: 2;" data-rm-mobile-position data-rm-mobile-zoom>', $html);

        // A zoom that is no number is refused, next to the field; nothing changes.
        $response = $this->saveCard($session, $csrf, $card, $picture, ['image_zoom' => 'heel veel', 'image_focus_x' => '10']);
        self::assertStringNotContainsString('saved=1', $response['location']);
        self::assertSame([0, 150], [(int) (new CardCarouselRepository())->findCardById($card)['image_focus_x'], (int) (new CardCarouselRepository())->findCardById($card)['image_zoom']]);
        self::assertStringContainsString('Deze zoom kan niet.', self::$server->request('GET', '/admin/carousel-card.php?card_id=' . $card, $session)['body']);

        // Out of range is clamped: 100-200.
        $this->assertSaved($this->saveCard($session, $csrf, $card, $picture, ['image_zoom' => '999']));
        self::assertSame(200, (int) (new CardCarouselRepository())->findCardById($card)['image_zoom']);
        $this->assertSaved($this->saveCard($session, $csrf, $card, $picture, ['image_zoom' => '20']));
        self::assertSame(100, (int) (new CardCarouselRepository())->findCardById($card)['image_zoom']);

        // Contain keeps the zoom and prints none; cover brings it back.
        $this->assertSaved($this->saveCard($session, $csrf, $card, $picture, ['image_zoom' => '170', 'image_fit' => 'contain']));
        self::assertSame([170, 'contain'], [(int) (new CardCarouselRepository())->findCardById($card)['image_zoom'], (new CardCarouselRepository())->findCardById($card)['image_fit']]);
        $html = $this->page();
        self::assertMatchesRegularExpression('#zz-zoom-kaart[^"]*"[^>]*style="object-fit: contain;"#', $html);
        self::assertStringNotContainsString('scale: 1.7', $html);
        $screen = self::$server->request('GET', '/admin/carousel-card.php?card_id=' . $card, $session)['body'];
        self::assertStringContainsString('<div class="admin-rm__focus is-contained" data-rm-focus="desktop">', $screen, 'the zoom row hides');
        self::assertMatchesRegularExpression('/name="image_zoom" min="100" max="200" step="1" value="170"/', $screen, 'and still carries the value');

        $this->assertSaved($this->saveCard($session, $csrf, $card, $picture, ['image_zoom' => '170', 'image_fit' => 'cover']));
        self::assertStringContainsString('style="scale: 1.7;"', $this->page());
    }

    // ---------------------------------------------------------- every place

    public function testEveryPlacePrintsItsZoomAroundItsPointAndItsEditorShowsIt(): void
    {
        $zoom = new ResponsiveImage(20, 30, zoom: 150);
        $expected = 'object-position: 20% 30%; scale: 1.5; transform-origin: 20% 30%;';
        $slider = '/name="[^"]*image_zoom\]?" min="100" max="200" step="1" value="150"/';
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $repository = new ResponsiveImageRepository();
        $db = Database::connection();

        // Kaarten-carrousel.
        $picture = $this->libraryItem('zz-zoom-carrousel');
        [$carouselId] = $this->block('card_carousel');
        $card = (new CardCarouselRepository())->createCard($carouselId, true);
        BlockLocalization::save('carousel_cards', $card, 'nl', ['title' => 'Kaart']);
        $db->prepare('UPDATE carousel_cards SET media_id = ? WHERE id = ?')->execute([$picture, $card]);
        $repository->save('carousel_cards', $card, CardCarouselContent::imageSlot(), $zoom);
        $witnesses['Kaarten-carrousel'] = ['zz-zoom-carrousel', '/admin/carousel-card.php?card_id=' . $card];

        // Tekst met afbeelding.
        $picture = $this->libraryItem('zz-zoom-tekst');
        [$splitId, $splitSection] = $this->block('text_image_split');
        $db->prepare('DELETE FROM text_image_split_items WHERE text_image_split_id = ?')->execute([$splitId]);
        $db->prepare("INSERT INTO text_image_split_items (text_image_split_id, media_id, sort_order, created_at, updated_at) VALUES (?, ?, 0, NOW(), NOW())")->execute([$splitId, $picture]);
        $item = (int) $db->lastInsertId();
        BlockLocalization::save('text_image_split_items', $item, 'nl', ['title' => 'Werkplaats', 'body' => '<p>Tekst</p>']);
        $repository->save('text_image_split_items', $item, TextImageSplitContent::imageSlot(), $zoom);
        $witnesses['Tekst met afbeelding'] = ['zz-zoom-tekst', '/admin/text-image-split.php?section=' . urlencode($splitSection)];

        // Paginakop, beside the text.
        $picture = $this->libraryItem('zz-zoom-kop');
        [$heroId] = $this->block('page_hero');
        $db->prepare("UPDATE page_heroes SET media_id = ?, image_mode = 'right' WHERE id = ?")->execute([$picture, $heroId]);
        $repository->save('page_heroes', $heroId, PageHeroContent::imageSlot(), $zoom);
        $witnesses['Paginakop'] = ['zz-zoom-kop', '/admin/page-hero.php?slug=' . rawurlencode(self::KEY)];

        // Oproep met knop, behind its text.
        $picture = $this->libraryItem('zz-zoom-oproep');
        [$ctaId, $ctaSection] = $this->block('cta_band');
        $db->prepare('UPDATE cta_bands SET background_media_id = ? WHERE id = ?')->execute([$picture, $ctaId]);
        $repository->save('cta_bands', $ctaId, CtaBandContent::backgroundSlot(), new ResponsiveImage(20, 30, zoom: 150));
        $witnesses['Oproep met knop'] = ['zz-zoom-oproep', '/admin/cta-band.php?section=' . urlencode($ctaSection), '/name="background_zoom" min="100" max="200" step="1" value="150"/'];

        // Mediabanner.
        $picture = $this->libraryItem('zz-zoom-banner');
        [$bannerId, $bannerSection] = $this->block('media_banner');
        $db->prepare('UPDATE media_banners SET media_id = ? WHERE id = ?')->execute([$picture, $bannerId]);
        $repository->save('media_banners', $bannerId, MediaBannerContent::imageSlot(), $zoom);
        $witnesses['Mediabanner'] = ['zz-zoom-banner', '/admin/media-banner.php?section=' . urlencode($bannerSection)];

        // Hover kaarten.
        $picture = $this->libraryItem('zz-zoom-hover');
        [$gridId, $gridSection] = $this->block('hover_card_grid');
        $db->prepare('DELETE FROM hover_card_grid_items WHERE hover_card_grid_id = ?')->execute([$gridId]);
        $hover = (new HoverCardGridRepository())->createItem($gridId, ['media_id' => $picture]);
        BlockLocalization::save('hover_card_grid_items', $hover, 'nl', ['title' => 'Hover']);
        $repository->save('hover_card_grid_items', $hover, HoverCardGridContent::imageSlot(), $zoom);
        $witnesses['Hover kaarten'] = ['zz-zoom-hover', '/admin/hover-card-grid.php?section=' . urlencode($gridSection)];

        // Detailsectie.
        $picture = $this->libraryItem('zz-zoom-detail');
        [$detailId, $detailSection] = $this->block('detail_section');
        BlockLocalization::save('detail_sections', $detailId, 'nl', ['title' => 'Detail']);
        $galleryItem = (new DetailSectionRepository())->createImage($detailId, ['media_id' => $picture, 'image_path' => (string) MediaService::find($picture)?->path]);
        $repository->save('detail_section_images', $galleryItem, DetailSectionContent::imageSlot(), $zoom);
        $witnesses['Detailsectie'] = ['zz-zoom-detail', '/admin/detail-section.php?section=' . urlencode($detailSection)];

        $this->clearCaches();
        $html = $this->page();
        foreach ($witnesses as $place => [$file, $editor]) {
            self::assertMatchesRegularExpression('#<img[^>]*src="[^"]*' . preg_quote($file, '#') . '[^"]*"[^>]*style="' . preg_quote($expected, '#') . '"#', $html, $place . ' prints its zoom around its point');

            $screen = self::$server->request('GET', $editor, $session);
            self::assertSame(200, $screen['status'], $place);
            self::assertMatchesRegularExpression($witnesses[$place][2] ?? $slider, $screen['body'], $place . '\'s editor shows the zoom');
        }

        // Homepage Hero, put back as it was afterwards.
        $this->homepageHeroWitness($session, $zoom, $expected);
    }

    // ----------------------------------------------------------- Detailsectie

    public function testAGalleryItemKeepsItsPointAndZoomWhateverItShowsAndWhenThatChanges(): void
    {
        $library = $this->libraryItem('zz-zoom-bieb');
        $productPicture = $this->libraryItem('zz-zoom-product');
        $projectPicture = $this->libraryItem('zz-zoom-project');
        $postPicture = $this->libraryItem('zz-zoom-post');
        $product = $this->shop->product('ZZ Zoom plank');
        (new ProductImageRepository())->addFromMedia($product, $productPicture, (string) MediaService::find($productPicture)?->path);
        $project = $this->project('ZZ Zoom kast', $projectPicture);
        $post = $this->post('ZZ Zoom bericht', $postPicture);
        [$sectionId, $section] = $this->block('detail_section');
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $this->saveGallery($session, $csrf, $section, [
            'new0' => ['present' => '1', 'source' => 'media', 'media_id' => (string) $library, 'image_presentation' => '1', 'image_focus_x' => '10', 'image_focus_y' => '20', 'image_zoom' => '110'],
            'new1' => ['present' => '1', 'source' => 'product', 'source_product' => (string) $product, 'image_presentation' => '1', 'image_focus_x' => '30', 'image_focus_y' => '40', 'image_zoom' => '140'],
            'new2' => ['present' => '1', 'source' => 'portfolio_project', 'source_portfolio_project' => (string) $project, 'image_presentation' => '1', 'image_focus_x' => '50', 'image_focus_y' => '60', 'image_zoom' => '170'],
            'new3' => ['present' => '1', 'source' => 'blog_post', 'source_blog_post' => (string) $post, 'image_presentation' => '1', 'image_focus_x' => '70', 'image_focus_y' => '80', 'image_zoom' => '200'],
        ]);
        $rows = (new DetailSectionRepository())->findImagesBySectionId($sectionId);
        // A library picture is stored as no source kind, as it always was.
        self::assertSame([null, 'product', 'portfolio_project', 'blog_post'], array_column($rows, 'source_type'));
        self::assertSame([110, 140, 170, 200], array_map(static fn (array $row): int => (int) $row['image_zoom'], $rows));
        self::assertSame([null, null, null, null], array_column($rows, 'image_mobile_zoom'), 'no phone part: a phone follows the item');

        $expected = ['zz-zoom-bieb' => '10% 20%; scale: 1.1; transform-origin: 10% 20%', 'zz-zoom-product' => '30% 40%; scale: 1.4; transform-origin: 30% 40%', 'zz-zoom-project' => '50% 60%; scale: 1.7; transform-origin: 50% 60%', 'zz-zoom-post' => '70% 80%; scale: 2; transform-origin: 70% 80%'];
        $html = $this->page();
        foreach ($expected as $file => $style) {
            self::assertMatchesRegularExpression('#<img src="[^"]*' . $file . '-\d+\.jpg"[^>]*style="object-position: ' . $style . ';"#', $html, $file);
        }
        // A linked item is still one link around the zoomed picture.
        self::assertMatchesRegularExpression('#<a class="service-detail__gallery-link" href="[^"]+"[^>]*>\s*<img src="[^"]*zz-zoom-product-\d+\.jpg"[^>]*scale: 1\.4;#', $html);

        // The editor's frames: a linked item shows its library thumbnail.
        $screen = self::$server->request('GET', '/admin/detail-section.php?section=' . urlencode($section), $session)['body'];
        foreach (['zz-zoom-product', 'zz-zoom-project', 'zz-zoom-post'] as $file) {
            self::assertMatchesRegularExpression('#<img src="/assets/media/thumbs/' . $file . '-\d+\.jpg" alt="" draggable="false" loading="lazy" decoding="async" data-rm-preview#', $screen, $file);
        }
        // A zoom is presentation, not a use: a save that only zooms leaves the
        // library's list of uses exactly as it was, and adds no relation.
        $usages = static fn (): array => array_map(
            static fn (array $uses): array => array_map(static fn ($use): string => $use->editUrl, $uses),
            (new ContentBlockMediaUsage())->usagesFor([$library, $productPicture])
        );
        $before = $usages();
        $this->saveGallery($session, $csrf, $section, [
            (string) $rows[0]['id'] => ['present' => '1', 'source' => 'media', 'media_id' => (string) $library, 'image_presentation' => '1', 'image_focus_x' => '10', 'image_focus_y' => '20', 'image_zoom' => '190'],
            (string) $rows[1]['id'] => ['present' => '1', 'source' => 'product', 'source_product' => (string) $product, 'image_presentation' => '1', 'image_focus_x' => '30', 'image_focus_y' => '40', 'image_zoom' => '140'],
            (string) $rows[2]['id'] => ['present' => '1', 'source' => 'portfolio_project', 'source_portfolio_project' => (string) $project, 'image_presentation' => '1', 'image_focus_x' => '50', 'image_focus_y' => '60', 'image_zoom' => '170'],
            (string) $rows[3]['id'] => ['present' => '1', 'source' => 'blog_post', 'source_blog_post' => (string) $post, 'image_presentation' => '1', 'image_focus_x' => '70', 'image_focus_y' => '80', 'image_zoom' => '200'],
        ]);
        self::assertSame($before, $usages());
        self::assertSame(1, count($before[$library] ?? []), 'the gallery item is the one use of the library picture');
        self::assertSame(190, (int) (new DetailSectionRepository())->findImagesBySectionId($sectionId)[0]['image_zoom']);

        $answer = json_decode(self::$server->request('POST', '/api/admin/linked-image-preview.php', $session, [
            'csrf_token' => $csrf, 'section' => $section, 'kind' => 'product', 'id' => (string) $product,
        ])['body'], true);
        self::assertMatchesRegularExpression('#^/assets/media/thumbs/zz-zoom-product-\d+\.jpg$#', (string) ($answer['src'] ?? ''), 'chosen on screen: the thumbnail too');

        // Each item gets another picture: the new one shows, with the same point and zoom.
        $newProduct = $this->libraryItem('zz-zoom-product-nieuw');
        $newProject = $this->libraryItem('zz-zoom-project-nieuw');
        $newPost = $this->libraryItem('zz-zoom-post-nieuw');
        Database::connection()->prepare('DELETE FROM product_images WHERE product_id = :id')->execute(['id' => $product]);
        (new ProductImageRepository())->addFromMedia($product, $newProduct, (string) MediaService::find($newProduct)?->path);
        (new PortfolioGalleryRepository())->updateItem($project, ['media_id' => $newProject, 'image_path' => (string) MediaService::find($newProject)?->path, 'thumbnail_path' => null, 'is_active' => true]);
        Database::connection()->prepare('UPDATE blog_posts SET featured_media_id = :media WHERE id = :id')->execute(['media' => $newPost, 'id' => $post]);
        $this->clearCaches();

        $html = $this->page();
        foreach (['zz-zoom-product-nieuw' => '30% 40%; scale: 1.4', 'zz-zoom-project-nieuw' => '50% 60%; scale: 1.7', 'zz-zoom-post-nieuw' => '70% 80%; scale: 2'] as $file => $style) {
            self::assertMatchesRegularExpression('#<img src="[^"]*' . $file . '-\d+\.jpg"[^>]*style="object-position: ' . $style . ';#', $html, $file);
        }
        self::assertSame([190, 140, 170, 200], array_map(static fn (array $row): int => (int) $row['image_zoom'], (new DetailSectionRepository())->findImagesBySectionId($sectionId)));
    }

    public function testANewRowThatOnlyCarriesItsSlidersIsNoRow(): void
    {
        [$gridId, $section] = $this->block('hover_card_grid');
        $before = count((new HoverCardGridRepository())->findItemsByGridId($gridId));
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        // Exactly what an untouched new card of the screen sends.
        $response = self::$server->request('POST', '/api/admin/update-hover-card-grid.php', $session, [
            'section' => $section, 'language_code' => 'nl', 'eyebrow' => '', 'title' => '', 'lead' => '',
            'layout' => 'overlay', 'shape' => 'rounded', 'columns' => '3', 'overlay' => 'medium', 'effect' => 'normal', 'header_align' => 'left',
            'cards_present' => '1', 'csrf_token' => $csrf,
            'cards' => ['new0' => [
                'present' => '1', 'media_id' => '', 'hover_media_id' => '', 'badge' => '', 'title' => '', 'body' => '',
                'link_type' => 'none', 'link_url' => '', 'link_label' => '',
                'image_presentation' => '1', 'image_focus_x' => '50', 'image_focus_y' => '50', 'image_zoom' => '100', 'image_fit' => 'cover',
                'image_mobile_source' => 'desktop', 'image_mobile_media_id' => '', 'image_mobile_focus_x' => '50', 'image_mobile_focus_y' => '50', 'image_mobile_zoom' => '100', 'image_mobile_fit' => '',
            ]],
        ]);
        HoverCardGridContent::clearCache();

        self::assertStringNotContainsString('error', strtolower($response['location']));
        self::assertCount($before, (new HoverCardGridRepository())->findItemsByGridId($gridId), 'the zoom sliders do not make an empty new card a card');
    }

    // ---------------------------------------------------------------- helpers

    private function homepageHeroWitness(string $session, ResponsiveImage $zoom, string $expected): void
    {
        $repository = new HomepageHeroRepository();
        $row = $repository->findBySlug(HomepageHeroContent::PAGE_SLUG);
        self::assertNotNull($row);
        $picture = $this->libraryItem('zz-zoom-hero');
        $was = ['media_id' => $row['media_id'], 'image_path' => $row['image_path'], 'media_type' => $row['media_type'], 'is_active' => $row['is_active']]
            + ResponsiveImage::fromRow($row, HomepageHeroContent::imageSlot())->toRow(HomepageHeroContent::imageSlot());

        try {
            Database::connection()->prepare("UPDATE homepage_hero SET media_id = ?, image_path = '', media_type = 'image', is_active = 1 WHERE id = ?")->execute([$picture, $row['id']]);
            (new ResponsiveImageRepository())->save('homepage_hero', (int) $row['id'], HomepageHeroContent::imageSlot(), $zoom);
            HomepageHeroContent::clearCache();
            MediaService::clearCache();

            require_once dirname(__DIR__, 2) . '/partials/section-homepage-hero.php';
            ob_start();
            render_section_homepage_hero(HomepageHeroContent::current());
            $html = (string) ob_get_clean();
            self::assertMatchesRegularExpression('#<img src="[^"]*zz-zoom-hero-\d+\.jpg"[^>]*style="' . preg_quote($expected, '#') . '"#', $html, 'Homepage Hero prints its zoom around its point');

            $screen = self::$server->request('GET', '/admin/homepage-hero.php', $session)['body'];
            self::assertMatchesRegularExpression('/name="image_zoom" min="100" max="200" step="1" value="150"/', $screen, 'Homepage Hero\'s editor shows the zoom');
        } finally {
            $columns = array_keys($was);
            Database::connection()->prepare(
                'UPDATE homepage_hero SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $columns)) . ' WHERE id = ?'
            )->execute([...array_values($was), $row['id']]);
            HomepageHeroContent::clearCache();
        }
    }

    /** @return array{0: int, 1: string} the block row's id and its section string */
    private function block(string $type): array
    {
        [$id, $key] = SectionRegistry::create($type, self::KEY);
        (new PageSectionRepository())->create($this->pageId, self::KEY, $type, $key, (int) $id);

        return [(int) $id, self::KEY . ':' . $key];
    }

    private function libraryItem(string $name): int
    {
        $db = Database::connection();
        $db->prepare(
            "INSERT INTO media (path, thumbnail_path, original_filename, display_name, mime_type, width, height, file_size, alt_text, created_at, updated_at)
             VALUES ('assets/media/zz-pending.jpg', NULL, :name, :display, 'image/jpeg', 1600, 900, 100, 'ZZ alt', NOW(), NOW())"
        )->execute(['name' => $name . '.jpg', 'display' => $name . '.jpg']);
        $id = (int) $db->lastInsertId();
        // Names the assertions can find, the thumbnail next to it as the uploader makes one.
        $db->prepare('UPDATE media SET path = ?, thumbnail_path = ? WHERE id = ?')->execute(['assets/media/' . $name . '-' . $id . '.jpg', 'assets/media/thumbs/' . $name . '-' . $id . '.jpg', $id]);
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    private function project(string $title, int $mediaId): int
    {
        $repository = new PortfolioGalleryRepository();
        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], ['media_id' => $mediaId, 'image_path' => (string) MediaService::find($mediaId)?->path, 'thumbnail_path' => null]);
        $this->projectIds[] = $id;
        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [PortfolioLocalization::TITLE => $title]);
        $repository->setItemProjectPage($id, true, 'zz-rm-zoom-' . $id);

        return $id;
    }

    private function post(string $title, int $mediaId): int
    {
        $id = (new BlogPostRepository())->create([
            'slug' => 'zz-rm-zoom-' . bin2hex(random_bytes(3)),
            'featured_media_id' => $mediaId,
            'status' => 'published',
            'published_at' => '2026-01-01 10:00:00',
            'author_name' => 'ZZ',
            'noindex' => 0,
        ]);
        $this->postIds[] = $id;
        BlogLocalization::savePost($id, BlogLocalization::defaultLanguage(), [BlogLocalization::TITLE => $title, BlogLocalization::SLUG => 'zz-rm-zoom-post-' . $id]);

        return $id;
    }

    /**
     * One card as its editor posts it: the presentation as the screen prints
     * it unless $presentation says otherwise.
     *
     * @param array<string, string> $presentation
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function saveCard(string $session, string $csrf, int $cardId, int $mediaId, array $presentation): array
    {
        $response = self::$server->request('POST', '/api/admin/update-carousel-card.php', $session, $presentation + [
            'csrf_token' => $csrf,
            'card_id' => (string) $cardId,
            'language_code' => 'nl',
            'is_active' => '1',
            'title' => 'Hout',
            'media_id' => (string) $mediaId,
            'image_alt' => '',
            'link_type' => 'none',
            'tags_present' => '1',
            'image_presentation' => '1',
            'image_focus_x' => '50',
            'image_focus_y' => '50',
            'image_zoom' => '100',
            'image_fit' => 'cover',
            'image_mobile_source' => 'desktop',
            'image_mobile_media_id' => '',
            'image_mobile_focus_x' => '50',
            'image_mobile_focus_y' => '50',
            'image_mobile_zoom' => '100',
            'image_mobile_fit' => '',
        ]);
        $this->clearCaches();

        return $response;
    }

    /** @param array<string, array<string, string>> $images */
    private function saveGallery(string $session, string $csrf, string $section, array $images): void
    {
        $response = self::$server->request('POST', '/api/admin/update-detail-section.php', $session, [
            'csrf_token' => $csrf,
            'section' => $section,
            'language_code' => PageLocalization::defaultLanguage(),
            'title' => 'ZZ Zoom',
            'is_active' => '1',
            'image_position' => 'image_right',
            'images_present' => '1',
            'images' => $images,
        ]);
        $this->assertSaved($response);
        $this->clearCaches();
    }

    private function page(): string
    {
        $this->clearCaches();
        \App\Service\PageAssets::reset();
        ob_start();
        SectionRegistry::collectPageAssets(self::KEY);
        SectionRegistry::renderPage(self::KEY);

        return (string) ob_get_clean();
    }

    /** @param array{location: string} $response */
    private function assertSaved(array $response): void
    {
        self::assertStringContainsString('saved=1', $response['location'], $response['location']);
    }

    private function clearCaches(): void
    {
        BlockLocalization::clearCache();
        CardCarouselContent::clearCache();
        HoverCardGridContent::clearCache();
        HomepageHeroContent::clearCache();
        PageContent::clearCache();
        MediaService::clearCache();
        PageLocalization::clearCache();
        DetailSectionContent::clearCache();
        PageHeroContent::clearCache();
        \App\Service\PortfolioGalleryContent::clearCache();
        \App\Service\ShopLocalization::clearCache();
        LinkTargets::reset();
    }

    private function removePage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::KEY);
        if ($page !== null) {
            PageService::delete($page);
        }
        PageContent::clearCache();
    }
}
