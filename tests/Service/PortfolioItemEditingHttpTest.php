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
use App\Service\Media\MediaService;
use App\Service\PortfolioImageProcessor;
use App\Service\PortfolioLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Creating and editing a portfolio item through the real endpoints: an image
 * is enough, every word and every category is optional, what an editor does
 * fill in comes back exactly as typed, and the item's own project page
 * (Portfolio 2.0) — its switch, address, texts and gallery — is saved right
 * there, without an ordinary page ever being made, chosen or linked.
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

    /** @var list<int> library items this test uploaded */
    private array $mediaIds = [];

    /** @var list<int> */
    private array $categoryIds = [];

    /** @var list<int> */
    private array $pageIds = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    /** @var list<string> redirect source paths a rename in this test wrote */
    private array $redirectSources = [];

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

        // After the items that used them: a library item in use cannot go.
        $media = new MediaService();
        foreach ($this->mediaIds as $id) {
            MediaService::clearCache();
            $media->delete($id);
        }
        $this->mediaIds = [];

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

        $redirects = new \App\Repository\RedirectRepository();
        foreach ($this->redirectSources as $sourcePath) {
            $row = $redirects->findBySourcePath($sourcePath);
            if ($row !== null) {
                $redirects->delete((int) $row['id']);
            }
        }
        $this->redirectSources = [];

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
            ['csrf_token' => $csrf, 'media_id' => $this->libraryImage($session, $csrf)]
        );

        $itemId = $this->createdItemId($response);
        $item = (new PortfolioGalleryRepository())->findItemById($itemId);
        $this->assertNotNull($item);

        // No words at all in any language: no row, which is the same state as
        // "written as nothing" since Multilingual 2.0 phase 5 wave A.
        $this->assertSame([], PortfolioLocalization::items()->words($itemId), 'every word may be left empty');
        $this->assertSame(
            [PortfolioLocalization::TITLE => '', PortfolioLocalization::ALT => '', PortfolioLocalization::SUBTITLE => ''],
            $this->storedWords($itemId, 'en'),
            'and nothing is translated'
        );

        $this->assertSame([], (new PortfolioGalleryRepository())->categoryIdsForItem($itemId), 'no category is a valid choice');
        $this->assertNull($item['page_id'], 'and so is no page');
        $this->assertFileExists(dirname(__DIR__, 2) . '/' . $item['image_path']);
        $this->assertNull($this->accounts->read($session, 'admin_portfolio_item_errors'));
    }

    public function testEverythingAnEditorFillsInIsStoredAsTyped(): void
    {
        $category = $this->category();
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        // ONE language per request (phase 5 wave A), and a new item is written
        // in the default language whatever the screen showed.
        $words = [
            'title' => 'ZZ Snijplank',
            'alt' => 'Houten snijplank met een gegraveerde naam',
            'subtitle' => 'Cadeau, hout',
        ];

        $response = self::$server->request(
            'POST',
            '/api/admin/create-portfolio-item.php',
            $session,
            ['csrf_token' => $csrf, 'categories[0]' => (string) $category['id'], 'media_id' => $this->libraryImage($session, $csrf)] + $words
        );

        $itemId = $this->createdItemId($response);
        $repository = new PortfolioGalleryRepository();

        $this->assertSame($words, $this->storedWords($itemId));
        $this->assertSame(
            ['title' => '', 'alt' => '', 'subtitle' => ''],
            $this->storedWords($itemId, 'en'),
            'the request named one language, so only that one was written'
        );

        $this->assertSame([(int) $category['id']], $repository->categoryIdsForItem($itemId));
    }

    /**
     * Saving Dutch leaves English standing, and the other way round. That used
     * to be a property of a form that posted both columns at once; since
     * phase 5 wave A it is a property of the storage.
     */
    public function testSavingOneLanguageThroughTheEndpointLeavesTheOtherAlone(): void
    {
        $itemId = $this->storedItem('ZZ Snijplank', []);
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        $save = function (string $language, string $title) use ($session, $csrf, $itemId): array {
            return self::$server->request('POST', '/api/admin/update-portfolio-item.php', $session, [
                'csrf_token' => $csrf,
                'item_id' => (string) $itemId,
                'language_code' => $language,
                'title' => $title,
                'alt' => '',
                'subtitle' => '',
                'is_active' => '1',
            ]);
        };

        $this->assertSame(302, $save('en', 'ZZ Cutting board')['status']);
        $this->assertSame('ZZ Snijplank', $this->storedWords($itemId)['title'], 'English did not touch Dutch');
        $this->assertSame('ZZ Cutting board', $this->storedWords($itemId, 'en')['title']);

        $this->assertSame(302, $save('nl', 'ZZ Plank')['status']);
        $this->assertSame('ZZ Plank', $this->storedWords($itemId)['title']);
        $this->assertSame('ZZ Cutting board', $this->storedWords($itemId, 'en')['title'], 'Dutch did not touch English');

        // A language the registry does not have is refused, and nothing moves.
        $refused = $save('xx', 'ZZ Nope');
        $this->assertSame('/admin/portfolio-item.php?id=' . $itemId, $refused['location']);
        $this->assertNotEmpty($this->accounts->read($session, 'admin_portfolio_item_errors'));
        $this->assertSame('ZZ Plank', $this->storedWords($itemId)['title']);
    }

    /** No image, no item — and the editor is told why. */
    public function testWithoutAnImageThereIsNoItem(): void
    {
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);
        $before = $this->itemCount();

        $response = self::$server->request('POST', '/api/admin/create-portfolio-item.php', $session, [
            'csrf_token' => $csrf,
            'title' => 'ZZ Zonder afbeelding',
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
            'language_code' => PortfolioLocalization::defaultLanguage(),
            'title' => '',
            'alt' => '',
            'subtitle' => '',
            'is_active' => '1',
        ]);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/admin/portfolio-item.php?id=' . $itemId . '&updated=1', $response['location']);

        $repository = new PortfolioGalleryRepository();

        // Emptied means "no words", so that language has no row left at all.
        $this->assertSame(
            ['title' => '', 'alt' => '', 'subtitle' => ''],
            $this->storedWords($itemId)
        );
        $this->assertSame([], PortfolioLocalization::items()->words($itemId));

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
     * Media Library 2.0: the editor chooses its picture from the library,
     * never with a file input of its own. An item from before the library
     * shows its own picture, in the HTML itself, beside the picker until
     * another one is chosen; a new item's form offers the picker alone.
     */
    public function testTheEditorShowsAnOldPictureBesideTheLibraryPicker(): void
    {
        $itemId = $this->storedItem('ZZ Werk', []);
        $imageSrc = '/' . (string) (new PortfolioGalleryRepository())->findItemById($itemId)['image_path'];
        [$session] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        $editor = self::$server->request('GET', '/admin/portfolio-item.php?id=' . $itemId, $session);
        $this->assertSame(200, $editor['status']);
        $this->assertStringContainsString('<img src="' . $imageSrc . '" alt="" loading="lazy">', $editor['body']);
        $this->assertStringContainsString('data-media-picker-open', $editor['body']);
        $this->assertStringContainsString('data-media-modal', $editor['body']);
        $this->assertStringNotContainsString('type="file" class="admin-file__input"', $editor['body']);

        $new = self::$server->request('GET', '/admin/portfolio-item.php', $session);
        $this->assertSame(200, $new['status']);
        $this->assertStringContainsString('data-media-picker-open', $new['body']);
        $this->assertStringNotContainsString('admin-file__input', $new['body']);
    }

    /* ------------------------------------------------------------------ */
    /* The project page (Portfolio 2.0)                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Switching the project page on is enough: its address is made from the
     * default-language title, its words are stored for this language, and no
     * ordinary page is created for it — the item is the page's content.
     */
    public function testSwitchingTheProjectPageOnGivesItAnAddressAndMakesNoPage(): void
    {
        $marker = bin2hex(random_bytes(3));
        $itemId = $this->storedItem('ZZ Gegraveerde snijplank ' . $marker, []);
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);
        $pagesBefore = $this->pageCount();

        $response = $this->saveProjectPage($session, $csrf, $itemId, [
            'title' => 'ZZ Gegraveerde snijplank ' . $marker,
            'has_detail_page' => '1',
            'slug' => '',
            'intro' => '<p>ZZ inleiding</p>',
            'description' => '<h2>ZZ Het verhaal</h2><p>Lang <strong>verhaal</strong>.</p>',
        ]);

        $this->assertSame('/admin/portfolio-item.php?id=' . $itemId . '&updated=1', $response['location']);
        $item = (array) (new PortfolioGalleryRepository())->findItemById($itemId);
        $this->assertSame(1, (int) $item['has_detail_page']);
        $this->assertSame('zz-gegraveerde-snijplank-' . $marker, $item['slug'], 'made from the title');
        $this->assertNull($item['page_id']);
        $this->assertSame($pagesBefore, $this->pageCount(), 'no pages row is ever made for a project');

        PortfolioLocalization::clearCache();
        $this->assertSame('<p>ZZ inleiding</p>', PortfolioLocalization::rawItemValue($itemId, PortfolioLocalization::INTRO, PortfolioLocalization::defaultLanguage()));
        $this->assertStringContainsString('<strong>verhaal</strong>', PortfolioLocalization::rawItemValue($itemId, PortfolioLocalization::DESCRIPTION, PortfolioLocalization::defaultLanguage()));

        $public = self::$server->request('GET', '/portfolio-detail.php?slug=' . $item['slug']);
        $this->assertSame(200, $public['status'], 'the project page answers');
        $this->assertStringContainsString('ZZ Het verhaal', $public['body']);
    }

    /**
     * A typed address is normalised, another item's is refused and nothing is
     * written, and an address the screen filled in from the title by itself is
     * made unique instead of refused.
     */
    public function testATypedSlugIsNormalisedAndATakenOneIsRefused(): void
    {
        $marker = bin2hex(random_bytes(3));
        $otherId = $this->storedItem('ZZ Ander', []);
        (new PortfolioGalleryRepository())->setItemProjectPage($otherId, true, 'zz-bezet-' . $marker);
        $itemId = $this->storedItem('ZZ Project', []);
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);
        $repository = new PortfolioGalleryRepository();

        $this->saveProjectPage($session, $csrf, $itemId, ['has_detail_page' => '1', 'slug' => '  ZZ Één Mooi Werk! ' . $marker]);
        $this->assertSame('zz-een-mooi-werk-' . $marker, $repository->findItemById($itemId)['slug']);

        $refused = $this->saveProjectPage($session, $csrf, $itemId, ['has_detail_page' => '1', 'slug' => 'zz-bezet-' . $marker, 'title' => 'ZZ Niet opgeslagen']);
        $this->assertSame('/admin/portfolio-item.php?id=' . $itemId, $refused['location']);
        $this->assertSame(
            [AdminTranslator::trans('validation.portfolio_slug_taken', ['slug' => 'zz-bezet-' . $marker])],
            $this->accounts->read($session, 'admin_portfolio_item_errors')
        );
        $this->assertSame('zz-een-mooi-werk-' . $marker, $repository->findItemById($itemId)['slug'], 'nothing written');
        $this->assertSame('ZZ Project', $this->storedWords($itemId)['title']);

        $this->saveProjectPage($session, $csrf, $itemId, ['has_detail_page' => '1', 'slug' => '!!!']);
        $this->assertSame([AdminTranslator::trans('validation.portfolio_slug_empty')], $this->accounts->read($session, 'admin_portfolio_item_errors'));

        // The screen's own suggestion that happens to be taken: made unique.
        $this->saveProjectPage($session, $csrf, $otherId, ['title' => 'ZZ Ander', 'has_detail_page' => '1', 'slug' => 'zz-bezet-' . $marker]);
        $newId = $this->storedItem('ZZ Bezet ' . $marker, []);
        $this->saveProjectPage($session, $csrf, $newId, ['title' => 'ZZ Bezet ' . $marker, 'has_detail_page' => '1', 'slug' => 'zz-bezet-' . $marker, 'slug_auto' => '1']);
        $this->assertSame('zz-bezet-' . $marker . '-2', $repository->findItemById($newId)['slug']);
    }

    /**
     * Renaming a public project page leaves no dead address: the old one
     * redirects permanently to the new one in every active language, through
     * the Redirect Manager's own slug_change rows.
     */
    public function testRenamingAPublicProjectPageRecordsARedirect(): void
    {
        $marker = bin2hex(random_bytes(3));
        $itemId = $this->storedItem('ZZ Hernoemd', []);
        (new PortfolioGalleryRepository())->setItemProjectPage($itemId, true, 'zz-oud-' . $marker);
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        $this->saveProjectPage($session, $csrf, $itemId, ['has_detail_page' => '1', 'slug' => 'zz-nieuw-' . $marker]);

        $redirects = new \App\Repository\RedirectRepository();
        foreach (\App\Service\Language\SiteLanguages::activeCodes() as $language) {
            $from = \App\Service\Routing\LocalizedUrl::path('/portfolio/zz-oud-' . $marker, $language);
            $this->redirectSources[] = $from;
            $row = $redirects->findBySourcePath($from);
            $this->assertNotNull($row, $from . ' redirects');
            $this->assertSame(\App\Service\Routing\LocalizedUrl::path('/portfolio/zz-nieuw-' . $marker, $language), $row['target_value']);
            $this->assertSame('slug_change', $row['origin']);
            $this->assertSame(301, (int) $row['status_code']);
        }
    }

    /** A hidden item's page was never public, so renaming it leaves nothing behind. */
    public function testRenamingAHiddenProjectPageRecordsNoRedirect(): void
    {
        $marker = bin2hex(random_bytes(3));
        $itemId = $this->storedItem('ZZ Verborgen', []);
        (new PortfolioGalleryRepository())->setItemProjectPage($itemId, true, 'zz-verborgen-oud-' . $marker);
        Database::connection()->prepare('UPDATE portfolio_gallery_items SET is_active = 0 WHERE id = ?')->execute([$itemId]);
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        $this->saveProjectPage($session, $csrf, $itemId, ['has_detail_page' => '1', 'slug' => 'zz-verborgen-nieuw-' . $marker]);

        $this->assertNull((new \App\Repository\RedirectRepository())->findBySourcePath('/portfolio/zz-verborgen-oud-' . $marker));
    }

    /**
     * The rich texts go through the sanitizer on the way in: a script never
     * reaches the table, and the markup an editor may use stays.
     */
    public function testTheProjectTextsAreSanitizedOnTheWayIn(): void
    {
        $itemId = $this->storedItem('ZZ Veilig', []);
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        $this->saveProjectPage($session, $csrf, $itemId, [
            'has_detail_page' => '1',
            'slug' => 'zz-veilig-' . bin2hex(random_bytes(3)),
            'description' => '<p onclick="alert(1)">ZZ tekst<script>alert(2)</script></p><img src=x onerror=alert(3)>',
        ]);

        PortfolioLocalization::clearCache();
        $stored = PortfolioLocalization::rawItemValue($itemId, PortfolioLocalization::DESCRIPTION, PortfolioLocalization::defaultLanguage());
        $this->assertStringContainsString('ZZ tekst', $stored);
        foreach (['<script', 'onclick', 'onerror', 'alert('] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $stored);
        }
    }

    /**
     * A request that never showed the project-page section — no marker — does
     * not switch the page off and does not empty its texts.
     */
    public function testARequestWithoutTheSectionLeavesTheProjectPageAlone(): void
    {
        $itemId = $this->storedItem('ZZ Ongemoeid', []);
        $slug = 'zz-ongemoeid-' . bin2hex(random_bytes(3));
        (new PortfolioGalleryRepository())->setItemProjectPage($itemId, true, $slug);
        PortfolioLocalization::saveItem($itemId, PortfolioLocalization::defaultLanguage(), [PortfolioLocalization::INTRO => '<p>ZZ blijft</p>']);
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        self::$server->request('POST', '/api/admin/update-portfolio-item.php', $session, [
            'csrf_token' => $csrf,
            'item_id' => (string) $itemId,
            'language_code' => PortfolioLocalization::defaultLanguage(),
            'title' => 'ZZ Ongemoeid',
            'alt' => '',
            'subtitle' => '',
            'is_active' => '1',
        ]);

        $item = (array) (new PortfolioGalleryRepository())->findItemById($itemId);
        $this->assertSame(1, (int) $item['has_detail_page']);
        $this->assertSame($slug, $item['slug']);
        PortfolioLocalization::clearCache();
        $this->assertSame('<p>ZZ blijft</p>', PortfolioLocalization::rawItemValue($itemId, PortfolioLocalization::INTRO, PortfolioLocalization::defaultLanguage()));
    }

    /* ------------------------------------------------------------------ */
    /* The gallery                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Library photos in the posted order; the main picture is never taken as
     * a photo too; a photo token of another item is ignored; removing a photo
     * takes only the relation off, and the library keeps the item — which it
     * then refuses to delete while the project still shows it.
     */
    public function testTheGalleryStoresLibraryPhotosInOrderAndRemovingOneKeepsTheLibraryItem(): void
    {
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);
        $main = (int) $this->libraryImage($session, $csrf);
        $first = (int) $this->libraryImage($session, $csrf);
        $second = (int) $this->libraryImage($session, $csrf);

        $itemId = $this->createdItemId(self::$server->request('POST', '/api/admin/create-portfolio-item.php', $session, [
            'csrf_token' => $csrf,
            'media_id' => (string) $main,
        ]));

        $otherId = $this->storedItem('ZZ Ander', []);
        $images = new \App\Repository\PortfolioItemImageRepository();
        $images->replaceForItem($otherId, [['media_id' => $first, 'image_path' => 'assets/media/zz-ander.webp', 'thumbnail_path' => null]]);
        $foreignPhotoId = (int) $images->findByPortfolioItemId($otherId)[0]['id'];

        $this->saveProjectPage($session, $csrf, $itemId, [
            'gallery_submitted' => '1',
            'gallery[0]' => 'media:' . $second,
            'gallery[1]' => 'media:' . $main,
            'gallery[2]' => 'media:' . $first,
            'gallery[3]' => 'photo:' . $foreignPhotoId,
            'gallery[4]' => 'media:' . $second,
            'media_id' => (string) $main,
        ]);

        $rows = $images->findByPortfolioItemId($itemId);
        $this->assertSame([$second, $first], array_map(static fn (array $r): int => (int) $r['media_id'], $rows), 'in order, no main picture, no double, no stolen photo');
        $this->assertCount(1, $images->findByPortfolioItemId($otherId), 'the other item keeps its photo');

        MediaService::clearCache();
        $usages = \App\Service\Media\MediaUsageRegistry::usagesFor([$second])[$second] ?? [];
        $this->assertNotEmpty($usages, 'the library knows the project uses the photo');
        $this->assertStringContainsString('(galerij)', $usages[0]->label);
        $this->assertSame('in_use', (new MediaService())->delete($second)['reason'], 'and refuses to delete it');

        // Reorder and remove one: the relation goes, the library item stays.
        $keptRowId = (int) $rows[1]['id'];
        $this->saveProjectPage($session, $csrf, $itemId, [
            'gallery_submitted' => '1',
            'gallery[0]' => 'photo:' . $keptRowId,
        ]);

        $rows = $images->findByPortfolioItemId($itemId);
        $this->assertSame([$first], array_map(static fn (array $r): int => (int) $r['media_id'], $rows));
        MediaService::clearCache();
        $this->assertNotNull(MediaService::find($second), 'the removed photo is still in the library');
        $this->assertFileExists(dirname(__DIR__, 2) . '/' . MediaService::find($second)->path);

        // A save without the section never empties the gallery.
        $this->saveProjectPage($session, $csrf, $itemId, []);
        $this->assertCount(1, $images->findByPortfolioItemId($itemId));

        $images->replaceForItem($otherId, []);
    }

    /* ------------------------------------------------------------------ */
    /* The legacy linked page                                              */
    /* ------------------------------------------------------------------ */

    /**
     * No page can be linked any more: a posted page id is ignored, for an item
     * without a link and for one with. The one thing left is unlinking, which
     * never touches the page itself.
     */
    public function testALegacyPageCanOnlyBeKeptOrUnlinked(): void
    {
        $itemId = $this->storedItem('ZZ Zonder koppeling', []);
        $linkedId = $this->storedItem('ZZ Met koppeling', []);
        $pageId = $this->page(PageContent::STATUS_PUBLISHED);
        $otherPageId = $this->page(PageContent::STATUS_PUBLISHED);
        $repository = new PortfolioGalleryRepository();
        $repository->setItemPage($linkedId, $pageId);
        [$session, $csrf] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);

        $this->saveProjectPage($session, $csrf, $itemId, ['page_id' => (string) $pageId]);
        $this->assertNull($repository->findItemById($itemId)['page_id'], 'no new link can be made');

        $this->saveProjectPage($session, $csrf, $linkedId, ['page_id' => (string) $otherPageId]);
        $this->assertSame($pageId, (int) $repository->findItemById($linkedId)['page_id'], 'nor an existing one moved');

        $this->saveProjectPage($session, $csrf, $linkedId, ['unlink_page' => '1']);
        $this->assertNull($repository->findItemById($linkedId)['page_id'], 'unlinked');
        $this->assertNotNull((new PageRepository())->findById($pageId), 'and the page itself stays, with its content');
    }

    /**
     * The editor has no page flow at all: no page select and no "Nieuwe pagina
     * maken", not even for an editor who may make pages. The legacy card shows
     * only on an item that has a link, with the page's name.
     */
    public function testTheEditorHasNoPageFlowAndShowsALegacyLinkOnlyWhereThereIsOne(): void
    {
        $itemId = $this->storedItem('ZZ Eigen', []);
        $linkedId = $this->storedItem('ZZ Gekoppeld', []);
        $pageId = $this->page(PageContent::STATUS_PUBLISHED);
        (new PortfolioGalleryRepository())->setItemPage($linkedId, $pageId);

        [$pageEditor] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE, AdminPermissions::PAGES_MANAGE]);

        $own = self::$server->request('GET', '/admin/portfolio-item.php?id=' . $itemId, $pageEditor);
        $this->assertSame(200, $own['status']);
        $this->assertStringNotContainsString('page-new.php', $own['body']);
        $this->assertStringNotContainsString('name="page_id"', $own['body']);
        $this->assertStringNotContainsString('name="unlink_page"', $own['body']);
        foreach (['name="has_detail_page"', 'name="slug"', 'name="intro"', 'name="description"', 'data-picture-gallery', 'class="admin-card-pair"'] as $needle) {
            $this->assertStringContainsString($needle, $own['body']);
        }

        $linked = self::$server->request('GET', '/admin/portfolio-item.php?id=' . $linkedId, $pageEditor);
        $this->assertStringContainsString('name="unlink_page"', $linked['body']);
        $this->assertStringContainsString(htmlspecialchars(\App\Service\PageLocalization::name($pageId), ENT_QUOTES, 'UTF-8'), $linked['body']);
        $this->assertStringNotContainsString('page-new.php', $linked['body']);
    }

    /* ------------------------------------------------------------------ */
    /* Guards                                                              */
    /* ------------------------------------------------------------------ */

    /** No portfolio.manage, or no valid CSRF token: refused, nothing written. */
    public function testTheSaveRefusesAnEditorWithoutThePermissionAndAMissingToken(): void
    {
        $itemId = $this->storedItem('ZZ Beschermd', []);
        $repository = new PortfolioGalleryRepository();

        [$pagesOnly, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $denied = $this->saveProjectPage($pagesOnly, $csrf, $itemId, ['has_detail_page' => '1', 'slug' => 'zz-niet-' . bin2hex(random_bytes(3))]);
        $this->assertSame(403, $denied['status']);

        [$session] = $this->accounts->signIn([PortfolioModule::PORTFOLIO_MANAGE]);
        $forged = $this->saveProjectPage($session, 'geen-geldig-token', $itemId, ['has_detail_page' => '1', 'slug' => 'zz-niet-' . bin2hex(random_bytes(3))]);
        $this->assertSame(403, $forged['status']);

        $this->assertNull($repository->findItemById($itemId)['slug'], 'nothing was written');
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function category(): array
    {
        $repository = new PortfolioCategoryRepository();
        $marker = bin2hex(random_bytes(3));
        $id = $repository->create('zz-categorie-' . $marker);
        $this->categoryIds[] = $id;
        PortfolioLocalization::saveCategory($id, PortfolioLocalization::defaultLanguage(), 'ZZ Categorie ' . $marker);

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
        ]);
        $this->itemIds[] = $id;

        PortfolioLocalization::saveItem($id, PortfolioLocalization::defaultLanguage(), [
            PortfolioLocalization::ALT => $title === '' ? '' : 'ZZ alt ' . $marker,
            PortfolioLocalization::TITLE => $title,
            PortfolioLocalization::SUBTITLE => $title === '' ? '' : 'ZZ onderschrift ' . $marker,
        ]);

        $repository->setItemCategories($id, $categoryIds);

        return $id;
    }


    /**
     * What is stored for one item in one language, with no fallback — the
     * per-language storage of Multilingual 2.0 phase 5 wave A.
     *
     * @return array<string, string>
     */
    private function storedWords(int $itemId, string $language = 'nl'): array
    {
        PortfolioLocalization::clearCache();

        $words = [];
        foreach ([PortfolioLocalization::TITLE, PortfolioLocalization::ALT, PortfolioLocalization::SUBTITLE] as $field) {
            $words[$field] = PortfolioLocalization::rawItemValue($itemId, $field, $language);
        }

        return $words;
    }

    private function page(string $status): int
    {
        $key = 'zz-projectpagina-' . bin2hex(random_bytes(4));

        $id = \Tests\Support\PageFixture::create([
            'content_key' => $key,
            'slug' => $key,
            'status' => $status,
        ], 'ZZ Projectpagina ' . $key);
        $this->pageIds[] = $id;

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

    /**
     * One save of the item's editor with the project-page section on it, the
     * way admin/portfolio-item.php posts: the item's words and switches, the
     * section's marker, and whatever this call changes.
     *
     * @param array<string, string> $fields
     * @return array{status: int, location: string, body: string}
     */
    private function saveProjectPage(string $session, string $csrf, int $itemId, array $fields): array
    {
        return self::$server->request('POST', '/api/admin/update-portfolio-item.php', $session, $fields + [
            'csrf_token' => $csrf,
            'item_id' => (string) $itemId,
            'language_code' => PortfolioLocalization::defaultLanguage(),
            'title' => PortfolioLocalization::rawItemValue($itemId, PortfolioLocalization::TITLE, PortfolioLocalization::defaultLanguage()),
            'alt' => '',
            'subtitle' => '',
            'is_active' => '1',
            'project_page_submitted' => '1',
        ]);
    }

    private function pageCount(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM pages')->fetchColumn();
    }

    private function itemCount(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM portfolio_gallery_items')->fetchColumn();
    }

    /**
     * A picture as an editor gets one since Media Library 2.0: uploaded into
     * the library through the picker's own endpoint, then chosen by its id.
     *
     * @return string the media id, as the picker posts it
     */
    private function libraryImage(string $session, string $csrf): string
    {
        $response = self::$server->request('POST', '/api/admin/media-upload.php', $session, [
            'csrf_token' => $csrf,
            'kind' => 'image',
        ], ['file' => $this->uploadableImage()]);

        $this->assertSame(200, $response['status'], $response['body']);
        $id = (int) (json_decode($response['body'], true)['item']['id'] ?? 0);
        $this->assertGreaterThan(0, $id);
        $this->mediaIds[] = $id;

        return (string) $id;
    }

    /** A small, real PNG, sent the way a browser sends a chosen file. */
    private function uploadableImage(): \CURLFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'zzportfolio');
        $this->temporaryFiles[] = $path;

        $image = imagecreatetruecolor(64, 48);
        // A colour of its own per upload: the library hands back the item it
        // already has for identical bytes (MEDIA.md), and a test that uploads
        // several pictures needs several items.
        imagefill($image, 0, 0, (int) imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        imagepng($image, $path);
        imagedestroy($image);

        return new \CURLFile($path, 'image/png', 'werk.png');
    }
}
