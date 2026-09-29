<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\SiteSettingRepository;
use App\Service\PageContent;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\PageFixture;
use Tests\Support\TemplatePageFixture;
use Tests\Support\TestEnvironment;

/**
 * The CMS-only deployment, over real HTTP: the same code and the same test
 * database as every other HTTP test, served by a web server that was STARTED
 * with MODULE_SHOP_ENABLED=false (the php_cms container in
 * docker-compose.yml).
 *
 * A separate server rather than a flag, because module configuration is read
 * from the environment a process was started with; nothing a test does from
 * the outside can change that for an already-running server, and a config
 * file would be shared with the development site. The in-process half of the
 * same behaviour is Tests\Module\ShopDisabledTest.
 *
 * This is the proof the whole step is for: the application boots, serves its
 * pages and runs its admin without a webshop — and none of the webshop leaks
 * through anyway. Skips itself when that server is not running, exactly like
 * the rest of the HTTP tier, so it never fails for the wrong reason.
 */
final class CmsOnlyHttpTest extends TestCase
{
    /**
     * The address of the page this test creates as "a page the owner made
     * themselves": an ordinary page served by the generic template. Made of
     * slug characters, so .htaccess rewrites it to pagina.php.
     */
    private const OWN_PAGE_SLUG = 'zz-cms-only-eigen-pagina';

    /** @var list<int> pages this test created, removed again in tearDown() */
    private array $templatePageIds = [];

    private ?int $ownPageId = null;

    /** @var array<string, string|null> site settings this test wrote, with what they held before (null: no row) */
    private array $originalSettings = [];

    protected function setUp(): void
    {
        if (!TestEnvironment::cmsOnlySiteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::cmsOnlyUnreachableMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->ownPageId !== null) {
            (new PageRepository())->delete($this->ownPageId);
            $this->ownPageId = null;
        }
        TemplatePageFixture::remove($this->templatePageIds);
        $this->templatePageIds = [];

        if ($this->originalSettings !== []) {
            $repository = new SiteSettingRepository();
            foreach ($this->originalSettings as $key => $value) {
                if ($value === null) {
                    Database::connection()
                        ->prepare('DELETE FROM site_settings WHERE setting_key = :key')
                        ->execute(['key' => $key]);
                } else {
                    $repository->upsertMany([$key => $value]);
                }
            }
            $this->originalSettings = [];
            SiteSettings::clearCache();
        }
    }

    /**
     * The CMS pages this test requests, brought by the test itself: the
     * three pages with a root-level template (a fresh installation has none
     * of them, and their URL then 404s exactly as it should) and one
     * ordinary page of the owner's own. Both servers share the test
     * database, so what is made here is what php_cms serves.
     */
    private function bringTheCmsPages(): void
    {
        $this->templatePageIds = TemplatePageFixture::ensureAll(['diensten', 'over-mij', 'contact']);

        if ((new PageRepository())->findByContentKey(self::OWN_PAGE_SLUG) === null) {
            $this->ownPageId = PageFixture::create([
                'content_key' => self::OWN_PAGE_SLUG,
                'slug' => self::OWN_PAGE_SLUG,
                'status' => PageContent::STATUS_PUBLISHED,
            ], 'ZZ Eigen pagina');
        }
        PageContent::clearCache();
    }

    /**
     * Writes site settings for this test; tearDown() puts back exactly what
     * was there before, a missing row included.
     *
     * @param array<string, string> $values
     */
    private function setSiteSettings(array $values): void
    {
        $repository = new SiteSettingRepository();
        $stored = $repository->findAll();
        foreach (array_keys($values) as $key) {
            if (!array_key_exists($key, $this->originalSettings)) {
                $this->originalSettings[$key] = array_key_exists($key, $stored) ? $stored[$key] : null;
            }
        }

        $repository->upsertMany($values);
        SiteSettings::clearCache();
    }

    /**
     * @return array{status: int, body: string, headers: string}
     */
    private function get(string $path): array
    {
        $handle = curl_init(TestEnvironment::cmsOnlyBaseUrl() . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        return [
            'status' => $status,
            'headers' => substr($response, 0, $headerSize),
            'body' => substr($response, $headerSize),
        ];
    }

    private function post(string $path, array $fields): int
    {
        $handle = curl_init(TestEnvironment::cmsOnlyBaseUrl() . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
        ]);

        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return $status;
    }

    /* ------------------------------------------------------------------ */
    /* It is a working CMS                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, array{0: string}>
     */
    public static function cmsPages(): array
    {
        return [
            'homepage' => ['/index.php'],
            'diensten' => ['/diensten.php'],
            'over mij' => ['/over-mij.php'],
            'contact' => ['/contact.php'],
            // A page the owner created themselves, served by the generic
            // template — the case that proves this is a CMS and not five
            // hardcoded files.
            'own page' => ['/' . self::OWN_PAGE_SLUG],
            'legal page' => ['/cookiebeleid.php'],
            'sitemap' => ['/sitemap.xml'],
        ];
    }

    /**
     * @dataProvider cmsPages
     */
    public function testTheCmsStillServesItsPages(string $path): void
    {
        $this->bringTheCmsPages();

        $response = $this->get($path);

        $this->assertSame(200, $response['status'], $path . ' must render on a CMS-only deployment');
        $this->assertStringNotContainsString('Fatal error', $response['body']);
        $this->assertStringNotContainsString('Uncaught', $response['body']);
    }

    public function testThePageStillHasItsHeaderFooterAndContentBlocks(): void
    {
        $body = $this->get('/index.php')['body'];

        $this->assertStringContainsString('<header class="site-header"', $body);
        $this->assertStringContainsString('site-footer', $body);
        $this->assertStringContainsString('assets/css/core.css', $body);
        $this->assertStringContainsString('assets/js/core.js', $body);

        // Content blocks still render, and still bring their own assets.
        $this->assertMatchesRegularExpression('#assets/css/blocks/[a-z-]+\.css#', $body);
    }

    public function testTheAdminShellStillAnswers(): void
    {
        $response = $this->get('/admin/login.php');

        $this->assertSame(200, $response['status'], 'the CMS login must work without a webshop');
        $this->assertStringContainsString('csrf_token', $response['body']);
    }

    /* ------------------------------------------------------------------ */
    /* And nothing of the Shop leaks through                               */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, array{0: string}>
     */
    public static function shopRoutes(): array
    {
        return [
            'storefront' => ['/shop.php'],
            'cart' => ['/cart.php'],
            'checkout' => ['/checkout.php'],
            'product' => ['/product.php?id=1'],
            'collection' => ['/collecties/whatever'],
            'order status' => ['/bestelling-status.php?order=1'],
            'personalization catalogue' => ['/personaliseren.php'],
        ];
    }

    /**
     * @dataProvider shopRoutes
     */
    public function testAShopRouteIs404WhileTheShopIsOff(string $path): void
    {
        $response = $this->get($path);

        $this->assertSame(404, $response['status'], $path . ' must not answer');
        $this->assertStringContainsString('Pagina niet gevonden', $response['body'], $path . ' must render the CMS 404');
        $this->assertStringNotContainsString('data-products-grid', $response['body']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function shopReadEndpoints(): array
    {
        return [
            'products' => ['/api/products.php'],
            'product' => ['/api/product.php?id=1'],
            'shipping zones' => ['/api/shipping-zones.php'],
            'order status' => ['/api/order-status.php?order=1'],
            'address lookup' => ['/api/address-lookup-nl.php?postcode=6511AA&number=1'],
            'personalization image' => ['/api/personalization-image.php?token=abc'],
        ];
    }

    /**
     * @dataProvider shopReadEndpoints
     */
    public function testAShopEndpointRefusesWhileTheShopIsOff(string $path): void
    {
        $response = $this->get($path);

        $this->assertSame(404, $response['status'], $path . ' must not answer');
        $this->assertStringNotContainsString('{"', $response['body'], $path . ' must return no data at all');
    }

    /**
     * A write must be refused BEFORE anything is read, validated or stored —
     * not merely fail somewhere inside. Nothing here carries a valid CSRF
     * token either, so a 404 rather than a CSRF error is what proves the
     * module guard ran first.
     */
    public function testAShopWriteEndpointStoresNothingWhileTheShopIsOff(): void
    {
        foreach (
            [
                '/api/checkout.php' => ['items' => [], 'email' => 'nobody@example.com'],
                '/api/shipping-quote.php' => ['country' => 'NL', 'subtotal' => '10.00'],
                '/api/cart-check.php' => ['items' => []],
                '/api/stock-notification.php' => ['product_id' => '1', 'email' => 'nobody@example.com'],
                '/api/withdrawal-request.php' => ['order' => '1', 'email' => 'nobody@example.com'],
                '/api/mollie-webhook.php' => ['id' => 'tr_fake'],
                '/api/personalization-upload.php' => ['product_id' => '1'],
                '/api/order-field-upload.php' => ['product_id' => '1', 'field_id' => '1'],
            ] as $path => $fields
        ) {
            $this->assertSame(404, $this->post($path, $fields), $path . ' must reject the write');
        }
    }

    /**
     * The Portfolio is off on this server as well — pinned in
     * docker-compose.yml, because a test database copied from an existing
     * site stores it as on. Its page and a project page answer like URLs that
     * never existed; the in-process half is Tests\Module\PortfolioModuleTest.
     */
    public function testThePortfolioRoutesAre404WhileThePortfolioIsOff(): void
    {
        foreach (['/portfolio', '/portfolio.php', '/portfolio/whatever'] as $path) {
            $response = $this->get($path);

            $this->assertSame(404, $response['status'], $path . ' must not answer');
            $this->assertStringContainsString('Pagina niet gevonden', $response['body'], $path . ' must render the CMS 404');
        }
    }

    public function testNoShopStylesheetOrScriptIsLoadedAnywhere(): void
    {
        $this->bringTheCmsPages();

        foreach (['/index.php', '/contact.php', '/' . self::OWN_PAGE_SLUG] as $path) {
            $response = $this->get($path);
            $this->assertSame(200, $response['status'], $path . ' must render, or this asserts nothing');
            $body = $response['body'];

            $this->assertStringNotContainsString('assets/css/shop/', $body, $path);
            $this->assertStringNotContainsString('assets/js/shop/', $body, $path);
            $this->assertStringNotContainsString('assets/js/personalization.js', $body, $path);
        }
    }

    /**
     * The theme is Core, so a CMS-only deployment must still get all of it:
     * the stylesheet that declares the tokens, the font for the selected
     * pairing, and the theme-coloured browser chrome. Switching the Shop off
     * changes what a page loads, never how it looks.
     *
     * The favicon is branding the owner configures (App\Service\Branding):
     * an installation without one renders no <link rel="icon"> at all, so
     * the test configures one itself to see that it still arrives.
     */
    public function testTheThemeIsStillCompleteWithoutTheShop(): void
    {
        $this->bringTheCmsPages();
        $this->setSiteSettings(['favicon_path' => 'assets/images/zz-cms-only-favicon.png', 'favicon_media_id' => '']);

        foreach (['/index.php', '/contact.php'] as $path) {
            $response = $this->get($path);
            $this->assertSame(200, $response['status'], $path);
            $body = $response['body'];

            $this->assertStringContainsString('assets/css/core.css', $body, $path);
            $this->assertStringContainsString('fonts.googleapis.com/css2', $body, $path);
            $this->assertStringContainsString('<meta name="theme-color" content="#', $body, $path);
            $this->assertStringContainsString(
                '<link rel="icon" href="/assets/images/zz-cms-only-favicon.png" type="image/png">',
                $body,
                $path
            );
        }
    }

    public function testTheSharedHeaderRendersNoMiniCart(): void
    {
        $this->bringTheCmsPages();

        foreach (['/index.php', '/contact.php'] as $path) {
            $response = $this->get($path);
            $this->assertSame(200, $response['status'], $path . ' must render, or this asserts nothing');
            $body = $response['body'];

            $this->assertStringNotContainsString('data-cart-trigger', $body, $path);
            $this->assertStringNotContainsString('cart-dropdown', $body, $path);
            $this->assertStringNotContainsString('/cart.php', $body, $path);
            $this->assertStringNotContainsString('/checkout.php', $body, $path);
        }
    }

    public function testTheSitemapAdvertisesNoShopUrl(): void
    {
        $this->bringTheCmsPages();

        $body = $this->get('/sitemap.xml')['body'];

        $this->assertStringContainsString('<urlset', $body);
        $this->assertStringContainsString('/contact.php</loc>', $body, 'CMS pages must still be listed');
        $this->assertStringContainsString('/' . self::OWN_PAGE_SLUG . '</loc>', $body, 'so must a page the owner made');

        foreach (['/shop.php', '/product.php', '/collecties/', '/personaliseren'] as $shopUrl) {
            $this->assertStringNotContainsString($shopUrl, $body, $shopUrl . ' must not be advertised');
        }
    }

    public function testTheCmsHeadIsCompleteWithoutTheShop(): void
    {
        $body = $this->get('/')['body'];

        // The whole SEO head is Core's, so a CMS-only deployment loses none
        // of it: title, robots, canonical and the share preview are all
        // still there.
        $this->assertStringContainsString('name="robots" content="index,follow"', $body);
        $this->assertStringContainsString('<link rel="canonical" href="', $body);
        $this->assertStringContainsString('property="og:title"', $body);
        $this->assertStringContainsString('property="og:url"', $body);
    }

    public function testRobotsTxtStillWorksWithoutTheShop(): void
    {
        $response = $this->get('/robots.txt');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('User-agent: *', $response['body']);
        $this->assertStringContainsString('Sitemap: ', $response['body']);
    }

    /**
     * Every Shop admin screen is closed by the permission rule alone (nobody
     * holds a disabled module's permission), so a signed-out request gets the
     * ordinary login redirect and a signed-in one would get the CMS's own 403.
     * What matters here is that none of them renders a working screen.
     */
    public function testShopAdminScreensDoNotRender(): void
    {
        foreach (
            [
                '/admin/products.php', '/admin/collections.php', '/admin/orders.php',
                '/admin/shipping.php', '/admin/personalization.php', '/admin/related-products.php',
                '/admin/shop-settings.php',
            ] as $path
        ) {
            $response = $this->get($path);

            $this->assertContains($response['status'], [302, 403, 404], $path . ' must not render');
            $this->assertStringNotContainsString('<table class="admin-table"', $response['body'], $path);
        }
    }
}
