<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Repository\SiteSettingRepository;
use App\Service\RelatedProductsContent;
use App\Service\ShopLocalization;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * Regression coverage for one small, specific bug: a product with NO related
 * products showed a message where the block would have been.
 *
 * "Gerelateerde producten" is OPTIONAL. Zero related products is a valid
 * state, not an error, and the page has to come back completely normal — no
 * heading, no empty wrapper, no error text, no warning. That has to hold for
 * every way of ending up with nothing:
 *
 *   - the product is in no collection at all;
 *   - its collection holds no other product;
 *   - every other product in it has become invisible;
 *   - a configured related product was deleted outright;
 *   - the request that fetches the cards fails.
 *
 * The last two are the ones the server cannot decide on its own, so they are
 * asserted against the browser code that has to remove the block.
 *
 * Nothing here duplicates tests/Service/RelatedProductsContentTest.php, which
 * proves WHICH products a block would contain; this file is only about what
 * the customer sees when the answer is "none".
 */
final class RelatedProductsEmptyStateTest extends TestCase
{
    private const SLUG_PREFIX = 'zz-test-related-empty-';

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
        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->collectionIds as $id) {
            $db->prepare('DELETE FROM collections WHERE id = :id')->execute(['id' => $id]);
        }

        $this->productIds = [];
        $this->collectionIds = [];

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

        if ($body === false) {
            return null;
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => $body];
    }

    private function createProduct(bool $active = true): int
    {
        $id = $this->products->create([
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)),
            'price' => 9.95,
            'image_path' => null,
            'active' => $active,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 40,
            'requires_parcel' => false,
        ]);

        ShopLocalization::saveProduct($id, 'nl', [ShopLocalization::NAME => 'Testproduct gerelateerd']);
        ShopLocalization::clearCache();

        $this->productIds[] = $id;

        return $id;
    }

    private function createCollection(): int
    {
        $id = $this->collections->create([
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)),
            'image_path' => null,
            'is_active' => true,
        ]);

        ShopLocalization::saveCollection($id, 'nl', [ShopLocalization::NAME => 'Testcollectie gerelateerd']);
        ShopLocalization::clearCache();

        $this->collectionIds[] = $id;

        return $id;
    }

    /**
     * Everything the block would have rendered. A page in the "no related
     * products" state must contain NONE of it.
     */
    private function assertNothingRelatedIsRendered(string $body, string $because): void
    {
        $this->assertStringNotContainsString('data-related-products', $body, $because);
        $this->assertStringNotContainsString('Gerelateerde producten', $body, $because);
        $this->assertStringNotContainsString('data-product-ids', $body, $because);
        $this->assertStringNotContainsString('data-products-grid', $body, $because);

        // No message of any kind where the block would have been.
        $this->assertStringNotContainsString('Producten kunnen op dit moment niet worden geladen', $body, $because);
        $this->assertStringNotContainsString('Er zijn op dit moment geen producten beschikbaar', $body, $because);
        $this->assertStringNotContainsString('data-products-error', $body, $because);
        $this->assertStringNotContainsString('Producten laden', $body, $because);
    }

    /* ------------------------------------------------------------------ */
    /* Zero related products renders nothing at all                        */
    /* ------------------------------------------------------------------ */

    public function testAProductInNoCollectionRendersNoRelatedProductsBlock(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $response = $this->request('/product.php?id=' . $productId);

        $this->assertSame(200, $response['status'], 'an empty relation is a valid state, not an error');
        $this->assertNothingRelatedIsRendered($response['body'], 'product in no collection');
    }

    public function testACollectionHoldingOnlyThisProductRendersNoBlock(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $collectionId = $this->createCollection();
        $this->collections->setCollectionProducts($collectionId, [$productId]);
        RelatedProductsContent::clearCache();

        $response = $this->request('/product.php?id=' . $productId);

        $this->assertSame(200, $response['status']);
        $this->assertNothingRelatedIsRendered($response['body'], 'collection with only this product');
    }

    /**
     * The case the bug report singled out: everything IS configured, but the
     * configured products have all become invisible.
     */
    public function testACollectionWhoseOtherProductsAreAllInactiveRendersNoBlock(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $hidden = $this->createProduct(false);
        $alsoHidden = $this->createProduct(false);

        $collectionId = $this->createCollection();
        $this->collections->setCollectionProducts($collectionId, [$productId, $hidden, $alsoHidden]);
        RelatedProductsContent::clearCache();

        $response = $this->request('/product.php?id=' . $productId);

        $this->assertSame(200, $response['status']);
        $this->assertNothingRelatedIsRendered($response['body'], 'all related products hidden');
    }

    public function testADeletedRelatedProductIsSkippedRatherThanReported(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $doomed = $this->createProduct();

        $collectionId = $this->createCollection();
        $this->collections->setCollectionProducts($collectionId, [$productId, $doomed]);

        Database::connection()->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $doomed]);
        RelatedProductsContent::clearCache();

        $response = $this->request('/product.php?id=' . $productId);

        $this->assertSame(200, $response['status']);
        $this->assertNothingRelatedIsRendered($response['body'], 'the only related product was deleted');
    }

    /* ------------------------------------------------------------------ */
    /* ...and it still renders normally when there IS something to show    */
    /* ------------------------------------------------------------------ */

    public function testTheBlockStillRendersNormallyForAProductThatHasRelatedProducts(): void
    {
        $this->skipUnlessServerReachable();

        $productId = $this->createProduct();
        $sibling = $this->createProduct();

        $collectionId = $this->createCollection();
        $this->collections->setCollectionProducts($collectionId, [$productId, $sibling]);
        RelatedProductsContent::clearCache();

        $body = $this->request('/product.php?id=' . $productId)['body'];

        $this->assertStringContainsString('data-related-products', $body);
        $this->assertStringContainsString('data-product-ids="' . $sibling . '"', $body);
        $this->assertStringContainsString('Gerelateerde producten', $body);
    }

    /* ------------------------------------------------------------------ */
    /* No error text can reach the storefront                              */
    /* ------------------------------------------------------------------ */

    /**
     * The partial itself no longer contains an error paragraph at all, so
     * there is nothing that could be revealed by a stray `hidden = false`.
     */
    public function testTheRelatedProductsPartialContainsNoErrorMessage(): void
    {
        $partial = (string) file_get_contents(dirname(__DIR__, 2) . '/partials/related-products.php');

        $this->assertStringNotContainsString('data-products-error', $partial);
        $this->assertStringNotContainsString('niet worden geladen', $partial);
    }

    /**
     * When the ids resolve to nothing, or the request fails outright, the
     * browser REMOVES the whole section instead of putting a message in it —
     * while the shop and collection grids keep their own legitimate empty
     * state, because there "no products" is what the visitor asked to see.
     */
    public function testTheBrowserRemovesTheWholeBlockInsteadOfShowingAMessage(): void
    {
        $main = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/shop/shop.js');

        $this->assertStringContainsString('grid.closest("[data-related-products]")', $main);
        $this->assertStringContainsString('function removeOptionalBlock()', $main);
        $this->assertStringContainsString('relatedSection.parentNode.removeChild(relatedSection)', $main);

        // Both exits — an empty result and a failed request — take that path.
        $this->assertSame(
            2,
            substr_count($main, 'removeOptionalBlock();'),
            'both the empty-result and the failed-request paths must remove the block'
        );

        // The shop/collection empty state is still there for the grids that
        // legitimately need it.
        // Its sentence comes from the Shop's catalogue, in the page's language
        // (App\Service\ShopScriptText).
        $this->assertStringContainsString('S.escapeHtml(S.text("no_products"))', $main);
    }

    /**
     * A database problem is logged, never printed. The customer sees a page
     * without related products; the owner sees the failure in the log.
     */
    public function testADatabaseFailureIsLoggedAndNeverShownToTheCustomer(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/RelatedProductsContent.php');

        $this->assertStringContainsString('catch (\Throwable $e)', $content);
        $this->assertStringContainsString('error_log(', $content);
        $this->assertStringContainsString('return self::$cache[$cacheKey] = null;', $content);
        $this->assertStringNotContainsString('echo', $content);
    }
}
