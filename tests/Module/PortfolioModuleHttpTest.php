<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Database;
use App\Module\PortfolioModule;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\AdminPermissions;
use App\Service\PageContent;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioImageProcessor;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The Portfolio switched on and off, over real HTTP: the sidebar, both admin
 * screens, a write endpoint, the public routes, a gallery block on another
 * page, the sitemap — and the data that must still be there when the module
 * comes back.
 *
 * Which modules run is read from the environment a web server was STARTED
 * with (MODULES.md). So for as long as this class runs it starts two of PHP's
 * own built-in servers on this checkout, both on the test database
 * (Tests\Support\BuiltInServer): one with MODULE_PORTFOLIO_ENABLED=true and
 * one with =false. Switching the module off is then asking the other server,
 * which is exactly what a deploy with a different .env does. The registry
 * half of the same behaviour is Tests\Module\PortfolioModuleTest.
 *
 * Everything it creates — accounts, sessions, categories, items with their
 * uploaded images, pages — is its own, is marked zz-, and is removed again in
 * tearDown(). When a server cannot be started the test skips itself, like the
 * HTTP tier does (TESTING.md).
 */
final class PortfolioModuleHttpTest extends TestCase
{
    private static ?BuiltInServer $on = null;

    private static ?BuiltInServer $off = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<string> */
    private array $categoryNames = [];

    /** @var list<int> */
    private array $pageIds = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    public static function setUpBeforeClass(): void
    {
        self::$on = BuiltInServer::start(['MODULE_PORTFOLIO_ENABLED' => 'true']);
        self::$off = BuiltInServer::start(['MODULE_PORTFOLIO_ENABLED' => 'false']);
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
        $this->accounts = new AdminTestSession();

        if (self::$on === null || self::$off === null || !self::$on->answers() || !self::$off->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
    }

    protected function tearDown(): void
    {
        $gallery = new PortfolioGalleryRepository();
        $processor = new PortfolioImageProcessor();

        foreach ($this->itemIds as $id) {
            $item = $gallery->findItemById($id);
            if ($item === null) {
                continue;
            }

            // Its category links go with the row (ON DELETE CASCADE); its files
            // only go through the processor — the order the delete endpoint uses.
            $gallery->deleteItem($id);
            $processor->delete((string) $item['image_path'], $item['thumbnail_path'] ?? null);
        }

        $categories = new PortfolioCategoryRepository();
        foreach ($categories->findAll() as $category) {
            if (in_array((string) $category['name_nl'], $this->categoryNames, true)) {
                $categories->delete((int) $category['id']);
            }
        }

        $pages = new PageRepository();
        $sections = new PageSectionRepository();
        foreach ($this->pageIds as $id) {
            foreach ($sections->findForPage($id) as $section) {
                SectionRegistry::delete($section, $sections);
            }

            $pages->delete($id);
        }

        $this->accounts->forget();

        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->itemIds = [];
        $this->categoryNames = [];
        $this->pageIds = [];
        $this->temporaryFiles = [];

        PortfolioGalleryContent::clearCache();
        PageContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* The admin                                                           */
    /* ------------------------------------------------------------------ */

    public function testTheSidebarOnlyShowsThePortfolioWhileTheModuleRuns(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::DASHBOARD_VIEW, PortfolioModule::PORTFOLIO_MANAGE]);

        $on = self::$on->request('GET', '/admin/index.php', $session);
        $this->assertSame(200, $on['status']);
        $this->assertStringContainsString('href="/admin/portfolio.php"', $on['body']);

        $off = self::$off->request('GET', '/admin/index.php', $session);
        $this->assertSame(200, $off['status']);
        $this->assertStringNotContainsString('href="/admin/portfolio.php"', $off['body']);
    }

    /**
     * A hidden menu entry is not a guard: both screens answer a URL typed by
     * hand with the CMS's own "no access" page — for a Super Admin as well,
     * because nobody holds a disabled module's permission.
     */
    public function testTheAdminScreensAreClosedWhileTheModuleIsOff(): void
    {
        [$editor] = $this->accounts->signIn([AdminPermissions::DASHBOARD_VIEW, PortfolioModule::PORTFOLIO_MANAGE]);
        [$owner] = $this->accounts->signIn([], true);

        foreach (['/admin/portfolio.php', '/admin/portfolio-item.php'] as $path) {
            foreach ([$editor, $owner] as $session) {
                $off = self::$off->request('GET', $path, $session);

                $this->assertSame(403, $off['status'], $path . ' must be closed while the Portfolio is off');
                $this->assertStringNotContainsString('create-portfolio-item.php', $off['body']);
                $this->assertStringNotContainsString('data-portfolio-toolbar', $off['body']);
            }

            $this->assertSame(200, self::$on->request('GET', $path, $editor)['status'], $path . ' must open while it runs');
        }
    }

    /**
     * The refusal comes from the permission guard — before CSRF, before the
     * input is read — and nothing is stored. The same request succeeds the
     * moment the module runs.
     */
    public function testAWriteEndpointRefusesBeforeItStoresAnythingWhileTheModuleIsOff(): void
    {
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);
        $name = $this->categoryName();
        $fields = ['csrf_token' => $csrf, 'name_nl' => $name];

        $off = self::$off->request('POST', '/api/admin/create-portfolio-category.php', $session, $fields);

        $this->assertSame(403, $off['status']);
        $this->assertStringContainsString('missing permission', $off['body']);
        $this->assertNull($this->categoryNamed($name), 'a refused write stores nothing');

        $on = self::$on->request('POST', '/api/admin/create-portfolio-category.php', $session, $fields);

        $this->assertSame(302, $on['status']);
        $this->assertSame('/admin/portfolio.php?category_saved=1', $on['location']);
        $this->assertNotNull($this->categoryNamed($name));
    }

    /* ------------------------------------------------------------------ */
    /* The public side                                                     */
    /* ------------------------------------------------------------------ */

    public function testAProjectPageIsOnlyPublicWhileTheModuleRuns(): void
    {
        $item = $this->item(projectPage: true);
        $path = '/portfolio-detail.php?slug=' . $item['slug'];

        $on = self::$on->request('GET', $path);
        $this->assertSame(200, $on['status']);
        $this->assertStringContainsString((string) $item['title_nl'], $on['body']);

        $off = self::$off->request('GET', $path);
        $this->assertSame(404, $off['status']);
        $this->assertStringContainsString('Pagina niet gevonden', $off['body'], "the site's own 404, as for a page that never existed");
        $this->assertStringNotContainsString((string) $item['title_nl'], $off['body']);
    }

    public function testThePortfolioPageIsClosedWhileTheModuleIsOff(): void
    {
        $page = $this->portfolioPage();

        $this->assertSame(404, self::$off->request('GET', '/portfolio.php')['status']);
        $this->assertSame(
            PageContent::isPublished($page) ? 200 : 404,
            self::$on->request('GET', '/portfolio.php')['status'],
            'with the module on, the page answers as its own status says'
        );
    }

    /**
     * A gallery block on another page keeps its settings while the module is
     * off and simply shows nothing — never another source's content, never an
     * error — and shows the items again when the module is back.
     */
    public function testAGalleryBlockGoesQuietWhileTheModuleIsOffAndKeepsItsSettings(): void
    {
        $item = $this->item(projectPage: false);
        $page = $this->pageWithAGalleryBlock();
        $path = '/pagina.php?slug=' . $page['slug'];

        $on = self::$on->request('GET', $path);
        $this->assertSame(200, $on['status']);
        $this->assertStringContainsString('data-gallery-block', $on['body']);
        $this->assertStringContainsString((string) $item['title_nl'], $on['body']);

        $off = self::$off->request('GET', $path);
        $this->assertSame(200, $off['status'], 'the page itself is Core and keeps answering');
        $this->assertStringNotContainsString('data-gallery-block', $off['body']);
        $this->assertStringNotContainsString((string) $item['title_nl'], $off['body']);

        $stored = Database::connection()->prepare('SELECT source_type FROM item_galleries WHERE page_slug = :slug');
        $stored->execute(['slug' => $page['content_key']]);
        $this->assertSame(PortfolioModule::GALLERY_SOURCE, $stored->fetchColumn(), 'the block still names its source');
    }

    public function testTheSitemapLeavesThePortfolioOutWhileTheModuleIsOff(): void
    {
        $item = $this->item(projectPage: true);
        $page = $this->portfolioPage();
        $projectLoc = '/portfolio/' . $item['slug'] . '</loc>';

        $on = self::$on->request('GET', '/sitemap.php')['body'];
        $this->assertStringContainsString($projectLoc, $on);
        if (PageContent::isPublished($page)) {
            $this->assertStringContainsString('/portfolio.php</loc>', $on);
        }

        $off = self::$off->request('GET', '/sitemap.php')['body'];
        $this->assertStringContainsString('<urlset', $off, 'the sitemap itself still answers');
        $this->assertStringNotContainsString($projectLoc, $off);
        $this->assertStringNotContainsString('/portfolio.php</loc>', $off);
    }

    /* ------------------------------------------------------------------ */
    /* Data retention                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * On: a category and an item, saved through the real endpoints with a real
     * upload. Off: closed everywhere, and not one row or file touched. On
     * again: exactly what was there.
     */
    public function testPortfolioDataSurvivesTheModuleBeingSwitchedOffAndOnAgain(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::DASHBOARD_VIEW, PortfolioModule::PORTFOLIO_MANAGE]);
        $categoryName = $this->categoryName();
        $title = 'ZZ Bewaard werk ' . bin2hex(random_bytes(3));

        $this->assertSame(302, self::$on->request('POST', '/api/admin/create-portfolio-category.php', $session, [
            'csrf_token' => $csrf,
            'name_nl' => $categoryName,
        ])['status']);
        $category = $this->categoryNamed($categoryName);
        $this->assertNotNull($category);

        $created = self::$on->request('POST', '/api/admin/create-portfolio-item.php', $session, [
            'csrf_token' => $csrf,
            'title_nl' => $title,
            'alt_nl' => 'Een testafbeelding',
            'subtitle_nl' => 'Een bewaard onderschrift',
            'categories[0]' => (string) $category['id'],
        ], ['image' => $this->uploadableImage()]);

        $this->assertSame(302, $created['status']);
        $this->assertMatchesRegularExpression('#^/admin/portfolio-item\.php\?id=\d+&created=1$#', $created['location']);
        preg_match('#id=(\d+)#', $created['location'], $match);
        $itemId = (int) $match[1];
        $this->itemIds[] = $itemId;

        $before = $this->snapshot($itemId);
        $this->assertSame([(int) $category['id']], $before['categories']);
        $this->assertTrue($before['image_on_disk'], 'the upload must really be stored');

        // Off: closed, and nothing is gone.
        $this->assertSame(403, self::$off->request('GET', '/admin/portfolio.php', $session)['status']);
        $this->assertSame(403, self::$off->request('GET', '/admin/portfolio-item.php?id=' . $itemId, $session)['status']);
        $this->assertSame($before, $this->snapshot($itemId), 'switching the module off must not touch a row or a file');
        $this->assertNotNull($this->categoryNamed($categoryName));

        // On again: the item and its category are back, exactly as they were.
        $overview = self::$on->request('GET', '/admin/portfolio.php', $session);
        $this->assertSame(200, $overview['status']);
        $this->assertStringContainsString($title, $overview['body']);
        $this->assertStringContainsString($categoryName, $overview['body']);

        $editor = self::$on->request('GET', '/admin/portfolio-item.php?id=' . $itemId, $session);
        $this->assertSame(200, $editor['status']);
        $this->assertStringContainsString($title, $editor['body']);

        $this->assertSame($before, $this->snapshot($itemId));
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed> the stored item row
     */
    private function item(bool $projectPage): array
    {
        $repository = new PortfolioGalleryRepository();
        $galleryId = (int) $repository->ensureCatalogue()['id'];
        $marker = bin2hex(random_bytes(3));

        $values = [
            'image_path' => 'assets/images/sections/zz-portfoliotest-' . $marker . '.jpg',
            'thumbnail_path' => null,
            'alt_nl' => 'ZZ alt ' . $marker,
            'alt_en' => null,
            'title_nl' => 'ZZ Portfoliotest ' . $marker,
            'title_en' => null,
            'subtitle_nl' => 'ZZ onderschrift ' . $marker,
            'subtitle_en' => null,
        ];

        $id = $repository->createItem($galleryId, $values);
        $this->itemIds[] = $id;

        if ($projectPage) {
            // The old project page. Nothing in the application writes its
            // columns any more, so the fixture sets them directly, the way
            // portfolioPage() below sets a fixed route.
            Database::connection()
                ->prepare('UPDATE portfolio_gallery_items SET has_detail_page = 1, slug = :slug WHERE id = :id')
                ->execute(['slug' => 'zz-portfoliotest-' . $marker, 'id' => $id]);
        }

        PortfolioGalleryContent::clearCache();

        return (array) $repository->findItemById($id);
    }

    /**
     * The page /portfolio.php serves. A test database copied from a real site
     * has one, and it is left exactly as it is; otherwise this test makes one
     * of its own, bound to that template like the historical row.
     *
     * @return array<string, mixed>
     */
    private function portfolioPage(): array
    {
        $pages = new PageRepository();
        $existing = $pages->findByContentKey('portfolio');

        if ($existing !== null) {
            return $existing;
        }

        $marker = bin2hex(random_bytes(3));
        $id = $pages->create([
            'content_key' => 'portfolio',
            'slug' => 'zz-portfolio-' . $marker,
            'title' => 'ZZ Portfolio ' . $marker,
            'status' => PageContent::STATUS_PUBLISHED,
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);
        $this->pageIds[] = $id;

        // PageRepository::create() never writes a fixed route — only the
        // migrations do. This fixture needs one, so it sets it the same way.
        Database::connection()
            ->prepare("UPDATE pages SET is_system = 1, route_path = '/portfolio.php' WHERE id = :id")
            ->execute(['id' => $id]);

        PageContent::clearCache();

        return (array) $pages->findById($id);
    }

    /**
     * A published page of this test's own with one gallery block on it, added
     * the way the page builder adds one: with the block's default source,
     * which with the module on in this process is portfolio items.
     *
     * @return array<string, mixed>
     */
    private function pageWithAGalleryBlock(): array
    {
        $key = 'zz-galerij-' . bin2hex(random_bytes(4));
        $pages = new PageRepository();

        $id = $pages->create([
            'content_key' => $key,
            'slug' => $key,
            'title' => 'ZZ Galerijtest',
            'status' => PageContent::STATUS_PUBLISHED,
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);
        $this->pageIds[] = $id;

        [$sectionId, $sectionKey] = SectionRegistry::create('item_gallery', $key);
        (new PageSectionRepository())->create($id, $key, 'item_gallery', $sectionKey, $sectionId);

        PageContent::clearCache();

        return (array) $pages->findById($id);
    }

    private function categoryName(): string
    {
        $name = 'zz-portfoliotest-' . bin2hex(random_bytes(3));
        $this->categoryNames[] = $name;

        return $name;
    }

    /** @return array<string, mixed>|null */
    private function categoryNamed(string $name): ?array
    {
        foreach ((new PortfolioCategoryRepository())->findAll() as $category) {
            if ((string) $category['name_nl'] === $name) {
                return $category;
            }
        }

        return null;
    }

    /** A small, real PNG, sent the way a browser sends a chosen file. */
    private function uploadableImage(): \CURLFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'zzportfolio');
        $this->temporaryFiles[] = $path;

        $image = imagecreatetruecolor(64, 48);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 120, 200));
        imagepng($image, $path);
        imagedestroy($image);

        return new \CURLFile($path, 'image/png', 'werk.png');
    }

    /**
     * @return array{item: array<string, mixed>, categories: list<int>, image_on_disk: bool, thumbnail_on_disk: bool}
     */
    private function snapshot(int $itemId): array
    {
        $repository = new PortfolioGalleryRepository();
        $item = $repository->findItemById($itemId);
        $this->assertNotNull($item, 'the item must still exist');

        $root = dirname(__DIR__, 2) . '/';

        return [
            'item' => $item,
            'categories' => $repository->categoryIdsForItem($itemId),
            'image_on_disk' => is_file($root . $item['image_path']),
            'thumbnail_on_disk' => is_file($root . (string) $item['thumbnail_path']),
        ];
    }
}
