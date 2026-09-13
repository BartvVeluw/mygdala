<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Module\ModuleRegistry;
use App\Module\PersonalizationModule;
use App\Module\ShopModule;
use App\Service\AdminNavigation;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockDefinitions;
use App\Service\ItemGalleryContent;
use App\Service\ItemGallerySources;
use App\Service\PageAssets;
use App\Service\ReservedRoutes;
use App\Service\RouteRegistry;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * What the CMS looks like from the inside with the Shop switched off, and
 * that it still looks exactly like Van Veluw Laserdesign with it on.
 *
 * Everything here is a registry question, so it needs no database and no web
 * server; the same behaviour over real HTTP is Tests\Module\CmsOnlyHttpTest.
 * The two together are the proof that this is a CMS with an optional webshop
 * rather than a webshop with some pages.
 */
final class ShopDisabledTest extends TestCase
{
    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
    }

    private function withShopOff(): void
    {
        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false]);
    }

    private function withEverythingOn(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true]);
    }

    /* ------------------------------------------------------------------ */
    /* Shop enabled: the current site is unchanged                         */
    /* ------------------------------------------------------------------ */

    public function testWithTheShopOnEverythingItOwnsIsThere(): void
    {
        $this->withEverythingOn();

        $navKeys = array_column(AdminNavigation::items(), 'key');
        foreach (['catalog', 'collections', 'related_products', 'personalization', 'shipping', 'carrier_rates', 'shop_settings', 'orders', 'withdrawal_requests'] as $key) {
            $this->assertContains($key, $navKeys, $key . ' must be in the sidebar while the Shop runs');
        }

        foreach ([ShopModule::PRODUCTS_VIEW, ShopModule::ORDERS_VIEW, ShopModule::SHIPPING_MANAGE, PersonalizationModule::PERSONALIZATION_MANAGE] as $permission) {
            $this->assertTrue(AdminPermissions::isEnabled($permission), $permission);
        }

        foreach (['shop', 'cart', 'checkout'] as $route) {
            $this->assertTrue(RouteRegistry::exists($route), $route);
        }

        $this->assertTrue(BlockDefinitions::has('product_grid'));
        $this->assertTrue(BlockDefinitions::has('shop_collections'));
        $this->assertTrue(ItemGallerySources::isAvailable(ShopModule::GALLERY_SOURCE_COLLECTION));

        $this->assertArrayHasKey('products', ModuleRegistry::collectMap('sitemapCollectors'));
        $this->assertArrayHasKey('collections', ModuleRegistry::collectMap('sitemapCollectors'));
        $this->assertArrayHasKey('personalization', ModuleRegistry::collectMap('sitemapCollectors'));

        PageAssets::reset();
        $collected = PageAssets::collected();
        $this->assertContains('assets/css/shop/cart.css', $collected['styles']);
        $this->assertContains('assets/js/shop/cart.js', $collected['scripts']);
    }

    /**
     * The sidebar order the CMS has always had. It is now assembled from
     * three sources (Core, Shop, Personalisatie) and must come out identical.
     */
    public function testTheSidebarOrderIsUnchangedByTheRefactor(): void
    {
        $this->withEverythingOn();

        $this->assertSame(
            [
                'dashboard',
                'pages',
                'media',
                'forms',
                'content_blocks',
                'catalog',
                'collections',
                'related_products',
                'personalization',
                'shipping',
                'carrier_rates',
                'shop_settings',
                'portfolio',
                'orders',
                'withdrawal_requests',
                'contact_requests',
                'form_submissions',
                'navigation',
                'footer',
                'header_footer',
                'settings',
                'theme',
                'redirects',
                'users',
            ],
            array_column(AdminNavigation::items(), 'key')
        );
    }

    /* ------------------------------------------------------------------ */
    /* Shop disabled                                                       */
    /* ------------------------------------------------------------------ */

    public function testNoShopEntryIsLeftInTheAdminSidebar(): void
    {
        $this->withShopOff();

        $navKeys = array_column(AdminNavigation::items(), 'key');

        foreach (['catalog', 'collections', 'related_products', 'personalization', 'shipping', 'carrier_rates', 'shop_settings', 'orders', 'withdrawal_requests'] as $key) {
            $this->assertNotContains($key, $navKeys, $key . ' must be gone from the sidebar');
        }

        // ...and the CMS's own sections are all still there.
        foreach (['dashboard', 'pages', 'media', 'portfolio', 'contact_requests', 'navigation', 'footer', 'settings', 'users'] as $key) {
            $this->assertContains($key, $navKeys, $key . ' is Core and must stay');
        }
    }

    /**
     * The single rule that closes every Shop admin screen and all 174 write
     * endpoints at once: nobody holds a disabled module's permission, not
     * even a Super Admin. Each of those files already asks
     * AdminAuth::requirePermission(), so none of them needed a second check.
     */
    public function testNobodyHoldsAShopPermissionWhileTheShopIsOff(): void
    {
        $this->withShopOff();

        $superAdmin = ['is_super_admin' => true, 'permissions' => AdminPermissions::all()];
        $everything = ['is_super_admin' => false, 'permissions' => AdminPermissions::all()];

        foreach (
            [
                ShopModule::PRODUCTS_VIEW, ShopModule::PRODUCTS_MANAGE, ShopModule::COLLECTIONS_MANAGE,
                ShopModule::SHIPPING_MANAGE, ShopModule::ORDERS_VIEW, ShopModule::ORDERS_MANAGE,
                PersonalizationModule::PERSONALIZATION_MANAGE,
            ] as $permission
        ) {
            $this->assertFalse(AdminPermissions::isEnabled($permission), $permission . ' must not be holdable');
            $this->assertFalse(AdminPermissions::userHas($superAdmin, $permission), $permission . ' — super admin');
            $this->assertFalse(AdminPermissions::userHas($everything, $permission), $permission . ' — granted user');
            $this->assertNotContains($permission, AdminPermissions::enabled());
        }

        // Core's own permissions are untouched.
        $this->assertTrue(AdminPermissions::userHas($superAdmin, AdminPermissions::PAGES_MANAGE));
        $this->assertTrue(AdminPermissions::isEnabled(AdminPermissions::PORTFOLIO_MANAGE));
    }

    /**
     * Stored grants are DATA, not runtime state. A colleague's
     * `products.manage` row survives the Shop being switched off and comes
     * back the moment it is switched on — the module system never rewrites
     * `admin_user_permissions`.
     */
    public function testStoredShopGrantsSurviveTheShopBeingSwitchedOff(): void
    {
        $stored = [ShopModule::PRODUCTS_MANAGE, AdminPermissions::PAGES_MANAGE];

        $this->withShopOff();

        $this->assertTrue(AdminPermissions::isValid(ShopModule::PRODUCTS_MANAGE), 'the name must stay valid');
        $this->assertSame($stored, AdminPermissions::sanitize($stored), 'sanitize() must not drop it');
        $this->assertContains(ShopModule::PRODUCTS_MANAGE, AdminPermissions::expand($stored));
        $this->assertContains(ShopModule::PRODUCTS_MANAGE, AdminPermissions::all());

        // But it is not offered on the user form while the Shop is off.
        $labels = [];
        foreach (AdminPermissions::groups() as $group) {
            $labels[] = $group['label'];
        }
        $this->assertNotContains('Shop', $labels);
        $this->assertNotContains('Bestellingen', $labels);
        $this->assertNotContains('Personalisatie', $labels);
        $this->assertContains('Website', $labels);

        // ...and switching the Shop back on restores it exactly.
        $this->withEverythingOn();
        $this->assertTrue(AdminPermissions::isEnabled(ShopModule::PRODUCTS_MANAGE));
    }

    public function testShopRoutesAreNotOfferedAsLinkDestinations(): void
    {
        $this->withShopOff();

        foreach (['shop', 'cart', 'checkout'] as $route) {
            $this->assertFalse(RouteRegistry::exists($route), $route . ' must not be a link destination');
            $this->assertNull(RouteRegistry::url($route));
        }

        foreach (['home', 'cookiebeleid', 'herroeping'] as $route) {
            $this->assertTrue(RouteRegistry::exists($route), $route . ' is Core and must stay');
        }
    }

    /**
     * The one place the enabled/disabled distinction deliberately does NOT
     * apply: shop.php and friends are still on disk, so their names stay
     * reserved. A CMS page that claimed one would be permanently unreachable
     * behind the file that shadows it.
     */
    public function testAModulesSlugsStayReservedWhileItIsOff(): void
    {
        $this->withShopOff();

        foreach (['shop', 'product', 'collectie', 'collecties', 'cart', 'checkout', 'bestelling-status', 'personaliseren'] as $slug) {
            $this->assertTrue(ReservedRoutes::isReserved($slug), $slug . ' must stay reserved');
        }
    }

    public function testShopBlocksCannotBeAddedOrRenderedWhileTheShopIsOff(): void
    {
        $this->withShopOff();

        foreach (['product_grid', 'shop_collections'] as $type) {
            $this->assertFalse(BlockDefinitions::has($type), $type . ' must not be registered');
            $this->assertFalse(SectionRegistry::exists($type));
            $this->assertNull(BlockDefinitions::get($type), 'an unregistered type must never resolve to a class');
            $this->assertNotContains($type, SectionRegistry::applicationCriticalTypes());

            // ...and the write path refuses it outright rather than guessing.
            $refusal = null;

            try {
                SectionRegistry::create($type, '__test__');
            } catch (\Throwable $e) {
                $refusal = $e->getMessage();
            }

            $this->assertNotNull($refusal, 'creating a disabled module block must throw, never no-op');
            $this->assertStringContainsString($type, (string) $refusal);
        }
    }

    /**
     * "Module switched off" and "this data is broken" are different, and the
     * CMS must be able to say which. Both leave the row alone.
     */
    public function testADisabledModulesBlockIsRecognisedRatherThanCalledUnknown(): void
    {
        $this->withShopOff();

        $this->assertSame('shop', SectionRegistry::disabledModuleFor('product_grid'));
        $this->assertSame('shop', SectionRegistry::disabledModuleFor('shop_collections'));
        $this->assertNull(SectionRegistry::disabledModuleFor('__never_shipped__'));
        $this->assertNull(SectionRegistry::disabledModuleFor('rich_text'), 'a registered Core block is not "disabled"');

        $this->assertSame('Shop', ModuleRegistry::label('shop'));
    }

    public function testCoreBlocksAreAllStillRegisteredWithTheShopOff(): void
    {
        $this->withShopOff();

        foreach (
            [
                'homepage_hero', 'page_hero', 'rich_text', 'cta_band', 'feature_grid', 'faq', 'stat_strip',
                'step_list', 'text_image_split', 'marquee', 'contact_form', 'contact_card', 'detail_section',
                'card_carousel', 'item_gallery', 'quicknav',
            ] as $type
        ) {
            $this->assertTrue(BlockDefinitions::has($type), $type . ' is a Core block and must stay');
        }
    }

    public function testTheCollectionGallerySourceIsNotSelectableWhileTheShopIsOff(): void
    {
        $this->withShopOff();

        $this->assertSame(['portfolio'], array_keys(ItemGallerySources::available()));
        $this->assertFalse(ItemGalleryContent::isSource(ShopModule::GALLERY_SOURCE_COLLECTION));
        $this->assertFalse(ItemGallerySources::isAvailable(ShopModule::GALLERY_SOURCE_COLLECTION));

        // Still KNOWN, so an existing block that names it is preserved and
        // explained rather than rewritten to a different source.
        $this->assertTrue(ItemGallerySources::isKnown(ShopModule::GALLERY_SOURCE_COLLECTION));
        $this->assertSame('shop', ItemGallerySources::moduleOwnerOf(ShopModule::GALLERY_SOURCE_COLLECTION));

        // And it yields nothing rather than somebody else's content.
        $this->assertSame([], ItemGallerySources::items(ShopModule::GALLERY_SOURCE_COLLECTION, ['collection_id' => 1]));
    }

    public function testNoShopSitemapCollectorRunsWhileTheShopIsOff(): void
    {
        $this->withShopOff();

        $this->assertSame([], ModuleRegistry::collectMap('sitemapCollectors'));
    }

    public function testNoShopAssetIsInTheSiteShellWhileTheShopIsOff(): void
    {
        $this->withShopOff();

        PageAssets::reset();
        $collected = PageAssets::collected();

        $this->assertSame(['assets/css/core.css'], $collected['styles']);
        $this->assertSame(['assets/js/core.js'], $collected['scripts']);
        $this->assertSame([], $collected['vendor']);
    }

    public function testNothingIsRenderedIntoTheHeaderWhileTheShopIsOff(): void
    {
        $this->withShopOff();

        $this->assertSame([], ModuleRegistry::collect('headerPartials'));
        $this->assertSame([], ModuleRegistry::collect('dashboardPanels'));
        $this->assertSame([], ModuleRegistry::collect('dashboardCards'));
    }

    /* ------------------------------------------------------------------ */
    /* Core no longer reaches into the Shop                                */
    /* ------------------------------------------------------------------ */

    /**
     * The point of the whole step, asserted rather than described: the Core
     * integration classes that used to name a Shop repository or a Shop
     * screen no longer do. A concrete Shop class in one of these files is how
     * the coupling grew back last time.
     *
     * Named files rather than line numbers, and searched for class names
     * rather than positions, so an edit anywhere in them cannot make this
     * test lie.
     */
    public function testCoreIntegrationClassesNameNoShopImplementation(): void
    {
        $forbidden = [
            'ProductRepository', 'CollectionRepository', 'OrderRepository', 'DashboardRepository',
            'ProductPersonalizationRepository', 'ProductSeo', 'CollectionContent',
            'ProductGridBlock', 'ShopCollectionsBlock', 'PersonalizationCatalog',
        ];

        $files = [
            'src/Service/Sitemap.php',
            // The SEO layer is Core, and it stays Core: the shared renderer
            // and the metadata it renders know nothing about products or
            // collections. The Shop resolves its own metadata in its own
            // read models and hands the result over.
            'src/Service/SeoMetadata.php',
            'src/Service/SeoDefaults.php',
            'src/Service/PageSeo.php',
            'src/Service/Robots.php',
            'partials/seo-head.php',
            'src/Service/AdminNavigation.php',
            'src/Service/AdminPermissions.php',
            'src/Service/RouteRegistry.php',
            'src/Service/ReservedRoutes.php',
            'src/Service/ItemGalleryContent.php',
            'src/Service/PageAssets.php',
            'src/Service/Blocks/BlockDefinitions.php',
            'src/Service/SectionRegistry.php',
            'admin/index.php',
            'partials/header.php',
        ];

        foreach ($files as $file) {
            $source = $this->withoutComments((string) file_get_contents(dirname(__DIR__, 2) . '/' . $file));

            foreach ($forbidden as $class) {
                $this->assertStringNotContainsString(
                    $class,
                    $source,
                    $file . ' must reach the Shop through a module contribution, not by naming ' . $class
                );
            }
        }
    }

    /** Core's site shell and header name no Shop asset either. */
    public function testCoreNamesNoShopAssetPath(): void
    {
        foreach (['src/Service/PageAssets.php', 'partials/header.php'] as $file) {
            $source = $this->withoutComments((string) file_get_contents(dirname(__DIR__, 2) . '/' . $file));

            $this->assertStringNotContainsString('assets/css/shop/', $source, $file);
            $this->assertStringNotContainsString('assets/js/shop/', $source, $file);
        }
    }

    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}
