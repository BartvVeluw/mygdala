<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Repository\SiteSettingRepository;
use App\Service\RelatedProductsContent;
use App\Service\ShopLocalization;
use App\Service\SectionRegistry;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * "Gerelateerde producten" as a visitor meets it: rendered automatically at
 * the bottom of /product.php, with no page-builder configuration anywhere —
 * and, just as important, gone completely when it should be.
 *
 * The rendering half is HTTP-level (same helper and same skip-when-unreachable
 * guard as tests/Service/CollectionRoutingTest.php), because "the product page
 * renders this by itself" is exactly the thing a unit test of the partial
 * cannot prove. The rest is static source inspection — the technique this
 * project already uses where there is no harness (see
 * ProductDeletionAdminSecurityTest) — to hold the two architectural promises:
 * the obsolete content block is really gone, and the product card still has
 * exactly one implementation.
 */
final class RelatedProductsProductPageTest extends TestCase
{
    private const SLUG_PREFIX = 'zz-test-relatedpage-';

    /** The language-neutral keys; the heading is a site_setting_translations row since phase 5 wave C. */
    private const SETTING_KEYS = [
        'related_products_enabled',
        'related_products_max_items',
    ];

    private CollectionRepository $collections;
    private ProductRepository $products;

    /** @var list<int> */
    private array $collectionIds = [];
    /** @var list<int> */
    private array $productIds = [];
    /** @var array<string, string> */
    private array $originalSettings = [];

    protected function setUp(): void
    {
        $this->collections = new CollectionRepository();
        $this->products = new ProductRepository();

        $stored = (new SiteSettingRepository())->findAll();
        foreach (self::SETTING_KEYS as $key) {
            $this->originalSettings[$key] = $stored[$key] ?? SiteSettings::defaults()[$key];
        }

        SiteSettings::clearCache();
        RelatedProductsContent::clearCache();
    }

    protected function tearDown(): void
    {
        (new SiteSettingRepository())->upsertMany($this->originalSettings);

        $db = Database::connection();
        foreach ($this->collectionIds as $id) {
            $db->prepare('DELETE FROM collections WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }

        $this->collectionIds = [];
        $this->productIds = [];
        $this->originalSettings = [];

        SiteSettings::clearCache();
        RelatedProductsContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function skipUnlessServerReachable(): void
    {
        if ($this->request('/product.php') === null) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }
    }

    /**
     * @return array{status: int, body: string}|null
     */
    private function request(string $path): ?array
    {
        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 5, 'follow_location' => 0],
        ]);

        $body = @file_get_contents(TestEnvironment::baseUrl() . $path, false, $context);
        if ($body === false && !isset($http_response_header)) {
            return null;
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    private static function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function createCollection(string $name, bool $showRelated = true): int
    {
        $id = $this->collections->create([
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(5)),
            'image_path' => null,
            'is_active' => true,
        ]);
        $this->collectionIds[] = $id;

        ShopLocalization::saveCollection($id, 'nl', [ShopLocalization::NAME => $name]);
        ShopLocalization::clearCache();

        if (!$showRelated) {
            $this->collections->updateRelatedProductsSettings($id, false);
        }

        return $id;
    }

    private function createProduct(string $name): int
    {
        $id = $this->products->create([
            'slug' => self::SLUG_PREFIX . 'product-' . bin2hex(random_bytes(6)),
            'price' => 8.75,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 25,
            'requires_parcel' => false,
        ]);
        $this->productIds[] = $id;

        ShopLocalization::saveProduct($id, 'nl', [ShopLocalization::NAME => $name]);
        ShopLocalization::clearCache();

        return $id;
    }

    /* ------------------------------------------------------------------ */
    /* The product page renders it by itself                               */
    /* ------------------------------------------------------------------ */

    public function testTheProductPageRendersRelatedProductsWithoutAnyPageConfiguration(): void
    {
        $this->skipUnlessServerReachable();

        $collection = $this->createCollection('ZZ Paginabron');
        $current = $this->createProduct('ZZ Pagina huidig');
        $a = $this->createProduct('ZZ Pagina A');
        $b = $this->createProduct('ZZ Pagina B');

        $this->collections->setCollectionProducts($collection, [$current, $a, $b]);

        $response = $this->request('/product.php?id=' . $current);

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('data-related-products', $response['body']);
        $this->assertStringContainsString('Gerelateerde producten', $response['body']);
        $this->assertStringContainsString('data-product-ids="' . $a . ',' . $b . '"', $response['body']);
    }

    public function testTheViewedProductIsNeverAmongItsOwnRelatedProducts(): void
    {
        $this->skipUnlessServerReachable();

        $collection = $this->createCollection('ZZ Zelf uitsluiten');
        $current = $this->createProduct('ZZ Zelf');
        $other = $this->createProduct('ZZ Ander');

        $this->collections->setCollectionProducts($collection, [$current, $other]);

        $response = $this->request('/product.php?id=' . $current);

        $this->assertNotNull($response);
        $this->assertSame(1, preg_match('/data-product-ids="([^"]*)"/', $response['body'], $m));
        $ids = array_map('intval', explode(',', $m[1]));

        $this->assertContains($other, $ids);
        $this->assertNotContains($current, $ids);
    }

    public function testTheSectionDisappearsCompletelyWhenTheCollectionIsSwitchedOff(): void
    {
        $this->skipUnlessServerReachable();

        $collection = $this->createCollection('ZZ Uitgezet op pagina');
        $current = $this->createProduct('ZZ Uitgezet huidig');
        $other = $this->createProduct('ZZ Uitgezet ander');
        $this->collections->setCollectionProducts($collection, [$current, $other]);

        $withSection = $this->request('/product.php?id=' . $current);
        $this->assertNotNull($withSection);
        $this->assertStringContainsString('data-related-products', $withSection['body']);

        $this->collections->updateRelatedProductsSettings($collection, false, null, null);

        $withoutSection = $this->request('/product.php?id=' . $current);

        $this->assertNotNull($withoutSection);
        $this->assertSame(200, $withoutSection['status'], 'the product page itself keeps working');
        $this->assertStringNotContainsString('data-related-products', $withoutSection['body']);
        $this->assertStringNotContainsString('Gerelateerde producten', $withoutSection['body']);
        // No empty heading and no empty grid left behind.
        $this->assertStringNotContainsString('data-product-ids', $withoutSection['body']);
    }

    public function testTheGlobalSwitchRemovesTheSectionFromTheProductPage(): void
    {
        $this->skipUnlessServerReachable();

        $collection = $this->createCollection('ZZ Globaal op pagina');
        $current = $this->createProduct('ZZ Globaal huidig');
        $other = $this->createProduct('ZZ Globaal ander');
        $this->collections->setCollectionProducts($collection, [$current, $other]);

        (new SiteSettingRepository())->upsertMany(['related_products_enabled' => '0']);
        SiteSettings::clearCache();

        $response = $this->request('/product.php?id=' . $current);

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('data-related-products', $response['body']);
    }

    public function testAProductWithNoRelatedProductsRendersNoSectionAtAll(): void
    {
        $this->skipUnlessServerReachable();

        $lonely = $this->createProduct('ZZ Eenzaam');

        $response = $this->request('/product.php?id=' . $lonely);

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('data-related-products', $response['body']);
    }

    public function testTheProductPageStillWorksWithoutAUsableIdInTheUrl(): void
    {
        $this->skipUnlessServerReachable();

        // A URL that names no product at all still resolves normally (200);
        // one that names a product which does not exist answers 404 instead
        // of a soft 404, since product SEO made this page indexable — see
        // product.php's docblock and tests/Service/ShopSeoRoutingTest.php.
        // Either way the page renders its own shell and no related products.
        $expectedStatus = [
            '/product.php' => 200,
            '/product.php?id=' => 200,
            '/product.php?id=abc' => 200,
            '/product.php?id=999999999' => 404,
        ];

        foreach ($expectedStatus as $path => $status) {
            $response = $this->request($path);

            $this->assertNotNull($response, $path);
            $this->assertSame($status, $response['status'], $path);
            $this->assertStringNotContainsString('data-related-products', $response['body'], $path);
            // The page's own shell is untouched by this feature.
            $this->assertStringContainsString('data-product-detail', $response['body'], $path);
        }
    }

    public function testTheHeadingComesFromTheCollectionOverrideWhenItHasOne(): void
    {
        $this->skipUnlessServerReachable();

        $collection = $this->createCollection('ZZ Kop op pagina');
        $current = $this->createProduct('ZZ Kop huidig');
        $other = $this->createProduct('ZZ Kop ander');
        $this->collections->setCollectionProducts($collection, [$current, $other]);
        $this->collections->updateRelatedProductsSettings($collection, true, 'Meer onderzetters bekijken', null);

        $response = $this->request('/product.php?id=' . $current);

        $this->assertNotNull($response);
        $this->assertStringContainsString('Meer onderzetters bekijken', $response['body']);
    }

    /* ------------------------------------------------------------------ */
    /* The obsolete content block is really gone                           */
    /* ------------------------------------------------------------------ */

    public function testRelatedProductsIsNotAPageBuilderSectionType(): void
    {
        $this->assertFalse(SectionRegistry::exists('related_products'));
        $this->assertArrayNotHasKey('related_products', SectionRegistry::types());
        $this->assertStringNotContainsString('related_products', self::sourceOf('src/Service/SectionRegistry.php'));
    }

    public function testNoneOfTheObsoleteContentBlockFilesExist(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ([
            'partials/section-related-products.php',
            'src/Repository/RelatedProductsRepository.php',
            'api/admin/update-related-products-section.php',
            'db/migrations/20260908160000_create_related_products_sections_table.php',
            'db/migrations/20260908160100_create_related_products_items_table.php',
        ] as $obsolete) {
            $this->assertFileDoesNotExist($root . '/' . $obsolete);
        }
    }

    public function testNoManuallyMaintainedRelatedProductsTablesRemain(): void
    {
        $tables = Database::connection()
            ->query("SHOW TABLES LIKE 'related\\_products\\_%'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame([], $tables, 'a second, manual related-products system must not linger in the schema');
    }

    public function testTheAdminScreenIsASettingsPageNotASectionEditor(): void
    {
        $screen = self::sourceOf('admin/related-products.php');

        // No ?section=<page>:<key> addressing, no product picker, no
        // per-page anything: this screen is reached from the sidebar.
        $this->assertStringNotContainsString("\$_GET['section']", $screen);
        $this->assertStringNotContainsString('product_ids[]', $screen);
        $this->assertStringNotContainsString('data-collection-product-list', $screen);
        $this->assertStringContainsString('update-related-products-settings.php', $screen);
    }

    /* ------------------------------------------------------------------ */
    /* One product card, unchanged everywhere                              */
    /* ------------------------------------------------------------------ */

    public function testTheRelatedProductsPartialContainsNoProductCardMarkup(): void
    {
        $partial = self::sourceOf('partials/related-products.php');

        foreach (['product-card__price', 'product-card__media', 'product.php?id='] as $needle) {
            $this->assertStringNotContainsString($needle, $partial);
        }

        // It renders the shop's own grid instead.
        $this->assertStringContainsString('class="shop-grid" data-products-grid', $partial);
    }

    public function testThereIsStillExactlyOneProductCardRendererInTheFrontendJavaScript(): void
    {
        $js = self::sourceOf('assets/js/shop/shop.js');

        // The URL carries the page's language prefix since Multilingual 2.0
        // phase 6 (S.localeUrl, see assets/js/shop/cart.js), so the literal
        // moved — there is still exactly one place that builds a card.
        $this->assertSame(
            1,
            substr_count($js, 'class="product-card is-visible" href='),
            'a second product-card construction would let the shop and related products drift apart'
        );
        $this->assertStringContainsString(
            'S.localeUrl("/product.php?id="',
            $js,
            'and it links in the language the page is being read in'
        );
    }

    public function testTheShopAndCollectionPagesAreUnchangedByThisFeature(): void
    {
        // The shop's grid moved out of shop.php into its own block partial
        // when the page builder became one ordered list per page (see
        // App\Service\SectionRegistry's `product_grid`); the markup itself
        // is unchanged, which is what this asserts.
        foreach (['partials/section-product-grid.php', 'collectie.php'] as $page) {
            $source = self::sourceOf($page);

            $this->assertStringContainsString('class="shop-grid" data-products-grid', $source, $page);
            $this->assertStringContainsString('data-products-error', $source, $page);
            $this->assertStringNotContainsString('data-product-ids', $source, $page);
            $this->assertStringNotContainsString('related', $source, $page);
        }

        $this->assertStringContainsString('data-collection-slug', self::sourceOf('collectie.php'));
    }

    public function testTheShopGridStillListsTheWholeCatalogue(): void
    {
        $this->skipUnlessServerReachable();

        // A fresh product with no collection must still reach /shop's grid —
        // this feature narrows nothing outside a product page.
        $product = $this->createProduct('ZZ Shopregressie');

        $response = $this->request('/api/products.php');

        $this->assertNotNull($response);
        $payload = json_decode($response['body'], true);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $payload['data'] ?? []);

        $this->assertContains($product, $ids);
    }

    public function testTheProductDetailPageKeepsAllOfItsOwnBehaviour(): void
    {
        $this->skipUnlessServerReachable();

        $collection = $this->createCollection('ZZ Detailregressie');
        $current = $this->createProduct('ZZ Detail huidig');
        $this->collections->setCollectionProducts($collection, [$current, $this->createProduct('ZZ Detail ander')]);

        $response = $this->request('/product.php?id=' . $current);

        $this->assertNotNull($response);
        foreach ([
            'data-product-detail',
            'data-product-content',
            'data-product-loading',
            'data-product-error',
            'data-product-media',
            'data-product-variants',
            'data-product-add-to-cart',
            'data-product-qty',
        ] as $hook) {
            $this->assertStringContainsString($hook, $response['body'], $hook . ' must still be on the page');
        }
    }
}
