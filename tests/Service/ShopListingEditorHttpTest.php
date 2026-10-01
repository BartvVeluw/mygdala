<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\ShopListingRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\PageContent;
use App\Service\SectionRegistry;
use App\Service\ShopListingContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\SavedRedirect;

/**
 * The head of the Shop's Productgrid and Collectie-tegels through the real
 * editor (admin/shop-listing.php), endpoint (api/admin/update-shop-listing.php)
 * and page, over PHP's built-in server — once with the Shop on, once off:
 *
 *   - the four guards and the `<page>:<key>` gate, including a key of another
 *     block (a gallery) posted as if it were a listing's;
 *   - the head of one language is one save, the other language stays, and
 *     the page prints it escaped above the listing;
 *   - with the Shop off the editor is closed and the endpoint refuses before
 *     reading anything; the row and its words stay.
 *
 * The page, its blocks and the accounts are this test's own and are removed
 * in tearDown(). Without a server the test skips itself.
 */
final class ShopListingEditorHttpTest extends TestCase
{
    private const KEY = 'zz-shop-listing-http';

    private const ENDPOINT = '/api/admin/update-shop-listing.php';

    private static ?BuiltInServer $server = null;

    private static ?BuiltInServer $shopOff = null;

    private AdminTestSession $accounts;

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true']);
        self::$shopOff = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'false', 'MODULE_PERSONALIZATION_ENABLED' => 'false']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$shopOff?->stop();
        self::$server = null;
        self::$shopOff = null;
    }

    protected function setUp(): void
    {
        $this->accounts = new AdminTestSession();

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        $this->removePage();
        PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'Shop-listing-http');
    }

    protected function tearDown(): void
    {
        $this->removePage();
        $this->accounts->forget();
        ShopListingContent::clearCache();
        BlockLocalization::clearCache();
    }

    public function testTheFourGuardsAndTheSectionGate(): void
    {
        [$section] = $this->place('product_grid');
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        self::assertSame(401, self::$server->request('POST', self::ENDPOINT, null, ['section' => $section])['status'], 'not signed in');

        [$other, $otherCsrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        self::assertSame(403, self::$server->request('POST', self::ENDPOINT, $other, ['section' => $section, 'csrf_token' => $otherCsrf])['status'], 'no pages.manage');

        self::assertSame(405, self::$server->request('GET', self::ENDPOINT . '?section=' . urlencode($section), $session)['status']);
        self::assertSame(403, self::$server->request('POST', self::ENDPOINT, $session, ['section' => $section, 'csrf_token' => 'wrong'])['status']);

        // A gallery's own key, posted as if it were a listing's, is no listing.
        [$gallery] = $this->place('item_gallery');
        foreach ([self::KEY . ':custom-nothere', 'no-such-page:custom-x', self::KEY, '', $gallery] as $unknown) {
            self::assertSame(404, self::$server->request('POST', self::ENDPOINT, $session, ['section' => $unknown, 'csrf_token' => $csrf, 'language_code' => 'nl', 'title' => 'X'])['status'], $unknown);
            self::assertSame(404, self::$server->request('GET', '/admin/shop-listing.php?section=' . urlencode($unknown), $session)['status'], $unknown);
        }

        $editor = self::$server->request('GET', '/admin/shop-listing.php?section=' . urlencode($section), $session);
        self::assertSame(200, $editor['status']);
        foreach (['name="eyebrow"', 'name="title"', 'name="lead"', 'action="/api/admin/update-shop-listing.php"'] as $field) {
            self::assertStringContainsString($field, $editor['body']);
        }
        self::assertStringNotContainsString('appearance_', $editor['body'], 'no second styling panel in the editor');
    }

    public function testTheHeadOfOneLanguageIsOneSaveAndThePagePrintsItEscaped(): void
    {
        [$section, $id] = $this->place('shop_collections');
        [$grid, $gridId] = $this->place('product_grid');
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $saved = self::$server->request('POST', self::ENDPOINT, $session, [
            'section' => $grid, 'csrf_token' => $csrf, 'language_code' => 'nl',
            'eyebrow' => 'Winkel', 'title' => '<script>alert(1)</script>Alles', 'lead' => 'Met de hand & zorg.',
            // Never read: names that are not the head's.
            'is_active' => '0', 'appearance_background' => 'primary', 'section_id' => '1',
        ]);
        self::assertMatchesRegularExpression(SavedRedirect::PATTERN, (string) $saved['location']);
        self::assertSame('<script>alert(1)</script>Alles', BlockLocalization::raw(ShopListingRepository::TABLE, $gridId, 'title', 'nl'), 'stored as typed, plain text');
        self::assertSame(1, (int) Database::connection()->query('SELECT is_active FROM shop_listing_blocks WHERE id = ' . $gridId)->fetchColumn());

        self::$server->request('POST', self::ENDPOINT, $session, ['section' => $grid, 'csrf_token' => $csrf, 'language_code' => 'en', 'eyebrow' => '', 'title' => 'Everything', 'lead' => '']);
        BlockLocalization::clearCache();
        self::assertSame('Winkel', BlockLocalization::raw(ShopListingRepository::TABLE, $gridId, 'eyebrow', 'nl'), 'the other language stays');
        self::assertSame('Everything', BlockLocalization::raw(ShopListingRepository::TABLE, $gridId, 'title', 'en'));

        // A word too long is refused at its field and nothing is stored.
        $refused = self::$server->request('POST', self::ENDPOINT, $session, ['section' => $section, 'csrf_token' => $csrf, 'language_code' => 'nl', 'title' => str_repeat('a', 256)]);
        self::assertStringStartsWith('/admin/shop-listing.php?section=', (string) $refused['location']);
        self::assertSame('', BlockLocalization::raw(ShopListingRepository::TABLE, $id, 'title', 'nl'));
        // An unknown language is refused.
        $refused = self::$server->request('POST', self::ENDPOINT, $session, ['section' => $section, 'csrf_token' => $csrf, 'language_code' => 'xx', 'title' => 'Nee']);
        self::assertStringStartsWith('/admin/shop-listing.php?section=', (string) $refused['location']);

        $page = (string) self::$server->request('GET', '/pagina.php?slug=' . self::KEY)['body'];
        self::assertStringContainsString('<p class="eyebrow">Winkel</p>', $page);
        self::assertStringContainsString('<h2>&lt;script&gt;alert(1)&lt;/script&gt;Alles</h2>', $page);
        self::assertStringContainsString('<p class="lead">Met de hand &amp; zorg.</p>', $page);
        self::assertStringNotContainsString('<script>alert(1)', $page);
        self::assertStringContainsString('data-card-heading="h3"', $page, 'product names under the block title');
    }

    public function testWithTheShopOffTheEditorIsClosedAndTheRowKept(): void
    {
        if (self::$shopOff === null || !self::$shopOff->answers()) {
            $this->markTestSkipped('no second server with the Shop off');
        }

        [$section, $id] = $this->place('product_grid');
        BlockLocalization::save(ShopListingRepository::TABLE, $id, 'nl', ['eyebrow' => '', 'title' => 'Blijft', 'lead' => '']);
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE], true);

        self::assertSame(403, self::$shopOff->request('GET', '/admin/shop-listing.php?section=' . urlencode($section), $session)['status'], 'the CMS\'s no-access page');
        self::assertSame(404, self::$shopOff->request('POST', self::ENDPOINT, $session, ['section' => $section, 'csrf_token' => $csrf, 'language_code' => 'nl', 'title' => 'Weg'])['status']);

        $page = (string) self::$shopOff->request('GET', '/pagina.php?slug=' . self::KEY)['body'];
        self::assertStringNotContainsString('Blijft', $page, 'the block of a switched-off module is skipped');

        BlockLocalization::clearCache();
        self::assertSame('Blijft', BlockLocalization::raw(ShopListingRepository::TABLE, $id, 'title', 'nl'));
        self::assertNotNull((new ShopListingRepository())->findBySlugAndKey(self::KEY, explode(':', $section)[1]));
    }

    /** @return array{0: string, 1: int} "<page>:<key>", the content row id */
    private function place(string $type): array
    {
        [$id, $key] = SectionRegistry::create($type, self::KEY);
        $page = (new PageRepository())->findByContentKey(self::KEY);
        (new PageSectionRepository())->create((int) $page['id'], self::KEY, $type, $key, (int) $id);

        return [self::KEY . ':' . $key, (int) $id];
    }

    private function removePage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::KEY);
        if ($page === null) {
            return;
        }

        $sections = new PageSectionRepository();
        foreach ($sections->findForPage((int) $page['id']) as $row) {
            if (SectionRegistry::exists((string) $row['section_type'])) {
                SectionRegistry::delete($row, $sections);
            }
        }
        Database::connection()->prepare('DELETE FROM shop_listing_blocks WHERE page_slug = :slug')->execute(['slug' => self::KEY]);
        Database::connection()->prepare('DELETE FROM item_galleries WHERE page_slug = :slug')->execute(['slug' => self::KEY]);
        Database::connection()->prepare('DELETE FROM pages WHERE id = :id')->execute(['id' => (int) $page['id']]);
    }
}
