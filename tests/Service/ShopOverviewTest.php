<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ShopModule;
use App\Repository\PageRepository;
use App\Repository\ProductRepository;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use App\Service\ShopLocalization;
use App\Service\ShopOverview;
use App\Service\ShopSettings;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * The Shop no longer implies a public page listing every product
 * (App\Service\ShopOverview, MODULES.md "Shop"). Which page is the overview,
 * if any, is the owner's choice, and every link to "the shop" follows it.
 *
 * The first half asks the service itself. The second half starts PHP's
 * built-in server on this checkout, the way PagePreviewAccessTest does, and
 * asks the real routes: /shop.php and a product page's trail and back link.
 * It writes the one setting in the test database and puts back what was
 * there.
 */
final class ShopOverviewTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;

    private ?string $storedBefore = null;
    private bool $hadRow = false;
    private ?int $hiddenStorefrontId = null;

    /** @var list<int> */
    private array $pageIds = [];

    /** @var list<int> */
    private array $productIds = [];

    public static function setUpBeforeClass(): void
    {
        $probe = @stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            return;
        }

        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        self::$port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $root = dirname(__DIR__, 2);
        $discard = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

        // The server inherits this process's environment, which
        // tests/bootstrap.php has already pointed at the test database.
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', $root],
            [0 => ['pipe', 'r'], 1 => ['file', $discard, 'w'], 2 => ['file', $discard, 'w']],
            $pipes,
            $root,
            array_map('strval', getenv())
        );

        if (is_resource($process)) {
            self::$server = $process;
            for ($attempt = 0; $attempt < 50 && !self::answers(); $attempt++) {
                usleep(100000);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
    }

    protected function setUp(): void
    {
        $stmt = Database::connection()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
        $stmt->execute([ShopOverview::SETTING_KEY]);
        $value = $stmt->fetchColumn();
        $this->hadRow = $value !== false;
        $this->storedBefore = $value === false ? null : (string) $value;
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        if ($this->hadRow) {
            $db->prepare('UPDATE site_settings SET setting_value = ? WHERE setting_key = ?')
                ->execute([$this->storedBefore, ShopOverview::SETTING_KEY]);
        } else {
            $db->prepare('DELETE FROM site_settings WHERE setting_key = ?')->execute([ShopOverview::SETTING_KEY]);
        }

        if ($this->hiddenStorefrontId !== null) {
            $db->prepare("UPDATE pages SET content_key = 'shop' WHERE id = ?")->execute([$this->hiddenStorefrontId]);
            $this->hiddenStorefrontId = null;
        }

        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        }
        foreach ($this->pageIds as $id) {
            (new PageRepository())->delete($id);
        }

        SiteSettings::overrideForTests(null);
        ShopOverview::clearCache();
        PageContent::clearCache();
        ShopLocalization::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* The service                                                         */
    /* ------------------------------------------------------------------ */

    public function testNoStoredChoiceMeansNoOverviewAnywhere(): void
    {
        $this->choose('');

        $this->assertSame(ShopOverview::NONE, ShopOverview::mode());
        $this->assertNull(ShopOverview::url());
        $this->assertArrayNotHasKey('shop', (new ShopModule())->routes(), 'no menu item or trail may name a route that answers 404');
        $this->assertSame([], ((new ShopModule())->sitemapCollectors()['storefront'])());
    }

    public function testTheAutomaticListingKeepsItsOwnAddress(): void
    {
        $this->choose(ShopOverview::BUILTIN_VALUE);

        $this->assertSame(ShopOverview::BUILTIN, ShopOverview::mode());
        $this->assertSame('/shop.php', ShopOverview::url('nl'));
        $this->assertArrayHasKey('shop', (new ShopModule())->routes());
    }

    public function testAChosenPageIsTheOverviewAtItsOwnAddress(): void
    {
        $page = $this->page('published');
        $this->choose((string) $page['id']);

        $this->assertSame(ShopOverview::PAGE, ShopOverview::mode());
        $this->assertSame('/' . $page['slug'], ShopOverview::url(ShopLocalization::defaultLanguage()));
        $this->assertSame([], ((new ShopModule())->sitemapCollectors()['storefront'])(), 'the page is in the sitemap as a page, once');
    }

    public function testTheStorefrontPageIsOnlyAdvertisedWhileItIsTheOverview(): void
    {
        $storefront = PageContent::forContentKey('shop');
        if ($storefront === null) {
            $this->markTestSkipped('This database has no storefront page.');
        }
        $other = $this->page('published');

        foreach (['' => false, (string) $other['id'] => false, (string) (int) $storefront['id'] => true, ShopOverview::BUILTIN_VALUE => true] as $value => $served) {
            $this->choose((string) $value);

            $this->assertSame(
                $served,
                PageContent::isServedByAnEnabledModule($storefront),
                "shop_overview = '{$value}': /shop.php " . ($served ? 'shows' : 'does not show') . ' the storefront page'
            );
        }
    }

    public function testADraftOverviewIsLinkedFromNowhere(): void
    {
        $page = $this->page('draft');
        $this->choose((string) $page['id']);

        $this->assertNull(ShopOverview::url());
    }

    public function testAPageThatIsGoneMeansNoOverview(): void
    {
        $this->choose('999999999');

        $this->assertSame(ShopOverview::NONE, ShopOverview::mode());
    }

    public function testTheSettingsScreenOnlyAcceptsWhatItOffers(): void
    {
        $page = $this->page('published');

        $this->assertSame('', ShopOverview::normalise('', ''));
        $this->assertSame((string) $page['id'], ShopOverview::normalise((string) $page['id'], ''));
        $this->assertNull(ShopOverview::normalise('999999999', ''), 'not an offered page');
        $this->assertNull(ShopOverview::normalise('../etc', ''));
        $this->assertNull(ShopOverview::normalise(ShopOverview::BUILTIN_VALUE, ''), 'the automatic listing cannot be chosen anew');
        $this->assertSame(ShopOverview::BUILTIN_VALUE, ShopOverview::normalise(ShopOverview::BUILTIN_VALUE, ShopOverview::BUILTIN_VALUE));

        $refused = ShopSettings::validate(['shop_overview' => '999999999'], SiteSettings::all());
        $this->assertNotSame([], $refused['errors']);
        $this->assertArrayNotHasKey('shop_overview', $refused['values']);

        $accepted = ShopSettings::validate(['shop_overview' => (string) $page['id']], SiteSettings::all());
        $this->assertSame([], $accepted['errors']);
        $this->assertSame((string) $page['id'], $accepted['values']['shop_overview']);
    }

    /* ------------------------------------------------------------------ */
    /* The routes, over HTTP                                               */
    /* ------------------------------------------------------------------ */

    public function testWithoutAnOverviewShopPhpListsNothing(): void
    {
        $this->needServer();
        $this->choose('');

        $response = $this->get('/shop.php');

        $this->assertSame(404, $response['status']);
        $this->assertStringNotContainsString('data-products-grid', $response['body']);
    }

    public function testWithoutAnOverviewAProductLinksToNoOverview(): void
    {
        $this->needServer();
        $this->choose('');
        $productId = $this->product();

        $body = $this->get('/product.php?id=' . $productId)['body'];

        $this->assertStringNotContainsString('href="/shop.php"', $body);
        $this->assertStringNotContainsString('Terug naar producten', $body);
    }

    public function testAChosenPageIsWhereShopPhpAndEveryProductLinkGo(): void
    {
        $this->needServer();
        $page = $this->page('published');
        $this->choose((string) $page['id']);
        $productId = $this->product();

        $redirect = $this->get('/shop.php');
        $this->assertSame(302, $redirect['status']);
        $this->assertStringEndsWith('/' . $page['slug'], $this->header($redirect['headers'], 'Location'));

        $body = $this->get('/product.php?id=' . $productId)['body'];
        $this->assertStringContainsString('href="/' . $page['slug'] . '"', $body, 'trail and back link go to the chosen page');
        $this->assertStringContainsString($page['title'], $body, 'the trail names the page by its own title');
        // (The site's own menu may still hold a "Shop" item for /shop.php:
        // that is the owner's navigation, and /shop.php now leads here.)
        $this->assertMatchesRegularExpression('#<a href="/' . preg_quote($page['slug'], '#') . '" class="btn btn--ghost">#', $body);
    }

    public function testTheLinkFollowsThePagesAddressInTheLanguageBeingRead(): void
    {
        if (!in_array('en', SiteLanguages::activeCodes(), true)) {
            $this->markTestSkipped('English is not an active website language here.');
        }

        $page = $this->page('published');
        PageLocalization::save((int) $page['id'], 'en', [PageTranslation::TITLE => 'ZZ Products EN'], 'zz-products-' . $page['id']);
        PageContent::clearCache();
        $this->choose((string) $page['id']);

        // /en/... is the Apache dispatcher's address (docs/multilingual/ROUTING.md),
        // which PHP's built-in server does not run, so the address is asked
        // of the service every Shop link goes through.
        $this->assertSame('/en/zz-products-' . $page['id'], ShopOverview::url('en'));
        $this->assertSame('/' . $page['slug'], ShopOverview::url(ShopLocalization::defaultLanguage()));
    }

    public function testTheAutomaticListingStillAnswersForAnOlderInstallation(): void
    {
        $this->needServer();
        $this->choose(ShopOverview::BUILTIN_VALUE);

        // An installation pinned to 'builtin' has no storefront page
        // (db/migrations/20260923140000). The test database has one, so it is
        // renamed away for this test and put back in tearDown().
        $storefront = PageContent::forContentKey('shop');
        if ($storefront !== null) {
            $this->hiddenStorefrontId = (int) $storefront['id'];
            Database::connection()->prepare("UPDATE pages SET content_key = 'zz-storefront-hidden' WHERE id = ?")
                ->execute([$this->hiddenStorefrontId]);
            PageContent::clearCache();
        }

        $response = $this->get('/shop.php');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('data-products-grid', $response['body']);
    }

    public function testTheStorefrontPageStaysAtShopPhp(): void
    {
        $this->needServer();
        $storefront = PageContent::forContentKey('shop');
        if ($storefront === null) {
            $this->markTestSkipped('This database has no storefront page.');
        }
        $this->choose((string) (int) $storefront['id']);

        $this->assertSame('/shop.php', ShopOverview::url(ShopLocalization::defaultLanguage()));
        $this->assertSame(200, $this->get('/shop.php')['status']);
    }

    /* ------------------------------------------------------------------ */

    private function choose(string $value): void
    {
        Database::connection()->prepare(
            'INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        )->execute([ShopOverview::SETTING_KEY, $value]);

        SiteSettings::clearCache();
        ShopOverview::clearCache();
    }

    /** @return array<string, mixed> the page row plus its title */
    private function page(string $status): array
    {
        $key = 'zz-overzicht-' . bin2hex(random_bytes(4));
        $title = 'ZZ Productoverzicht ' . bin2hex(random_bytes(3));
        $pages = new PageRepository();

        $id = $pages->create(['content_key' => $key, 'slug' => $key, 'status' => $status]);
        $this->pageIds[] = $id;
        PageLocalization::save($id, PageLocalization::defaultLanguage(), [PageTranslation::TITLE => $title]);
        PageContent::clearCache();

        return (array) $pages->findById($id) + ['title' => $title];
    }

    private function product(): int
    {
        $id = (new ProductRepository())->create([
            'slug' => '__test_overview_' . bin2hex(random_bytes(3)) . '__',
            'price' => 10.00,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 25,
            'requires_parcel' => false,
        ]);
        ShopLocalization::saveProduct($id, ShopLocalization::defaultLanguage(), [ShopLocalization::NAME => 'Overzichttest']);
        $this->productIds[] = $id;

        return $id;
    }

    private function needServer(): void
    {
        if (self::$server === null || !self::answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
    }

    private static function answers(): bool
    {
        $socket = @fsockopen('127.0.0.1', self::$port, $errorCode, $errorMessage, 0.2);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /** @return array{status: int, headers: list<string>, body: string} */
    private function get(string $path): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15]]);
        $body = @file_get_contents('http://127.0.0.1:' . self::$port . $path, false, $context);
        $headers = $http_response_header ?? [];
        preg_match('#^HTTP/\S+\s+(\d{3})#', (string) ($headers[0] ?? ''), $status);

        return ['status' => (int) ($status[1] ?? 0), 'headers' => $headers, 'body' => (string) $body];
    }

    /** @param list<string> $headers */
    private function header(array $headers, string $name): string
    {
        foreach ($headers as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return '';
    }
}
