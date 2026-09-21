<?php

declare(strict_types=1);

namespace App\Module;

use App\Service\Language\AdminTranslator;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\AdminPermissions;
use App\Service\AppUrl;
use App\Service\Blocks\ProductGridBlock;
use App\Service\Blocks\ShopCollectionsBlock;
use App\Service\CollectionContent;
use App\Service\CollectionGalleryItems;
use App\Service\PageContent;
use App\Service\ProductSeo;
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

    /** The item-gallery source key this module contributes. */
    public const GALLERY_SOURCE_COLLECTION = 'collection';

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
            ],
            [
                'key' => 'collections',
                'label' => 'Collecties',
                'url' => '/admin/collections.php',
                'icon' => 'collections',
                'permission' => self::COLLECTIONS_MANAGE,
                'order' => 310,
                'scripts' => ['collections.php', 'collection.php'],
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
            ],
            [
                'key' => 'shipping',
                'label' => 'Verzendinstellingen',
                'url' => '/admin/shipping.php',
                'icon' => 'shipping',
                'permission' => self::SHIPPING_MANAGE,
                'order' => 340,
                'scripts' => ['shipping.php'],
            ],
            [
                'key' => 'carrier_rates',
                'label' => 'Carrier-tarieven',
                'url' => '/admin/carrier-rates.php',
                'icon' => 'carrier_rates',
                'permission' => self::SHIPPING_MANAGE,
                'order' => 350,
                'scripts' => ['carrier-rates.php'],
            ],
            [
                // Invoices, order numbers and the order confirmation e-mail:
                // two tabs of Site-instellingen once, and meaningless without
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
            ],
            [
                'key' => 'orders',
                'label' => 'Bestellingen',
                'url' => '/admin/orders.php',
                'icon' => 'orders',
                'permission' => self::ORDERS_VIEW,
                'order' => 500,
                'scripts' => ['orders.php', 'order.php', 'orders-export.php'],
            ],
            [
                'key' => 'withdrawal_requests',
                'label' => 'Retourverzoeken',
                'url' => '/admin/withdrawal-requests.php',
                'icon' => 'withdrawal_requests',
                'permission' => self::ORDERS_VIEW,
                'order' => 510,
                'scripts' => ['withdrawal-requests.php', 'withdrawal-request.php'],
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

    public function permissionImplications(): array
    {
        return [
            self::PRODUCTS_MANAGE => [self::PRODUCTS_VIEW],
            self::ORDERS_MANAGE => [self::ORDERS_VIEW],
        ];
    }

    public function routes(): array
    {
        return [
            'shop' => ['url' => '/shop.php', 'label' => ['nl' => 'Shop', 'en' => 'Shop'], 'order' => 20],
            'cart' => ['url' => '/cart.php', 'label' => ['nl' => 'Winkelwagen', 'en' => 'Cart'], 'order' => 30],
            'checkout' => ['url' => '/checkout.php', 'label' => ['nl' => 'Afrekenen', 'en' => 'Checkout'], 'order' => 40],
        ];
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
                if (PageContent::forContentKey('shop') !== null) {
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
        ];
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
                'items' => static fn (array $settings): array => CollectionGalleryItems::forCollection(
                    $settings['collection_id'] ?? null
                ),
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
