<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\CollectionContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * End-to-end coverage of the /collecties/{slug} route: an active collection
 * renders with the right SEO tags, an inactive one and an unknown slug both
 * 404 without leaking anything, and the collection API honours the same
 * visibility rules and the collection's product order.
 *
 * Like tests/Service/PageRoutingTest.php, these are HTTP-level tests because
 * the routing itself lives in .htaccess + Apache and cannot be asserted any
 * other way. They talk to the web server in the same container the suite runs
 * in and skip themselves when that isn't reachable (e.g. phpunit run outside
 * the Docker stack), so they never become a flaky failure for a developer
 * running the suite differently.
 */
final class CollectionRoutingTest extends TestCase
{
    /**
     * Made of the same characters a real slug is (`[a-z0-9-]`): .htaccess
     * only rewrites that charset, so a key with underscores would 404 in
     * Apache before collectie.php ever saw it and these assertions would be
     * testing nothing. The `zz-` prefix keeps it obviously fake.
     */
    private const SLUG_PREFIX = 'zz-test-collection-';

    private CollectionRepository $collections;
    private ProductRepository $products;

    /** @var list<int> */
    private array $collectionIds = [];
    /** @var list<int> */
    private array $productIds = [];

    protected function setUp(): void
    {
        $this->collections = new CollectionRepository();
        $this->products = new ProductRepository();
        $this->skipUnlessServerReachable();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        foreach ($this->collectionIds as $id) {
            $db->prepare('DELETE FROM collections WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }

        $this->collectionIds = [];
        $this->productIds = [];
        CollectionContent::clearCache();
    }

    private function skipUnlessServerReachable(): void
    {
        if ($this->request('/') === null) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }
    }

    /**
     * @return array{status: int, body: string}|null null when the request could not be made at all
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

    private function createCollection(string $name, bool $isActive, ?string $description = null): string
    {
        $slug = self::SLUG_PREFIX . bin2hex(random_bytes(4));

        $this->collectionIds[] = $this->collections->create([
            'name' => $name,
            'name_en' => null,
            'slug' => $slug,
            'description' => $description,
            'description_en' => null,
            'image_path' => null,
            'is_active' => $isActive,
        ]);

        return $slug;
    }

    private function lastCollectionId(): int
    {
        return $this->collectionIds[count($this->collectionIds) - 1];
    }

    private function createProduct(string $name, bool $active = true): int
    {
        $id = $this->products->create([
            'name' => $name,
            'name_en' => null,
            'slug' => 'zz-test-collection-product-' . bin2hex(random_bytes(6)),
            'description' => null,
            'description_en' => null,
            'price' => 12.00,
            'image_path' => null,
            'active' => $active,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 20,
            'requires_parcel' => false,
        ]);

        $this->productIds[] = $id;

        return $id;
    }

    public function testAnActiveCollectionPageRenders(): void
    {
        $slug = $this->createCollection('ZZ Routing Collectie', true, '<p>Een korte omschrijving.</p>');
        $this->collections->setCollectionProducts($this->lastCollectionId(), [$this->createProduct('ZZ routingproduct')]);

        $response = $this->request('/collecties/' . $slug);

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('ZZ Routing Collectie', $response['body']);
        $this->assertStringContainsString('Een korte omschrijving.', $response['body']);
        $this->assertStringContainsString('data-products-grid', $response['body']);
        $this->assertStringContainsString('data-collection-slug="' . $slug . '"', $response['body']);
    }

    public function testTheCollectionPageCanonicalIsTheCollectionPageItself(): void
    {
        $slug = $this->createCollection('ZZ Canonical Collectie', true, '<p>Beschrijving voor SEO.</p>');
        $this->collections->setCollectionProducts($this->lastCollectionId(), [$this->createProduct('ZZ canonicalproduct')]);

        $response = $this->request('/collecties/' . $slug);

        $this->assertNotNull($response);
        $this->assertStringContainsString('rel="canonical"', $response['body']);
        $this->assertStringContainsString('/collecties/' . $slug . '"', $response['body']);
        $this->assertStringContainsString('<meta name="description"', $response['body']);
        $this->assertStringContainsString('Beschrijving voor SEO.', $response['body']);
        $this->assertStringContainsString('ZZ Canonical Collectie | Shop', $response['body']);
    }

    public function testACollectionWithoutADescriptionEmitsNoEmptyDescriptionTag(): void
    {
        $slug = $this->createCollection('ZZ Zonder Omschrijving', true);

        $response = $this->request('/collecties/' . $slug);

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('<meta name="description" content=""', $response['body']);
    }

    public function testAnInactiveCollectionIs404AndLeaksNothing(): void
    {
        $slug = $this->createCollection('ZZ Geheime Collectie', false, '<p>Geheime tekst.</p>');

        $response = $this->request('/collecties/' . $slug);

        $this->assertNotNull($response);
        $this->assertSame(404, $response['status']);
        $this->assertStringNotContainsString('ZZ Geheime Collectie', $response['body']);
        $this->assertStringNotContainsString('Geheime tekst.', $response['body']);
        $this->assertStringContainsString('Collectie niet gevonden', $response['body']);
    }

    public function testAnUnknownCollectionSlugIs404(): void
    {
        $response = $this->request('/collecties/' . self::SLUG_PREFIX . 'bestaat-echt-niet');

        $this->assertNotNull($response);
        $this->assertSame(404, $response['status']);
        $this->assertStringContainsString('Collectie niet gevonden', $response['body']);
    }

    public function testTheCollectionApiReturnsTheCollectionsProductsInSortOrder(): void
    {
        $slug = $this->createCollection('ZZ API Collectie', true);
        $first = $this->createProduct('ZZ api een');
        $second = $this->createProduct('ZZ api twee');
        $third = $this->createProduct('ZZ api drie');

        $this->collections->setCollectionProducts($this->lastCollectionId(), [$third, $first, $second]);

        $response = $this->request('/api/products.php?collection=' . urlencode($slug));

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);

        $payload = json_decode($response['body'], true);
        $this->assertIsArray($payload);
        $ids = array_map('intval', array_column($payload['data'], 'id'));

        $this->assertSame([$third, $first, $second], $ids);
    }

    public function testTheCollectionApiOmitsInactiveProducts(): void
    {
        $slug = $this->createCollection('ZZ API Zichtbaarheid', true);
        $visible = $this->createProduct('ZZ api zichtbaar', true);
        $hidden = $this->createProduct('ZZ api verborgen', false);

        $this->collections->setCollectionProducts($this->lastCollectionId(), [$visible, $hidden]);

        $response = $this->request('/api/products.php?collection=' . urlencode($slug));

        $this->assertNotNull($response);
        $ids = array_map('intval', array_column(json_decode($response['body'], true)['data'], 'id'));

        $this->assertSame([$visible], $ids);
    }

    public function testTheCollectionApiReturnsNothingForAnInactiveOrUnknownCollection(): void
    {
        $slug = $this->createCollection('ZZ API Verborgen', false);
        $this->collections->setCollectionProducts($this->lastCollectionId(), [$this->createProduct('ZZ api geheim')]);

        $inactive = $this->request('/api/products.php?collection=' . urlencode($slug));
        $this->assertNotNull($inactive);
        $this->assertSame(200, $inactive['status']);
        $this->assertSame([], json_decode($inactive['body'], true)['data']);

        $unknown = $this->request('/api/products.php?collection=' . self::SLUG_PREFIX . 'bestaat-niet');
        $this->assertNotNull($unknown);
        $this->assertSame([], json_decode($unknown['body'], true)['data']);
    }

    public function testTheUnfilteredProductApiStillReturnsTheWholeCatalogue(): void
    {
        $collectionSlug = $this->createCollection('ZZ Regressie', true);
        $inCollection = $this->createProduct('ZZ regressie in collectie');
        $outsideCollection = $this->createProduct('ZZ regressie los product');

        $this->collections->setCollectionProducts($this->lastCollectionId(), [$inCollection]);

        $response = $this->request('/api/products.php');

        $this->assertNotNull($response);
        $ids = array_map('intval', array_column(json_decode($response['body'], true)['data'], 'id'));

        $this->assertContains($inCollection, $ids);
        $this->assertContains(
            $outsideCollection,
            $ids,
            'a product that belongs to no collection must still appear in the shop'
        );
    }

    public function testTheShopPageListsActiveCollectionsAndHidesInactiveOnes(): void
    {
        $activeSlug = $this->createCollection('ZZ Shop Zichtbaar', true);
        $this->collections->setCollectionProducts($this->lastCollectionId(), [$this->createProduct('ZZ shopproduct')]);

        $hiddenSlug = $this->createCollection('ZZ Shop Verborgen', false);
        $this->collections->setCollectionProducts($this->lastCollectionId(), [$this->createProduct('ZZ shopproduct verborgen')]);

        $response = $this->request('/shop.php');

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('/collecties/' . $activeSlug, $response['body']);
        $this->assertStringContainsString('ZZ Shop Zichtbaar', $response['body']);
        $this->assertStringNotContainsString('/collecties/' . $hiddenSlug, $response['body']);
        $this->assertStringNotContainsString('ZZ Shop Verborgen', $response['body']);

        // The all-products grid must still be there — collections are added
        // alongside the existing shop, not instead of it.
        $this->assertStringContainsString('data-products-grid', $response['body']);
    }

    public function testTheShopCollectionRowFollowsTheCmsSortOrder(): void
    {
        $product = $this->createProduct('ZZ volgordeproduct');

        $firstSlug = $this->createCollection('ZZ Volgorde Een', true);
        $firstId = $this->lastCollectionId();
        $secondSlug = $this->createCollection('ZZ Volgorde Twee', true);
        $secondId = $this->lastCollectionId();

        $this->collections->setCollectionProducts($firstId, [$product]);
        $this->collections->setCollectionProducts($secondId, [$product]);
        $this->collections->reorderCollections([$secondId, $firstId]);

        $response = $this->request('/shop.php');

        $this->assertNotNull($response);
        $firstPos = strpos($response['body'], '/collecties/' . $firstSlug);
        $secondPos = strpos($response['body'], '/collecties/' . $secondSlug);

        $this->assertNotFalse($firstPos);
        $this->assertNotFalse($secondPos);
        $this->assertLessThan($firstPos, $secondPos, 'the shop tiles follow the CMS collection order');
    }

    /**
     * The whole point of keeping products out of the /collecties/ namespace:
     * a product reached from a collection still links to its one canonical
     * product page.
     */
    public function testCollectionPagesDoNotIntroduceNestedProductUrls(): void
    {
        $slug = $this->createCollection('ZZ Canonieke Product-URL', true);
        $this->collections->setCollectionProducts($this->lastCollectionId(), [$this->createProduct('ZZ genest product')]);

        $response = $this->request('/collecties/' . $slug);

        $this->assertNotNull($response);
        $this->assertStringNotContainsString('/collecties/' . $slug . '/', $response['body']);
    }

    /**
     * A three-segment URL under /collecties/ is not a route at all — it must
     * not resolve to the collection page (or to anything else).
     */
    public function testANestedCollectionProductPathIsNotARoute(): void
    {
        $slug = $this->createCollection('ZZ Geen Geneste Route', true);
        $this->collections->setCollectionProducts($this->lastCollectionId(), [$this->createProduct('ZZ geneste route product')]);

        $response = $this->request('/collecties/' . $slug . '/een-product');

        $this->assertNotNull($response);
        $this->assertSame(404, $response['status']);
    }

    /**
     * Existing application routes must be completely unaffected by the new
     * rewrite rule.
     */
    public function testExistingRoutesStillWork(): void
    {
        foreach (['/', '/shop.php', '/product.php', '/contact.php', '/portfolio.php', '/cart.php'] as $path) {
            $response = $this->request($path);

            $this->assertNotNull($response, $path);
            $this->assertSame(200, $response['status'], $path . ' must still resolve');
        }
    }
}
