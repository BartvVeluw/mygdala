<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\PortfolioModule;
use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\Language\AdminTranslator;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioImageProcessor;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Creating and editing a portfolio item through the real endpoints: an image
 * is enough, every word and every category is optional, and what an editor
 * does fill in comes back exactly as typed.
 *
 * Over real HTTP against PHP's built-in server with the Portfolio switched on
 * (Tests\Support\BuiltInServer), because an endpoint's answer is its redirect
 * and its session flash, and an upload is a real multipart request. The items,
 * categories, files and accounts it makes are its own and are removed again in
 * tearDown(). When the server cannot be started the test skips itself, like
 * the HTTP tier does (TESTING.md).
 */
final class PortfolioItemEditingHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $categoryIds = [];

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

        $this->accounts->forget();

        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->itemIds = [];
        $this->categoryIds = [];
        $this->temporaryFiles = [];

        PortfolioGalleryContent::clearCache();
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

    /**
     * The one word that stays required, and only with a project page switched
     * on: it is that page's heading and the title search engines show. The
     * project page itself is phase 4B's to redesign; this keeps it intact.
     */
    public function testAProjectPageStillNeedsATitle(): void
    {
        $itemId = $this->storedItem('', []);
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        $response = self::$server->request('POST', '/api/admin/update-portfolio-item.php', $session, [
            'csrf_token' => $csrf,
            'item_id' => (string) $itemId,
            'title_nl' => '',
            'is_active' => '1',
            'has_detail_page' => '1',
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/admin/portfolio-item.php?id=' . $itemId, $response['location']);
        $this->assertSame(
            [AdminTranslator::trans('validation.projectpagina_heeft_titel_nodig')],
            $this->accounts->read($session, 'admin_portfolio_item_errors')
        );

        $item = (array) (new PortfolioGalleryRepository())->findItemById($itemId);
        $this->assertSame(0, (int) $item['has_detail_page'], 'a refused save changes nothing');
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
