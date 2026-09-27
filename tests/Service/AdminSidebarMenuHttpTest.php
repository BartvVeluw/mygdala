<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ShopModule;
use App\Service\AdminPermissions;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The Shop as one sidebar menu, drawn for real signed-in accounts over HTTP
 * (admin/_header.php, App\Service\AdminNavigation::sidebar()):
 *
 *  - one "Shop" line with a chevron and the Shop's screens under it, and no
 *    Shop screen on a line of its own any more;
 *  - open, with its entry highlighted, on a Shop screen; folded elsewhere;
 *  - only the entries an account may open, and no menu at all for an account
 *    that may open none of them;
 *  - nothing of it with the Shop switched off.
 *
 * The accounts are this test's own and are removed in tearDown(). Without a
 * server the test skips itself.
 */
final class AdminSidebarMenuHttpTest extends TestCase
{
    private const MENU_ENTRIES = [
        '/admin/products.php' => 'Producten',
        '/admin/collections.php' => 'Collecties',
        '/admin/related-products.php' => 'Gerelateerde producten',
        '/admin/personalization.php' => 'Personalisatie',
        '/admin/shipping.php' => 'Verzendinstellingen',
        '/admin/carrier-rates.php' => 'Carrier-tarieven',
        '/admin/shop-settings.php' => 'Shop-instellingen',
        '/admin/payments.php' => 'Betalingen',
        '/admin/orders.php' => 'Bestellingen',
        '/admin/withdrawal-requests.php' => 'Retourverzoeken',
    ];

    private static ?BuiltInServer $shop = null;
    private static ?BuiltInServer $noShop = null;

    private AdminTestSession $accounts;

    public static function setUpBeforeClass(): void
    {
        self::$shop = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true', 'MODULE_PERSONALIZATION_ENABLED' => 'true']);
        self::$noShop = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'false']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$shop?->stop();
        self::$noShop?->stop();
        self::$shop = null;
        self::$noShop = null;
    }

    protected function setUp(): void
    {
        $this->accounts = new AdminTestSession();

        if (self::$shop === null || !self::$shop->answers() || self::$noShop === null || !self::$noShop->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
    }

    protected function tearDown(): void
    {
        $this->accounts->forget();
    }

    public function testOnAShopScreenTheMenuIsOpenAndItsEntryHighlighted(): void
    {
        [$session] = $this->accounts->signIn([], true);

        $sidebar = $this->sidebar(self::$shop->request('GET', '/admin/orders.php', $session)['body']);

        $this->assertSame(1, substr_count($sidebar, 'data-admin-sidebar-menu-toggle'), 'one Shop line');
        $this->assertMatchesRegularExpression('/<button type="button" class="admin-sidebar__link admin-sidebar__menu-toggle is-current"\s+aria-expanded="true" aria-controls="admin-sidebar-menu-shop"/', $sidebar);
        $this->assertStringContainsString('<span>Shop</span>', $sidebar);
        $this->assertStringContainsString('class="admin-sidebar__chevron"', $sidebar);
        $this->assertStringContainsString('<div class="admin-sidebar__submenu" id="admin-sidebar-menu-shop">', $sidebar, 'open: not hidden');
        $this->assertStringContainsString('href="/admin/orders.php" class="admin-sidebar__link admin-sidebar__sublink is-active" aria-current="page"', $sidebar);

        $this->assertSame(self::MENU_ENTRIES, $this->submenu($sidebar), 'every Shop screen, in order, inside the menu');
        foreach (array_keys(self::MENU_ENTRIES) as $url) {
            $this->assertStringNotContainsString('href="' . $url . '" class="admin-sidebar__link"', $sidebar, $url . ' has no line of its own any more');
            $this->assertStringNotContainsString('href="' . $url . '" class="admin-sidebar__link is-active"', $sidebar);
        }

        // The product editor lights up Producten.
        $editor = $this->sidebar(self::$shop->request('GET', '/admin/product-form.php', $session)['body']);
        $this->assertStringContainsString('href="/admin/products.php" class="admin-sidebar__link admin-sidebar__sublink is-active" aria-current="page"', $editor);
    }

    public function testElsewhereTheMenuIsFoldedAndOtherLinesKeepTheirShape(): void
    {
        [$session] = $this->accounts->signIn([], true);

        $sidebar = $this->sidebar(self::$shop->request('GET', '/admin/index.php', $session)['body']);

        $this->assertMatchesRegularExpression('/<button type="button" class="admin-sidebar__link admin-sidebar__menu-toggle"\s+aria-expanded="false"/', $sidebar);
        $this->assertStringContainsString('<div class="admin-sidebar__submenu" id="admin-sidebar-menu-shop" hidden>', $sidebar);
        $this->assertStringContainsString('href="/admin/index.php" class="admin-sidebar__link is-active" aria-current="page"', $sidebar);
        $this->assertStringNotContainsString('admin-sidebar__sublink is-active', $sidebar);
    }

    public function testAnAccountSeesOnlyTheEntriesItMayOpen(): void
    {
        [$orders] = $this->accounts->signIn([ShopModule::ORDERS_VIEW, AdminPermissions::PAGES_MANAGE]);
        $sidebar = $this->sidebar(self::$shop->request('GET', '/admin/pages.php', $orders)['body']);
        $this->assertSame(
            ['/admin/orders.php' => 'Bestellingen', '/admin/withdrawal-requests.php' => 'Retourverzoeken'],
            $this->submenu($sidebar)
        );

        [$pagesOnly] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $sidebar = $this->sidebar(self::$shop->request('GET', '/admin/pages.php', $pagesOnly)['body']);
        $this->assertStringNotContainsString('data-admin-sidebar-menu', $sidebar, 'no empty Shop menu');
        $this->assertStringNotContainsString('admin-sidebar__submenu', $sidebar);
    }

    /** The first screen an account may open is the first line of its sidebar, menus included. */
    public function testTheLandingScreenFollowsTheSidebar(): void
    {
        [$orders] = $this->accounts->signIn([ShopModule::ORDERS_VIEW]);

        $forbidden = self::$shop->request('GET', '/admin/pages.php', $orders);
        $this->assertSame(403, $forbidden['status']);
        $this->assertStringContainsString('href="/admin/orders.php"', $forbidden['body']);
    }

    public function testWithTheShopOffThereIsNoMenuAndNoShopLine(): void
    {
        [$session] = $this->accounts->signIn([], true);

        $page = self::$noShop->request('GET', '/admin/index.php', $session);
        $this->assertSame(200, $page['status']);
        $sidebar = $this->sidebar($page['body']);

        $this->assertStringNotContainsString('data-admin-sidebar-menu', $sidebar);
        $this->assertStringNotContainsString('admin-sidebar__submenu', $sidebar);
        foreach (array_keys(self::MENU_ENTRIES) as $url) {
            $this->assertStringNotContainsString('href="' . $url . '"', $sidebar);
        }
    }

    private function sidebar(string $html): string
    {
        $start = strpos($html, '<nav class="admin-sidebar__nav"');
        self::assertNotFalse($start, 'the shell renders its sidebar');

        return substr($html, (int) $start, (int) strpos($html, '</nav>', (int) $start) - (int) $start);
    }

    /** @return array<string, string> url => label of every entry inside the menu */
    private function submenu(string $sidebar): array
    {
        preg_match_all('/<a href="([^"]+)" class="admin-sidebar__link admin-sidebar__sublink[^"]*"[^>]*><span>([^<]+)<\/span><\/a>/', $sidebar, $matches, PREG_SET_ORDER);

        $entries = [];
        foreach ($matches as $match) {
            $entries[$match[1]] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
        }

        return $entries;
    }
}
