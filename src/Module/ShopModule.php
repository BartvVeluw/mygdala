<?php

declare(strict_types=1);

namespace App\Module;

use App\Service\Language\AdminTranslator;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\AdminPermissions;
use App\Service\AppUrl;
use App\Service\Blocks\BlockCategories;
use App\Service\Blocks\FeaturedProductBlock;
use App\Service\Blocks\ProductGridBlock;
use App\Service\Blocks\ShopCollectionsBlock;
use App\Service\CollectionContent;
use App\Service\CollectionGalleryItems;
use App\Service\PageContent;
use App\Service\ProductSeo;
use App\Service\ShopMediaUsage;
use App\Service\ShopOverview;
use App\Service\Sitemap;

/**
 * The webshop as a first-party module: products, variants, collections,
 * related products, the cart, checkout, orders, payments, invoices and
 * shipping.
 *
 * Everything below used to be hardcoded somewhere in Core — the admin
 * sidebar, the permission list, the route picker, the reserved slugs, the
 * sitemap, the block registry, the item-gallery sources, the site shell's
 * asset list, the public header and the dashboard. This class is now the only
 * place Core learns that a webshop exists. See MODULES.md.
 *
 * The Shop's FILES have not moved (that is deliberately deferred): its
 * services, repositories, admin screens and endpoints still live beside
 * Core's. What moved is the ownership of the integration points.
 *
 * PERMISSIONS. The names below are the same strings that have always been
 * stored in `admin_user_permissions`, so switching the Shop off and on again
 * cannot lose a grant. They are constants HERE rather than on
 * App\Service\AdminPermissions because that class is Core and must not know
 * what a product is; the class remains the one authority on what a valid
 * permission is, it just no longer authors this half of the list.
 */
final class ShopModule extends ModuleDefinition
{
    public const PRODUCTS_VIEW = 'products.view';
    public const PRODUCTS_MANAGE = 'products.manage';
    public const COLLECTIONS_MANAGE = 'collections.manage';
    public const SHIPPING_MANAGE = 'shipping.manage';
    public const ORDERS_VIEW = 'orders.view';
    public const ORDERS_MANAGE = 'orders.manage';

    /**
     * Shop → Betalingen: the Mollie API keys, test or live, and the payment
     * methods (MODULES.md, "Betalingen"). Whoever holds it decides which
     * Mollie account customers pay into, so it is its own grant rather than
     * part of settings.manage, and only a Super Admin can hand it out
     * (superAdminGrantablePermissions()). Nobody holds it after an update
     * except the Super Admins, who hold everything.
     */
    public const PAYMENTS_MANAGE = 'payments.manage';

    /** The item-gallery source key this module contributes. */
    public const GALLERY_SOURCE_COLLECTION = 'collection';

    /**
     * The sidebar menu every Shop screen sits in (adminNavigationMenus()).
     * A module that depends on the Shop may put its own screen in it too,
     * as Personalisatie does.
     */
    public const ADMIN_MENU = 'shop';

    public function key(): string
    {
        return 'shop';
    }

    public function label(): string
    {
        return 'Shop';
    }

    public function description(): string
    {
        return 'Producten, collecties, winkelwagen, afrekenen, bestellingen, facturen en verzending.';
    }

    /**
     * ONE line in the sidebar, "Shop", instead of a line per screen: the
     * screens of a webshop (ten since Betalingen) crowded out everything
     * else a site owner edits. Its order is where Producten used to stand,
     * so the rest of the sidebar keeps its place; the screens keep their own
     * orders inside it.
     */
    public function adminNavigationMenus(): array
    {
        return [
            [
                'key' => self::ADMIN_MENU,
                'label' => 'Shop',
                'icon' => 'shop',
                'order' => 300,
            ],
        ];
    }

    public function adminNavigationItems(): array
    {
        return [
            [
                'key' => 'catalog',
                'label' => 'Producten',
                'url' => '/admin/products.php',
                'icon' => 'products',
                'permission' => self::PRODUCTS_VIEW,
                'order' => 300,
                'scripts' => ['products.php', 'product-form.php'],
                'menu' => self::ADMIN_MENU,
            ],
            [
                // The library of reusable product properties (Dikte, Hoogte,
                // Materiaal) whose values the product editor fills in — Shop
                // Product & Ordering 2.0, App\Service\ProductSpecifications.
                'key' => 'specifications',
                'label' => 'Specificaties',
                'url' => '/admin/product-specifications.php',
                'icon' => 'specifications',
                'permission' => self::PRODUCTS_MANAGE,
                'order' => 305,
                'scripts' => ['product-specifications.php'],
                'menu' => self::ADMIN_MENU,
            ],
            [
                'key' => 'collections',
                'label' => 'Collecties',
                'url' => '/admin/collections.php',
                'icon' => 'collections',
                'permission' => self::COLLECTIONS_MANAGE,
                'order' => 310,
                'scripts' => ['collections.php', 'collection.php'],
                'menu' => self::ADMIN_MENU,
            ],
            [
                // Configuration of the automatic related-products section on
                // product pages. It sits right after Collecties because
                // collections are exactly what it configures — see
                // App\Service\RelatedProductsContent.
                'key' => 'related_products',
                'label' => 'Gerelateerde producten',
                'url' => '/admin/related-products.php',
                'icon' => 'related_products',
                'permission' => self::COLLECTIONS_MANAGE,
                'order' => 320,
                'scripts' => ['related-products.php'],
                'menu' => self::ADMIN_MENU,
            ],
            [
                'key' => 'shipping',
                'label' => 'Verzendinstellingen',
                'url' => '/admin/shipping.php',
                'icon' => 'shipping',
                'permission' => self::SHIPPING_MANAGE,
                'order' => 340,
                'scripts' => ['shipping.php'],
                'menu' => self::ADMIN_MENU,
            ],
            [
                'key' => 'carrier_rates',
                'label' => 'Carrier-tarieven',
                'url' => '/admin/carrier-rates.php',
                'icon' => 'carrier_rates',
                'permission' => self::SHIPPING_MANAGE,
                'order' => 350,
                'scripts' => ['carrier-rates.php'],
                'menu' => self::ADMIN_MENU,
            ],
            [
                // Invoices, order numbers and the order confirmation e-mail:
                // two tabs of Instellingen once, and meaningless without
                // a shop. settings.manage, the permission those tabs asked, so
                // the move changed nobody's access; that is also why
                // admin/shop-settings.php carries a ModuleGuard of its own.
                'key' => 'shop_settings',
                'label' => 'Shop-instellingen',
                'url' => '/admin/shop-settings.php',
                'icon' => 'shop_settings',
                'permission' => AdminPermissions::SETTINGS_MANAGE,
                'order' => 360,
                'scripts' => ['shop-settings.php'],
                'menu' => self::ADMIN_MENU,
            ],
            [
                // Mollie: the keys, test or live, and the setup guide
                // (MODULES.md, "Betalingen"). Its own permission, which only
                // a Super Admin can grant: whoever holds it decides where
                // the money goes.
                'key' => 'payments',
                'label' => 'Betalingen',
                'url' => '/admin/payments.php',
                'icon' => 'payments',
                'permission' => self::PAYMENTS_MANAGE,
                'order' => 365,
                'scripts' => ['payments.php'],
                'menu' => self::ADMIN_MENU,
            ],
            [
                'key' => 'orders',
                'label' => 'Bestellingen',
                'url' => '/admin/orders.php',
                'icon' => 'orders',
                'permission' => self::ORDERS_VIEW,
                'order' => 500,
                'scripts' => ['orders.php', 'order.php', 'orders-export.php'],
                'menu' => self::ADMIN_MENU,
            ],
            [
                'key' => 'withdrawal_requests',
                'label' => 'Retourverzoeken',
                'url' => '/admin/withdrawal-requests.php',
                'icon' => 'withdrawal_requests',
                'permission' => self::ORDERS_VIEW,
                'order' => 510,
                'scripts' => ['withdrawal-requests.php', 'withdrawal-request.php'],
                'menu' => self::ADMIN_MENU,
            ],
        ];
    }

    public function permissionGroups(): array
    {
        return [
            [
                'label' => 'Shop',
                'order' => 200,
                'permissions' => [
                    self::PRODUCTS_VIEW => [
                        'label' => 'Producten bekijken',
                        'description' => 'Het productoverzicht inzien, zonder iets te kunnen wijzigen.',
                    ],
                    self::PRODUCTS_MANAGE => [
                        'label' => 'Producten beheren',
                        'description' => 'Producten, varianten, opties en foto\'s aanmaken, wijzigen en verwijderen. Bevat automatisch "Producten bekijken".',
                    ],
                    self::COLLECTIONS_MANAGE => [
                        'label' => 'Collecties beheren',
                        'description' => 'Shop-collecties aanmaken, wijzigen, verwijderen en sorteren.',
                    ],
                    self::SHIPPING_MANAGE => [
                        'label' => 'Verzending beheren',
                        'description' => 'Verzendzones, verzendtarieven en de PostNL-carriertarieven.',
                    ],
                    self::PAYMENTS_MANAGE => [
                        'label' => 'Betalingen beheren',
                        'description' => 'De Mollie-sleutels, test of live en de betaalmethoden. Bepaalt op welk Mollie-account klanten betalen; alleen een Super Admin kan dit toekennen.',
                    ],
                ],
            ],
            [
                'label' => 'Bestellingen',
                'order' => 300,
                'permissions' => [
                    self::ORDERS_VIEW => [
                        'label' => 'Bestellingen bekijken',
                        'description' => 'Bestellingen, retourverzoeken, facturen en de CSV-export inzien.',
                    ],
                    self::ORDERS_MANAGE => [
                        'label' => 'Bestellingen beheren',
                        'description' => 'Afhandelingsstatus wijzigen, facturen genereren, bevestigingsmails opnieuw versturen en retourverzoeken afhandelen. Bevat automatisch "Bestellingen bekijken".',
                    ],
                ],
            ],
        ];
    }

    public function superAdminGrantablePermissions(): array
    {
        return [self::PAYMENTS_MANAGE];
    }

    public function permissionImplications(): array
    {
        return [
            // Choosing a product's or a collection's pictures means picking
            // from (and uploading into) the Media Library, exactly as editing
            // a page or a blog post does (MEDIA.md, "Rechten").
            self::PRODUCTS_MANAGE => [self::PRODUCTS_VIEW, AdminPermissions::MEDIA_VIEW],
            self::COLLECTIONS_MANAGE => [AdminPermissions::MEDIA_VIEW],
            self::ORDERS_MANAGE => [self::ORDERS_VIEW],
        ];
    }

    /**
     * The storefront route is only there while the site HAS an overview
     * (App\Service\ShopOverview): with "Geen overzichtspagina" /shop.php
     * answers 404, so a menu item or breadcrumb that names this route drops
     * out instead of linking to it. With a chosen page, /shop.php sends a
     * visitor to that page, so an existing link keeps working.
     */
    public function routes(): array
    {
        $storefront = ShopOverview::mode() === ShopOverview::NONE
            ? []
            : ['shop' => ['url' => '/shop.php', 'label' => ['nl' => 'Shop', 'en' => 'Shop'], 'order' => 20]];

        return $storefront + [
            'cart' => ['url' => '/cart.php', 'label' => ['nl' => 'Winkelwagen', 'en' => 'Cart'], 'order' => 30],
            'checkout' => ['url' => '/checkout.php', 'label' => ['nl' => 'Afrekenen', 'en' => 'Checkout'], 'order' => 40],
        ];
    }

    /**
     * The storefront page, content key "shop", at /shop.php: in Pagina's on
     * every installation (App\Service\ModuleSystemPages). Whether /shop.php
     * shows it is Shop-instellingen → Productoverzicht (App\Service\ShopOverview).
     *
     * Ordinary pages may sit under it at /shop/<slug> (Pages & Destinations
     * 3.0). The storefront itself stays at /shop.php: `shop` is only the word
     * its subtree's paths start with, the Shop's own reserved word. Nothing of
     * the Shop lives under /shop/ — a product is /product.php?id=, a
     * collection /collecties/<slug> — so there is no child_conflicts: a page
     * there cannot clash with anything the Shop serves.
     */
    public function systemPages(): array
    {
        return ['shop' => ['route_path' => '/shop.php', 'child_prefix' => 'shop']];
    }

    /**
     * The storefront's address, whatever the overview setting says: with the
     * Shop off, the page at /shop.php is not served (ModuleGuard answers
     * 404), so nothing may list or link it. routes() alone could not say so,
     * because it drops the 'shop' route while there is no overview.
     */
    public function publicPaths(): array
    {
        return ['/shop.php'];
    }

    /**
     * Root-level PHP files and one URL namespace this module owns. Reserved
     * whether or not the Shop is enabled — see
     * ModuleDefinition::reservedSlugs().
     */
    public function reservedSlugs(): array
    {
        return [
            'shop',
            'product',
            'collectie',
            // /collecties/<slug> is the collection namespace (.htaccess ->
            // collectie.php). No file of that name exists, so only this list
            // keeps a CMS page out of the root of it.
            'collecties',
            'cart',
            'checkout',
            'bestelling-status',
            // The English word for the collection namespace
            // (App\Service\Routing\RouteSegments). It is a root-level URL word
            // on an English-speaking site exactly as "collecties" is on a
            // Dutch one, so it is reserved for the same reason — and reserved
            // whatever the site's languages are, because adding English later
            // must not have to take a page away from anybody.
            'collections',
        ];
    }

    /**
     * The Shop's public URL shapes. The collection namespace was a
     * `RewriteRule`; the rest are real root-level templates, listed so a
     * language-prefixed URL (/en/shop.php) reaches them — their unprefixed
     * form keeps being served straight off disk by Apache.
     *
     * Products deliberately have no slug URL: a product is one page at
     * /product.php?id=… however many collections it appears in
     * (App\Service\ProductSeo), and giving it one is a URL decision that has
     * nothing to do with language.
     */
    public function publicRoutes(): array
    {
        return [
            ['key' => 'shop.index', 'pattern' => 'shop.php', 'template' => 'shop.php'],
            ['key' => 'shop.product', 'pattern' => 'product.php', 'template' => 'product.php'],
            ['key' => 'shop.cart', 'pattern' => 'cart.php', 'template' => 'cart.php'],
            ['key' => 'shop.checkout', 'pattern' => 'checkout.php', 'template' => 'checkout.php'],
            ['key' => 'shop.order-status', 'pattern' => 'bestelling-status.php', 'template' => 'bestelling-status.php'],
            [
                'key' => 'shop.collection',
                'pattern' => '{shop.collections}/{slug}',
                'template' => 'collectie.php',
                'query' => ['slug' => 'slug'],
            ],
        ];
    }

    /** "collecties" is a Dutch word a visitor reads as language. */
    public function routeSegments(): array
    {
        return [
            'shop.collections' => ['default' => 'collecties', 'en' => 'collections'],
        ];
    }

    /** Active products in the site search (SEARCH.md). */
    public function searchProviders(): array
    {
        return ['product' => new \App\Service\ProductSearchProvider()];
    }

    public function sitemapCollectors(): array
    {
        return [
            // The storefront itself, but only while no CMS page carries it
            // (see shop.php). A site with a `shop` page already has /shop.php
            // in the sitemap through Core's pages collector, where
            // App\Service\PageSeo::isIndexable() decides — so the owner's
            // noindex on that page is honoured, and the URL is never claimed
            // twice. No lastmod: there is no row whose updated_at could vouch
            // for a date, and App\Service\Sitemap never invents one.
            'storefront' => static function (): array {
                // Only the automatic listing of an older installation is the
                // Shop's own URL to list. A chosen overview is an ordinary
                // page, which Core's pages collector lists at its own address
                // (and /shop.php then only redirects); no overview, no entry.
                if (ShopOverview::mode() !== ShopOverview::BUILTIN || ShopOverview::storefrontPage() !== null) {
                    return [];
                }

                // The storefront exists in every active language: it is a
                // listing at a fixed path, so there is no address that could
                // be missing (docs/multilingual/ROUTING.md).
                $paths = [];
                foreach (\App\Service\Language\SiteLanguages::activeCodes() as $code) {
                    $paths[$code] = \App\Service\Routing\LocalizedUrl::path('/shop.php', $code);
                }

                return Sitemap::entriesForVersions($paths, null);
            },
            'collections' => static function (): array {
                $entries = [];
                $sitemapCollections = (new CollectionRepository())->findActiveForSitemap();

                // Every collection's addresses in ONE query rather than one
                // per collection (docs/multilingual/ROUTING.md).
                \App\Service\ShopLocalization::collections()->preload(
                    array_map(static fn (array $collection): int => (int) ($collection['id'] ?? 0), $sitemapCollections)
                );

                foreach ($sitemapCollections as $collection) {
                    // Only the languages this collection really has an
                    // address in: a sitemap entry for a URL that 404s is
                    // worse than no entry.
                    foreach (
                        Sitemap::entriesForVersions(
                            CollectionContent::alternates($collection),
                            $collection['updated_at'] ?? null
                        ) as $entry
                    ) {
                        $entries[] = $entry;
                    }
                }

                return $entries;
            },
            'products' => static function (): array {
                $entries = [];
                foreach ((new ProductRepository())->findActiveForSitemap() as $product) {
                    // A product is reachable in EVERY active language: it has
                    // no slug that could be missing, only words that fall
                    // back inside a route that exists.
                    foreach (
                        Sitemap::entriesForVersions(
                            ProductSeo::alternates((int) $product['id']),
                            $product['updated_at'] ?? null
                        ) as $entry
                    ) {
                        $entries[] = $entry;
                    }
                }

                return $entries;
            },
        ];
    }

    public function blockDefinitions(): array
    {
        return [
            'shop_collections' => ShopCollectionsBlock::class,
            'product_grid' => ProductGridBlock::class,
            'featured_product' => FeaturedProductBlock::class,
        ];
    }

    /**
     * Uitgelicht product's "Bekijk product" button may use a button style
     * (Button Styles 2.0, App\Repository\ButtonStyleRepository).
     */
    public function buttonStyleSlots(): array
    {
        return ['featured_products' => ['button_style_id']];
    }

    public function itemGallerySources(): array
    {
        return [
            self::GALLERY_SOURCE_COLLECTION => [
                'label' => 'Een collectie (producten)',
                // After portfolio items (10): while the Portfolio runs, a new
                // gallery block still starts as the portfolio grid it always was.
                'order' => 20,
                'needs_collection' => true,
                // Its card in the block picker: the gallery started on a
                // collection, filed under Shop (App\Service\Blocks\ItemGalleryBlock).
                'picker' => [
                    'category' => BlockCategories::SHOP,
                    'label' => 'Collectiegalerij',
                    'description' => 'De producten van één collectie als raster met beeld, met optioneel een vergroting bij het aanklikken. Welke collectie kies je in het blok.',
                    'use_cases' => ['beeld uit een collectie tonen', 'een collectie tussen je eigen tekst en beeld'],
                ],
                'items' => static fn (array $settings): array => CollectionGalleryItems::forCollection(
                    $settings['collection_id'] ?? null
                ),
            ],
        ];
    }

    /**
     * A product and a collection as the destination of a link
     * (App\Service\Routing\LinkTargets, the Destination Picker): stored by id
     * and linked in the language being read — a product at /product.php?id=…,
     * a collection at its own /collecties/<slug> — only while it is active.
     * Both are chosen from a searchable list with their picture.
     */
    public function linkTargets(): array
    {
        return [
            'product' => [
                'label' => ['nl' => 'Product', 'en' => 'Product'],
                'order' => 30,
                'picker' => \App\Service\Routing\LinkTargets::PICKER_SEARCH,
                'choices' => static function (): array {
                    $products = (new ProductRepository())->findAllForAdmin();
                    \App\Service\ShopLocalization::preloadProducts(array_map(static fn (array $product): int => (int) $product['id'], $products));

                    $choices = [];
                    foreach ($products as $product) {
                        $choice = ['id' => (int) $product['id'], 'label' => \App\Service\ShopLocalization::productName((int) $product['id'])];
                        if (!(bool) $product['active']) {
                            $choice['note'] = 'inactive';
                        }
                        if ((string) ($product['image_path'] ?? '') !== '') {
                            $choice['thumbnail'] = '/' . ltrim((string) $product['image_path'], '/');
                        }
                        $choices[] = $choice;
                    }

                    usort($choices, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

                    return $choices;
                },
                'href' => static fn (int $id): ?string => (new ProductRepository())->findActiveById($id) === null
                    ? null
                    : ProductSeo::publicPath($id),
                'title' => static fn (int $id, string $language): ?string => (new ProductRepository())->findByIdForAdmin($id) === null
                    ? null
                    : \App\Service\ShopLocalization::product($id, \App\Service\ShopLocalization::NAME, $language),
            ],
            'collection' => [
                'label' => ['nl' => 'Collectie', 'en' => 'Collection'],
                'order' => 35,
                'picker' => \App\Service\Routing\LinkTargets::PICKER_SEARCH,
                'choices' => static function (): array {
                    $collections = (new \App\Repository\CollectionRepository())->findAll();
                    \App\Service\ShopLocalization::preloadCollections(array_map(static fn (array $collection): int => (int) $collection['id'], $collections));

                    $choices = [];
                    foreach ($collections as $collection) {
                        $choice = ['id' => (int) $collection['id'], 'label' => \App\Service\ShopLocalization::collectionName((int) $collection['id'])];
                        if (!(bool) $collection['is_active']) {
                            $choice['note'] = 'inactive';
                        }
                        if ((string) ($collection['image_path'] ?? '') !== '') {
                            $choice['thumbnail'] = '/' . ltrim((string) $collection['image_path'], '/');
                        }
                        $choices[] = $choice;
                    }

                    usort($choices, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

                    return $choices;
                },
                // collectie.php answers 404 for an inactive collection, so no
                // link points at one.
                'href' => static function (int $id): ?string {
                    $collection = (new \App\Repository\CollectionRepository())->findById($id);

                    return $collection === null || !(bool) $collection['is_active'] ? null : \App\Service\CollectionContent::urlFor($collection);
                },
                'title' => static fn (int $id, string $language): ?string => (new \App\Repository\CollectionRepository())->findById($id) === null
                    ? null
                    : \App\Service\ShopLocalization::collection($id, \App\Service\ShopLocalization::NAME, $language),
            ],
        ];
    }

    /**
     * The mini-cart in the shared public header is on every page, so its
     * stylesheet has to be in every page's <head> — which is written before
     * the header partial runs. This is the one asset request that genuinely
     * belongs to the site shell, and it is now the Shop asking for it rather
     * than Core naming a Shop file.
     */
    public function shellStyles(): array
    {
        return ['assets/css/shop/cart.css'];
    }

    public function shellScripts(): array
    {
        return ['assets/js/shop/cart.js'];
    }

    public function headerPartials(): array
    {
        return [dirname(__DIR__, 2) . '/partials/header-cart.php'];
    }

    public function dashboardPanels(): array
    {
        return [dirname(__DIR__, 2) . '/admin/_dashboard_shop.php'];
    }

    /**
     * /shop.php while the storefront page (content_key `shop`) is not the
     * chosen product overview (App\Service\ShopOverview): with no overview it
     * answers 404, with another page it redirects there. Core then stops
     * listing and linking that page, as it would for a switched-off module.
     */
    public function pausedPublicPaths(): array
    {
        $mode = ShopOverview::mode();

        if ($mode === ShopOverview::BUILTIN
            || ($mode === ShopOverview::PAGE && ShopOverview::isStorefrontPage(ShopOverview::page()))) {
            return [];
        }

        return ['/shop.php'];
    }

    /**
     * How the Media Library learns that a product or a collection shows one
     * of its images, without Core ever naming either (MEDIA.md).
     */
    public function mediaUsageProviders(): array
    {
        return [new ShopMediaUsage()];
    }

    /**
     * A product carries content blocks under its product detail (Product &
     * Portfolio Content Pages 1.0), through Core's one block engine
     * (App\Service\ContentOwners\ContentPages).
     */
    public function contentOwners(): array
    {
        return [new \App\Service\ProductContentOwner()];
    }

    /**
     * A product's picture where a block shows it as a linked picture: its
     * primary picture, as the storefront's cards take it
     * (ProductImageRepository::findPrimary()), else the product's own path.
     */
    public function linkedImages(): array
    {
        return [
            'product' => static function (int $id): ?array {
                $primary = (new \App\Repository\ProductImageRepository())->findPrimary($id);
                if ($primary !== null) {
                    return \App\Service\Media\BlockImage::fromOwner($primary, null);
                }

                $product = (new ProductRepository())->findByIdForAdmin($id);

                return $product === null ? null : \App\Service\Media\BlockImage::fromOwner($product, null);
            },
        ];
    }

    public function dashboardCards(): array
    {
        return [
            [
                'icon' => 'products',
                'title' => AdminTranslator::trans('dashboard.card_products_title'),
                'desc' => AdminTranslator::trans('dashboard.card_products_desc'),
                'href' => '/admin/products.php',
                'cta' => AdminTranslator::trans('dashboard.card_products_cta'),
                'permission' => self::PRODUCTS_VIEW,
                'order' => 200,
            ],
            [
                'icon' => 'orders',
                'title' => AdminTranslator::trans('dashboard.card_orders_title'),
                'desc' => AdminTranslator::trans('dashboard.card_orders_desc'),
                'href' => '/admin/orders.php',
                'cta' => AdminTranslator::trans('dashboard.card_orders_cta'),
                'permission' => self::ORDERS_VIEW,
                'order' => 300,
            ],
        ];
    }
}
