<?php

declare(strict_types=1);

namespace App\Module;

use App\Repository\CollectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\ProductRepository;
use App\Service\Blocks\ProductGridBlock;
use App\Service\Blocks\ShopCollectionsBlock;
use App\Service\CollectionContent;
use App\Service\CollectionGalleryItems;
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
            'shop' => ['url' => '/shop.php', 'label_nl' => 'Shop', 'label_en' => 'Shop', 'order' => 20],
            'cart' => ['url' => '/cart.php', 'label_nl' => 'Winkelwagen', 'label_en' => 'Cart', 'order' => 30],
            'checkout' => ['url' => '/checkout.php', 'label_nl' => 'Afrekenen', 'label_en' => 'Checkout', 'order' => 40],
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
        ];
    }

    public function sitemapCollectors(): array
    {
        return [
            'collections' => static function (): array {
                $entries = [];
                foreach ((new CollectionRepository())->findActiveForSitemap() as $collection) {
                    $entries[] = Sitemap::entryFor(
                        CollectionContent::canonicalUrlForSlug((string) $collection['slug']),
                        $collection['updated_at'] ?? null
                    );
                }

                return $entries;
            },
            'products' => static function (): array {
                $entries = [];
                foreach ((new ProductRepository())->findActiveForSitemap() as $product) {
                    $entries[] = Sitemap::entryFor(
                        ProductSeo::canonicalUrl((int) $product['id']),
                        $product['updated_at'] ?? null
                    );
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
                'title' => 'Producten',
                'desc' => 'Beheer producten, afbeeldingen, prijzen en varianten.',
                'href' => '/admin/products.php',
                'cta' => 'Producten beheren',
                'permission' => self::PRODUCTS_VIEW,
                'order' => 200,
            ],
            [
                'icon' => 'orders',
                'title' => 'Bestellingen',
                'desc' => 'Bekijk en beheer binnengekomen bestellingen.',
                'href' => '/admin/orders.php',
                'cta' => 'Bestellingen bekijken',
                'permission' => self::ORDERS_VIEW,
                'order' => 300,
            ],
        ];
    }
}
