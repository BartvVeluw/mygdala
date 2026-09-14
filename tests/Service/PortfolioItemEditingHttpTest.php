<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\PortfolioModule;
use App\Repository\PageRepository;
use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\AdminPermissions;
use App\Service\Language\AdminTranslator;
use App\Service\PageContent;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioImageProcessor;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Creating and editing a portfolio item through the real endpoints: an image
 * is enough, every word and every category is optional, what an editor does
 * fill in comes back exactly as typed, and the project page is one choice of
 * an ordinary page that leaves the old project page alone.
 *
 * Over real HTTP against PHP's built-in server with the Portfolio switched on
 * (Tests\Support\BuiltInServer), because an endpoint's answer is its redirect
 * and its session flash, and an upload is a real multipart request. The items,
 * categories, pages, files and accounts it makes are its own and are removed
 * again in tearDown(). When the server cannot be started the test skips
 * itself, like the HTTP tier does (TESTING.md).
 */
final class PortfolioItemEditingHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $categoryIds = [];

    /** @var list<int> */
    private array $pageIds = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_PORTFOLIO_ENABLED' => 'true']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        $this->accounts = new AdminTestSession();

        if (self::$server === null || !self::$server->answers()) {
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

            $gallery->deleteItem($id);
            $processor->delete((string) $item['image_path'], $item['thumbnail_path'] ?? null);
        }

        $categories = new PortfolioCategoryRepository();
        foreach ($this->categoryIds as $id) {
            $categories->delete($id);
        }

        $pages = new PageRepository();
        foreach ($this->pageIds as $id) {
            if ($pages->findById($id) !== null) {
                $pages->delete($id);
            }
        }

        $this->accounts->forget();

        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->itemIds = [];
        $this->categoryIds = [];
        $this->pageIds = [];
        $this->temporaryFiles = [];

        PortfolioGalleryContent::clearCache();
        PageContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Creating                                                            */
    /* ------------------------------------------------------------------ */

    public function testAnImageAloneIsAPortfolioItem(): void
    {
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        $response = self::$server->request(
            'POST',
            '/api/admin/create-portfolio-item.php',
            $session,
            ['csrf_token' => $csrf],
            ['image' => $this->uploadableImage()]
        );

        $itemId = $this->createdItemId($response);
        $item = (new PortfolioGalleryRepository())->findItemById($itemId);
        $this->assertNotNull($item);

        foreach (['title_nl', 'alt_nl', 'subtitle_nl'] as $column) {
            $this->assertSame('', $item[$column], $column . ' may be left empty');
        }

        foreach (['title_en', 'alt_en', 'subtitle_en'] as $column) {
            $this->assertNull($item[$column], $column . ' stays untranslated');
        }

        $this->assertSame([], (new PortfolioGalleryRepository())->categoryIdsForItem($itemId), 'no category is a valid choice');
        $this->assertNull($item['page_id'], 'and so is no page');
        $this->assertFileExists(dirname(__DIR__, 2) . '/' . $item['image_path']);
        $this->assertNull($this->accounts->read($session, 'admin_portfolio_item_errors'));
    }

    public function testEverythingAnEditorFillsInIsStoredAsTyped(): void
    {
        $category = $this->category();
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        $words = [
            'title_nl' => 'ZZ Snijplank',
            'title_en' => 'ZZ Cutting board',
            'alt_nl' => 'Houten snijplank met een gegraveerde naam',
            'alt_en' => 'Wooden cutting board with an engraved name',
            'subtitle_nl' => 'Cadeau, hout',
            'subtitle_en' => 'Gift, wood',
        ];

        $response = self::$server->request(
            'POST',
            '/api/admin/create-portfolio-item.php',
            $session,
            ['csrf_token' => $csrf, 'categories[0]' => (string) $category['id']] + $words,
            ['image' => $this->uploadableImage()]
        );

        $itemId = $this->createdItemId($response);
        $repository = new PortfolioGalleryRepository();
        $item = (array) $repository->findItemById($itemId);

        foreach ($words as $column => $typed) {
            $this->assertSame($typed, $item[$column], $column);
        }

        $this->assertSame([(int) $category['id']], $repository->categoryIdsForItem($itemId));
    }

    /** No image, no item — and the editor is told why. */
    public function testWithoutAnImageThereIsNoItem(): void
    {
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);
        $before = $this->itemCount();

        $response = self::$server->request('POST', '/api/admin/create-portfolio-item.php', $session, [
            'csrf_token' => $csrf,
            'title_nl' => 'ZZ Zonder afbeelding',
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/admin/portfolio-item.php', $response['location']);
        $this->assertSame($before, $this->itemCount());
        $this->assertNotEmpty($this->accounts->read($session, 'admin_portfolio_item_errors'));
    }

    /* ------------------------------------------------------------------ */
    /* Editing                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * An item that had words and a category can lose all of them: the save is
     * valid, the words are stored empty, and the category itself stays in the
     * list for other items.
     */
    public function testTheWordsCanBeEmptiedAndTheCategoryTakenOffAgain(): void
    {
        $category = $this->category();
        $itemId = $this->storedItem('ZZ Met alles', [(int) $category['id']]);
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        $response = self::$server->request('POST', '/api/admin/update-portfolio-item.php', $session, [
            'csrf_token' => $csrf,
            'item_id' => (string) $itemId,
            'title_nl' => '',
            'alt_nl' => '',
            'subtitle_nl' => '',
            'is_active' => '1',
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/admin/portfolio-item.php?id=' . $itemId . '&updated=1', $response['location']);

        $repository = new PortfolioGalleryRepository();
        $item = (array) $repository->findItemById($itemId);

        foreach (['title_nl', 'alt_nl', 'subtitle_nl'] as $column) {
            $this->assertSame('', $item[$column], $column);
        }

        $this->assertSame([], $repository->categoryIdsForItem($itemId));
        $this->assertNotNull((new PortfolioCategoryRepository())->findById((int) $category['id']), 'the category itself stays');
    }

    /** An item without a title still has a name in the CMS, never an empty card. */
    public function testAnUntitledItemIsNamedInTheCms(): void
    {
        $itemId = $this->storedItem('', []);
        [$session] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);
        $untitled = AdminTranslator::trans('portfolio.untitled');

        $overview = self::$server->request('GET', '/admin/portfolio.php', $session);
        $this->assertSame(200, $overview['status']);
        $this->assertMatchesRegularExpression(
            '#data-id="' . $itemId . '"[\s\S]*?' . preg_quote($untitled, '#') . '#',
            $overview['body']
        );

        $editor = self::$server->request('GET', '/admin/portfolio-item.php?id=' . $itemId, $session);
        $this->assertSame(200, $editor['status']);
        $this->assertStringContainsString('<h1>' . $untitled . '</h1>', $editor['body']);
    }

    /**
     * The editor's preview starts on the stored image, in the HTML itself — so
     * also without the script — and a new item's form has nothing to show yet.
     */
    public function testTheEditorPreviewStartsOnTheStoredImage(): void
    {
        $itemId = $this->storedItem('ZZ Werk', []);
        $imageSrc = '/' . (string) (new PortfolioGalleryRepository())->findItemById($itemId)['image_path'];
        [$session] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        $editor = self::$server->request('GET', '/admin/portfolio-item.php?id=' . $itemId, $session);
        $this->assertSame(200, $editor['status']);
        $this->assertStringContainsString(
            'data-admin-file-preview="portfolio-image" data-admin-file-preview-current="' . $imageSrc . '"',
            $editor['body']
        );
        $this->assertStringContainsString('src="' . $imageSrc . '" data-admin-file-preview-image', $editor['body']);

        $new = self::$server->request('GET', '/admin/portfolio-item.php', $session);
        $this->assertSame(200, $new['status']);
        $this->assertStringContainsString('data-admin-file-preview="portfolio-image" hidden', $new['body']);
    }

    /* ------------------------------------------------------------------ */
    /* The project page                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Choosing a page stores its id, and only its id: the old project page's
     * columns keep every value, because nothing on this form reaches them.
     */
    public function testChoosingAPageStoresItsIdAndLeavesTheOldProjectPageAlone(): void
    {
        $itemId = $this->storedItem('ZZ Project', []);
        $this->giveItAnOldProjectPage($itemId);
        $pageId = $this->page(PageContent::STATUS_PUBLISHED);
        $repository = new PortfolioGalleryRepository();
        $before = (array) $repository->findItemById($itemId);
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        $response = self::$server->request('POST', '/api/admin/update-portfolio-item.php', $session, [
            'csrf_token' => $csrf,
            'item_id' => (string) $itemId,
            'title_nl' => 'ZZ Project',
            'is_active' => '1',
            'page_id' => (string) $pageId,
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/admin/portfolio-item.php?id=' . $itemId . '&updated=1', $response['location']);

        $after = (array) $repository->findItemById($itemId);
        $this->assertSame($pageId, (int) $after['page_id']);

        foreach (['has_detail_page', 'slug', 'intro_nl', 'intro_en', 'description_nl', 'description_en'] as $column) {
            $this->assertSame($before[$column], $after[$column], $column . ' belongs to the old project page and is left alone');
        }
    }

    /** "Geen gekoppelde pagina" is a valid answer: the link goes, the page stays. */
    public function testNoPageIsAValidChoiceAndOnlyTakesTheLinkOff(): void
    {
        $itemId = $this->storedItem('ZZ Project', []);
        $pageId = $this->page(PageContent::STATUS_PUBLISHED);
        $repository = new PortfolioGalleryRepository();
        $repository->setItemPage($itemId, $pageId);
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        $response = self::$server->request('POST', '/api/admin/update-portfolio-item.php', $session, [
            'csrf_token' => $csrf,
            'item_id' => (string) $itemId,
            'title_nl' => 'ZZ Project',
            'is_active' => '1',
            'page_id' => '',
        ]);

        $this->assertSame('/admin/portfolio-item.php?id=' . $itemId . '&updated=1', $response['location']);
        $this->assertNull($repository->findItemById($itemId)['page_id']);
        $this->assertNotNull((new PageRepository())->findById($pageId), 'taking the link off never touches the page');
        $this->assertNull($this->accounts->read($session, 'admin_portfolio_item_errors'));
    }

    /**
     * A page the item may not link to — one that does not exist (any more), one
     * with a template of its own, or no id at all — is refused, and the refused
     * save writes nothing: not the link, not a word.
     */
    public function testAPageThatCannotBeTheProjectPageIsRefusedAndNothingIsSaved(): void
    {
        $itemId = $this->storedItem('ZZ Onveranderd', []);
        $pageId = $this->page(PageContent::STATUS_PUBLISHED);
        $repository = new PortfolioGalleryRepository();
        $repository->setItemPage($itemId, $pageId);
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        $missing = (int) Database::connection()->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM pages')->fetchColumn();

        foreach ([(string) $missing, (string) $this->templatePage(), 'geen-id'] as $choice) {
            $response = self::$server->request('POST', '/api/admin/update-portfolio-item.php', $session, [
                'csrf_token' => $csrf,
                'item_id' => (string) $itemId,
                'title_nl' => 'ZZ Gewijzigd',
                'is_active' => '1',
                'page_id' => $choice,
            ]);

            $this->assertSame('/admin/portfolio-item.php?id=' . $itemId, $response['location'], $choice . ' must be refused');
            $this->assertSame(
                [AdminTranslator::trans('validation.portfolio_page_unknown')],
                $this->accounts->read($session, 'admin_portfolio_item_errors')
            );

            $item = (array) $repository->findItemById($itemId);
            $this->assertSame($pageId, (int) $item['page_id'], 'the link the item had is kept');
            $this->assertSame('ZZ Onveranderd', $item['title_nl'], 'a refused save writes nothing');
        }
    }

    /**
     * The editor offers the site's ordinary pages — a draft marked as one —
     * with the linked page selected, and not a page with a template of its own.
     * "Nieuwe pagina maken" is only there for an editor who may make one.
     */
    public function testTheEditorOffersOrdinaryPagesWithTheLinkedOneSelected(): void
    {
        $itemId = $this->storedItem('ZZ Project', []);
        $draftId = $this->page(PageContent::STATUS_DRAFT);
        $templatePageId = $this->templatePage();
        (new PortfolioGalleryRepository())->setItemPage($itemId, $draftId);
        $draft = (array) (new PageRepository())->findById($draftId);

        [$portfolioOnly] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);
        $editor = self::$server->request('GET', '/admin/portfolio-item.php?id=' . $itemId, $portfolioOnly);

        $this->assertSame(200, $editor['status']);
        $this->assertStringContainsString('<select class="admin-select" id="portfolio-page" name="page_id">', $editor['body']);
        $this->assertStringContainsString(
            '<option value="">' . htmlspecialchars(AdminTranslator::trans('portfolio.no_linked_page'), ENT_QUOTES, 'UTF-8') . '</option>',
            $editor['body']
        );
        $this->assertStringContainsString(
            '<option value="' . $draftId . '" selected>'
                . htmlspecialchars(AdminTranslator::trans('portfolio.page_option_draft', ['title' => (string) $draft['title']]), ENT_QUOTES, 'UTF-8')
                . '</option>',
            $editor['body']
        );
        $this->assertStringNotContainsString('<option value="' . $templatePageId . '"', $editor['body'], 'a page with a template of its own is no project page');

        foreach (['has_detail_page', 'slug', 'intro_nl', 'description_nl'] as $name) {
            $this->assertStringNotContainsString('name="' . $name . '"', $editor['body'], $name . ' belonged to the old project page');
        }

        $this->assertStringNotContainsString('href="/admin/page-new.php"', $editor['body'], 'no page to make without pages.manage');

        [$pageEditor] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE, AdminPermissions::PAGES_MANAGE]);
        $withPages = self::$server->request('GET', '/admin/portfolio-item.php?id=' . $itemId, $pageEditor);

        $this->assertSame(200, $withPages['status']);
        $this->assertStringContainsString('href="/admin/page-new.php"', $withPages['body']);
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function category(): array
    {
        $repository = new PortfolioCategoryRepository();
        $marker = bin2hex(random_bytes(3));
        $id = $repository->create('ZZ Categorie ' . $marker, null, 'zz-categorie-' . $marker);
        $this->categoryIds[] = $id;

        return (array) $repository->findById($id);
    }

    /** @param list<int> $categoryIds */
    private function storedItem(string $title, array $categoryIds): int
    {
        $repository = new PortfolioGalleryRepository();
        $marker = bin2hex(random_bytes(3));

        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-portfoliotest-' . $marker . '.jpg',
            'thumbnail_path' => null,
            'alt_nl' => $title === '' ? '' : 'ZZ alt ' . $marker,
            'alt_en' => null,
            'title_nl' => $title,
            'title_en' => null,
            'subtitle_nl' => $title === '' ? '' : 'ZZ onderschrift ' . $marker,
            'subtitle_en' => null,
        ]);
        $this->itemIds[] = $id;
        $repository->setItemCategories($id, $categoryIds);

        return $id;
    }

    /**
     * The old project page's columns, filled the way its editor filled them.
     * Nothing in the application writes them any more, so the fixture does it
     * directly.
     */
    private function giveItAnOldProjectPage(int $itemId): void
    {
        Database::connection()
            ->prepare(
                "UPDATE portfolio_gallery_items
                    SET has_detail_page = 1, slug = :slug, intro_nl = '<p>ZZ oude intro</p>', description_nl = '<p>ZZ oude beschrijving</p>'
                  WHERE id = :id"
            )
            ->execute(['slug' => 'zz-oud-project-' . bin2hex(random_bytes(4)), 'id' => $itemId]);
    }

    private function page(string $status): int
    {
        $key = 'zz-projectpagina-' . bin2hex(random_bytes(4));

        $id = (new PageRepository())->create([
            'content_key' => $key,
            'slug' => $key,
            'title' => 'ZZ Projectpagina ' . $key,
            'status' => $status,
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);
        $this->pageIds[] = $id;

        return $id;
    }

    /**
     * A page with a template of its own at a fixed address. Only migrations
     * make one, so the fixture sets it the way Tests\Module\PortfolioModuleHttpTest
     * sets the Portfolio page's route.
     */
    private function templatePage(): int
    {
        $id = $this->page(PageContent::STATUS_PUBLISHED);

        Database::connection()
            ->prepare('UPDATE pages SET is_system = 1, route_path = :route WHERE id = :id')
            ->execute(['route' => '/zz-vaste-route-' . $id . '.php', 'id' => $id]);

        return $id;
    }

    /** @param array{status: int, location: string, body: string} $response */
    private function createdItemId(array $response): int
    {
        $this->assertSame(302, $response['status']);
        $this->assertMatchesRegularExpression('#^/admin/portfolio-item\.php\?id=\d+&created=1$#', $response['location']);

        preg_match('#id=(\d+)#', $response['location'], $match);
        $id = (int) $match[1];
        $this->itemIds[] = $id;

        return $id;
    }

    private function itemCount(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM portfolio_gallery_items')->fetchColumn();
    }

    /** A small, real PNG, sent the way a browser sends a chosen file. */
    private function uploadableImage(): \CURLFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'zzportfolio');
        $this->temporaryFiles[] = $path;

        $image = imagecreatetruecolor(64, 48);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 120, 40));
        imagepng($image, $path);
        imagedestroy($image);

        return new \CURLFile($path, 'image/png', 'werk.png');
    }
}
