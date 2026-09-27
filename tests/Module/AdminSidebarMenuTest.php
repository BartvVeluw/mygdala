<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Module\ModuleRegistry;
use App\Module\ShopModule;
use App\Service\AdminNavigation;
use PHPUnit\Framework\TestCase;

/**
 * The Shop as ONE sidebar menu (App\Module\ShopModule::adminNavigationMenus(),
 * App\Service\AdminNavigation, admin/_header.php, admin/assets/admin-sidebar.js).
 *
 * The menu is a module's contribution, like its entries: Core draws a menu
 * wherever an enabled module names one, and names none itself. With the Shop
 * off there is no menu and no entry to put in it. What the signed-in sidebar
 * looks like per permission set is Tests\Service\AdminSidebarMenuHttpTest;
 * this file pins the data and the markup contract, and needs no database.
 */
final class AdminSidebarMenuTest extends TestCase
{
    /** Every screen of a webshop, in the order they have always had; Betalingen after Shop-instellingen. */
    private const SHOP_ENTRIES = [
        'catalog', 'collections', 'related_products', 'personalization', 'shipping',
        'carrier_rates', 'shop_settings', 'payments', 'orders', 'withdrawal_requests',
    ];

    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
    }

    public function testWithTheShopOnItsScreensAreOneShopMenu(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'multilingual' => true]);

        $menus = AdminNavigation::menus();
        $this->assertCount(1, $menus);
        $this->assertSame(ShopModule::ADMIN_MENU, $menus[0]['key']);
        $this->assertSame('Shop', $menus[0]['label']);
        $this->assertSame('shop', $menus[0]['icon']);
        $this->assertSame(300, $menus[0]['order'], 'where Producten stood, so nothing else moves');

        $inMenu = array_values(array_map(
            static fn (array $item): string => $item['key'],
            array_filter(AdminNavigation::items(), static fn (array $item): bool => ($item['menu'] ?? null) === ShopModule::ADMIN_MENU)
        ));
        $this->assertSame(self::SHOP_ENTRIES, $inMenu, 'every Shop screen, and Personalisatie with them, in their own order');

        foreach (AdminNavigation::items() as $item) {
            if (!in_array($item['key'], self::SHOP_ENTRIES, true)) {
                $this->assertArrayNotHasKey('menu', $item, $item['key'] . ' is no Shop screen');
            }
        }
    }

    public function testWithTheShopOffThereIsNoMenuAndNothingToPutInIt(): void
    {
        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => true, 'portfolio' => true, 'multilingual' => true]);

        $this->assertSame([], AdminNavigation::menus());
        $this->assertSame([], array_filter(AdminNavigation::items(), static fn (array $item): bool => isset($item['menu'])));
    }

    public function testCoreNamesNoMenuOfItsOwn(): void
    {
        $navigation = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/AdminNavigation.php');

        $this->assertStringContainsString("ModuleRegistry::collect('adminNavigationMenus')", $navigation);

        $start = (int) strpos($navigation, 'private static function coreItems(): array');
        $coreItems = substr($navigation, $start, (int) strpos($navigation, 'public static function visibleItems', $start) - $start);
        $this->assertNotSame('', $coreItems);
        $this->assertStringNotContainsString("'menu'", $coreItems, 'Core puts none of its own entries in a menu');
        $this->assertStringNotContainsString('ADMIN_MENU', $navigation);
    }

    /**
     * The menu's line is a real button with its state and its target; its
     * entries stay links; it starts open only on its own screens, and without
     * the script every menu is drawn open.
     */
    public function testTheMenuIsARealButtonAndItsEntriesRealLinks(): void
    {
        $header = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/_header.php');

        $this->assertMatchesRegularExpression('/<button type="button" class="admin-sidebar__link admin-sidebar__menu-toggle/', $header);
        $this->assertStringContainsString('aria-expanded="<?= $navMenuCurrent ? \'true\' : \'false\' ?>"', $header);
        $this->assertStringContainsString('aria-controls="<?= htmlspecialchars($navMenuId', $header);
        $this->assertStringContainsString('<div class="admin-sidebar__submenu" id="<?= htmlspecialchars($navMenuId, ENT_QUOTES, \'UTF-8\') ?>"<?= $navMenuCurrent ? \'\' : \' hidden\' ?>>', $header);
        $this->assertStringContainsString("adminNavLink(\$navItem, \$adminActiveGroup === \$navItem['key'], true)", $header);
        $this->assertStringContainsString('<noscript><style>.admin-sidebar__submenu[hidden]{ display: flex; }', $header);
        $this->assertStringContainsString("'/admin/assets/admin-sidebar.js'", $header);
        $this->assertStringContainsString('aria-current="page"', $header);

        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/admin-sidebar.js');
        $this->assertStringContainsString('toggle.setAttribute("aria-expanded", open ? "true" : "false");', $script);
        $this->assertStringContainsString('submenu.hidden = !open;', $script);
        $this->assertStringNotContainsString('localStorage', $script, 'the server decides which menu starts open');
    }

    /** Its line looks like every other line: the button's own look is taken off, not added to. */
    public function testTheMenuLineHasTheShapeOfEveryOtherLine(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/admin.css');

        $this->assertMatchesRegularExpression('/\.admin-sidebar__menu-toggle\{[^}]*background: transparent;[^}]*font: inherit;[^}]*line-height: inherit;/s', $css);
        $this->assertMatchesRegularExpression('/\.admin-sidebar__menu-toggle\[aria-expanded="true"\] \.admin-sidebar__chevron\{ transform: rotate\(180deg\); \}/', $css);
        $this->assertStringContainsString('.admin-sidebar__submenu[hidden]{ display: none; }', $css);
    }
}
