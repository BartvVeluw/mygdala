<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\CollectionService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the real dev database, same convention as
 * tests/Repository/ProductDeletionIntegrationTest.php — the whole point of
 * the collections feature is schema behaviour (the pivot's composite primary
 * key, both ON DELETE CASCADE directions, per-collection sort_order), which
 * no mock can meaningfully exercise.
 *
 * Everything created here uses obviously-fake '__test_collection_*__' slugs
 * so it can never collide with real content, and tearDown() removes whatever
 * a test did not delete itself.
 */
final class CollectionRepositoryIntegrationTest extends TestCase
{
    private const SLUG_PREFIX = '__test-collection-';
    private const PRODUCT_SLUG_PREFIX = '__test_collection_product_';

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
    }

    private function createCollection(string $name, bool $isActive = true, ?string $description = null): int
    {
        $slug = self::SLUG_PREFIX . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '') . '-' . bin2hex(random_bytes(4));

        $id = $this->collections->create([
            'name' => $name,
            'name_en' => null,
            'slug' => $slug,
            'description' => $description,
            'description_en' => null,
            'image_path' => null,
            'is_active' => $isActive,
        ]);

        $this->collectionIds[] = $id;

        return $id;
    }

    private function createProduct(string $name, bool $active = true): int
    {
        $id = $this->products->create([
            'name' => $name,
            'name_en' => null,
            'slug' => self::PRODUCT_SLUG_PREFIX . bin2hex(random_bytes(6)),
            'description' => null,
            'description_en' => null,
            'price' => 9.95,
            'image_path' => null,
            'active' => $active,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 25,
            'requires_parcel' => false,
        ]);

        $this->productIds[] = $id;

        return $id;
    }

    public function testCreatingACollectionStoresItsFieldsAndAppendsItToTheOrder(): void
    {
        $first = $this->createCollection('Sleutelhangers test');
        $second = $this->createCollection('Onderzetters test');

        $stored = $this->collections->findById($first);

        $this->assertNotNull($stored);
        $this->assertSame('Sleutelhangers test', $stored['name']);
        $this->assertSame(1, (int) $stored['is_active']);

        $secondStored = $this->collections->findById($second);
        $this->assertNotNull($secondStored);
        $this->assertGreaterThan(
            (int) $stored['sort_order'],
            (int) $secondStored['sort_order'],
            'a new collection is appended after the existing ones'
        );
    }

    public function testSlugsAreUniqueAndGeneratedSlugsAvoidCollisions(): void
    {
        $existing = $this->createCollection('Kerst test');
        $existingSlug = (string) $this->collections->findById($existing)['slug'];

        $this->assertTrue($this->collections->slugExists($existingSlug));
        $this->assertFalse(
            $this->collections->slugExists($existingSlug, $existing),
            'a collection never collides with its own slug'
        );

        // Two collections asking for the same name-derived slug must not both
        // get it — the second is suffixed.
        $slugA = CollectionService::generateSlug($this->collections, 'ZZ Vier Daagse Test');
        $idA = $this->collections->create([
            'name' => 'ZZ Vier Daagse Test', 'name_en' => null, 'slug' => $slugA,
            'description' => null, 'description_en' => null, 'image_path' => null, 'is_active' => true,
        ]);
        $this->collectionIds[] = $idA;

        $slugB = CollectionService::generateSlug($this->collections, 'ZZ Vier Daagse Test');

        $this->assertNotSame($slugA, $slugB);
        $this->assertSame($slugA . '-2', $slugB);
    }

    public function testTheDatabaseRefusesADuplicateProductCollectionPair(): void
    {
        $collection = $this->createCollection('Duplicaat test');
        $product = $this->createProduct('Duplicaat testproduct');

        $db = Database::connection();
        $db->prepare('INSERT INTO collection_products (collection_id, product_id, sort_order) VALUES (:c, :p, 0)')
            ->execute(['c' => $collection, 'p' => $product]);

        $this->expectException(\PDOException::class);
        $db->prepare('INSERT INTO collection_products (collection_id, product_id, sort_order) VALUES (:c, :p, 1)')
            ->execute(['c' => $collection, 'p' => $product]);
    }

    public function testAProductCanBelongToMultipleCollections(): void
    {
        $collectionA = $this->createCollection('Multi A');
        $collectionB = $this->createCollection('Multi B');
        $product = $this->createProduct('Product in twee collecties');

        $this->collections->setCollectionProducts($collectionA, [$product]);
        $this->collections->setCollectionProducts($collectionB, [$product]);

        $this->assertSame([$collectionA, $collectionB], $this->collections->collectionIdsForProduct($product));
        $this->assertSame([$product], $this->collections->productIdsForCollection($collectionA));
        $this->assertSame([$product], $this->collections->productIdsForCollection($collectionB));
    }

    public function testACollectionCanHoldMultipleProductsInItsOwnOrder(): void
    {
        $collection = $this->createCollection('Meerdere producten');
        $first = $this->createProduct('Product een');
        $second = $this->createProduct('Product twee');
        $third = $this->createProduct('Product drie');

        // Deliberately not ascending id order — the stored order must be the
        // one that was asked for, not whatever the database finds first.
        $this->collections->setCollectionProducts($collection, [$third, $first, $second]);

        $this->assertSame([$third, $first, $second], $this->collections->productIdsForCollection($collection));
    }

    public function testCollectionProductsRespectSortOrderInThePublicProductQuery(): void
    {
        $collection = $this->createCollection('Sorteervolgorde');
        $first = $this->createProduct('Sorteer een');
        $second = $this->createProduct('Sorteer twee');
        $third = $this->createProduct('Sorteer drie');

        $this->collections->setCollectionProducts($collection, [$third, $first, $second]);

        $rows = $this->products->findAllActive($collection);
        $ids = array_map('intval', array_column($rows, 'id'));

        $this->assertSame([$third, $first, $second], $ids);
    }

    public function testReorderingProductsInsideACollectionRewritesOnlyThatCollection(): void
    {
        $collectionA = $this->createCollection('Herorden A');
        $collectionB = $this->createCollection('Herorden B');
        $first = $this->createProduct('Herorden een');
        $second = $this->createProduct('Herorden twee');

        $this->collections->setCollectionProducts($collectionA, [$first, $second]);
        $this->collections->setCollectionProducts($collectionB, [$first, $second]);

        $this->collections->reorderCollectionProducts($collectionA, [$second, $first]);

        $this->assertSame([$second, $first], $this->collections->productIdsForCollection($collectionA));
        $this->assertSame(
            [$first, $second],
            $this->collections->productIdsForCollection($collectionB),
            'the same product sits in a different position in another collection'
        );
    }

    public function testReorderingIgnoresUnknownIdsAndKeepsMissingMembersAtTheEnd(): void
    {
        $collection = $this->createCollection('Defensief herordenen');
        $first = $this->createProduct('Defensief een');
        $second = $this->createProduct('Defensief twee');
        $third = $this->createProduct('Defensief drie');

        $this->collections->setCollectionProducts($collection, [$first, $second, $third]);

        // A stale/filtered browser list: mentions only one member, plus an id
        // that is not in this collection at all.
        $this->collections->reorderCollectionProducts($collection, [$third, 999999999]);

        $this->assertSame(
            [$third, $first, $second],
            $this->collections->productIdsForCollection($collection),
            'no member may be dropped out of the collection by a partial reorder'
        );
    }

    public function testSyncingFromTheProductSideAddsAndRemovesWithoutDuplicating(): void
    {
        $collectionA = $this->createCollection('Productzijde A');
        $collectionB = $this->createCollection('Productzijde B');
        $collectionC = $this->createCollection('Productzijde C');
        $product = $this->createProduct('Productzijde product');

        $this->collections->setProductCollections($product, [$collectionA, $collectionB]);
        $this->assertSame([$collectionA, $collectionB], $this->collections->collectionIdsForProduct($product));

        // Deselect B, keep A, add C.
        $this->collections->setProductCollections($product, [$collectionA, $collectionC]);
        $this->assertSame([$collectionA, $collectionC], $this->collections->collectionIdsForProduct($product));

        // Saving the identical selection again must be a no-op, not a duplicate.
        $this->collections->setProductCollections($product, [$collectionA, $collectionC]);
        $this->assertSame([$collectionA, $collectionC], $this->collections->collectionIdsForProduct($product));

        $db = Database::connection();
        $stmt = $db->prepare('SELECT COUNT(*) AS c FROM collection_products WHERE product_id = :p');
        $stmt->execute(['p' => $product]);
        $this->assertSame(2, (int) $stmt->fetch()['c']);
    }

    public function testSyncingFromTheProductSideKeepsAnExistingPositionInsideACollection(): void
    {
        $collection = $this->createCollection('Positie behouden');
        $first = $this->createProduct('Positie een');
        $second = $this->createProduct('Positie twee');
        $third = $this->createProduct('Positie drie');

        $this->collections->setCollectionProducts($collection, [$first, $second, $third]);

        // Re-saving the middle product from the product editor must not shove
        // it to the end of the collection.
        $this->collections->setProductCollections($second, [$collection]);

        $this->assertSame([$first, $second, $third], $this->collections->productIdsForCollection($collection));
    }

    public function testDeletingACollectionRemovesItsPivotRowsButLeavesEveryProductIntact(): void
    {
        $collection = $this->createCollection('Verwijder mij');
        $first = $this->createProduct('Blijft bestaan een');
        $second = $this->createProduct('Blijft bestaan twee');

        $this->collections->setCollectionProducts($collection, [$first, $second]);

        $deleted = CollectionService::delete($collection, $this->collections);

        $this->assertTrue($deleted);
        $this->assertNull($this->collections->findById($collection));

        $db = Database::connection();
        $stmt = $db->prepare('SELECT COUNT(*) AS c FROM collection_products WHERE collection_id = :c');
        $stmt->execute(['c' => $collection]);
        $this->assertSame(0, (int) $stmt->fetch()['c'], 'the pivot rows go with the collection');

        // The single most important property of this feature.
        $this->assertNotNull($this->products->findByIdForAdmin($first));
        $this->assertNotNull($this->products->findByIdForAdmin($second));
    }

    public function testDeletingAProductRemovesItsCollectionRelations(): void
    {
        $collection = $this->createCollection('Product verdwijnt');
        $keep = $this->createProduct('Blijft in de collectie');
        $remove = $this->createProduct('Verdwijnt uit de collectie');

        $this->collections->setCollectionProducts($collection, [$keep, $remove]);

        $this->products->delete($remove);

        $this->assertSame(
            [$keep],
            $this->collections->productIdsForCollection($collection),
            'a deleted product may never leave a dangling membership behind'
        );
        $this->assertNotNull($this->collections->findById($collection), 'the collection itself survives');
    }

    public function testInactiveCollectionsAreExcludedFromTheActiveReads(): void
    {
        $active = $this->createCollection('Zichtbaar', true);
        $inactive = $this->createCollection('Verborgen', false);
        $product = $this->createProduct('Zichtbaarheidsproduct');

        $this->collections->setCollectionProducts($active, [$product]);
        $this->collections->setCollectionProducts($inactive, [$product]);

        $activeIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->collections->findAllActive()
        );

        $this->assertContains($active, $activeIds);
        $this->assertNotContains($inactive, $activeIds);

        $shopIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->collections->findActiveWithActiveProductCounts()
        );

        $this->assertContains($active, $shopIds);
        $this->assertNotContains($inactive, $shopIds);
    }

    public function testTheShopReadSkipsCollectionsWithoutAnyActiveProduct(): void
    {
        $empty = $this->createCollection('Leeg maar actief', true);
        $onlyInactive = $this->createCollection('Alleen inactieve producten', true);
        $inactiveProduct = $this->createProduct('Inactief product', false);

        $this->collections->setCollectionProducts($onlyInactive, [$inactiveProduct]);

        $shopIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->collections->findActiveWithActiveProductCounts()
        );

        $this->assertNotContains($empty, $shopIds);
        $this->assertNotContains($onlyInactive, $shopIds);
    }

    public function testProductCountsReflectTheStoredMembership(): void
    {
        $collection = $this->createCollection('Tellen');
        $first = $this->createProduct('Tel een');
        $second = $this->createProduct('Tel twee');

        $this->collections->setCollectionProducts($collection, [$first, $second]);

        $counts = $this->collections->productCountsByCollectionIds([$collection]);
        $this->assertSame(2, $counts[$collection] ?? 0);

        $withCounts = $this->collections->findAllWithProductCounts();
        $row = null;
        foreach ($withCounts as $candidate) {
            if ((int) $candidate['id'] === $collection) {
                $row = $candidate;
            }
        }

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row['product_count']);
    }

    public function testReorderingCollectionsRewritesTheirSortOrder(): void
    {
        $first = $this->createCollection('Volgorde een');
        $second = $this->createCollection('Volgorde twee');
        $third = $this->createCollection('Volgorde drie');

        $this->collections->reorderCollections([$third, $second, $first]);

        $orderedIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->collections->findAll()
        );

        // Other collections may exist in the dev database; only the relative
        // order of these three is asserted.
        $positions = [
            $first => array_search($first, $orderedIds, true),
            $second => array_search($second, $orderedIds, true),
            $third => array_search($third, $orderedIds, true),
        ];

        $this->assertLessThan($positions[$second], $positions[$third]);
        $this->assertLessThan($positions[$first], $positions[$second]);
    }

    public function testFindBySlugReturnsInactiveCollectionsSoCallersCanDecide(): void
    {
        $inactive = $this->createCollection('Slug lookup', false);
        $slug = (string) $this->collections->findById($inactive)['slug'];

        $found = $this->collections->findBySlug($slug);

        $this->assertNotNull($found, 'the repository is not the visibility gate — CollectionContent is');
        $this->assertSame(0, (int) $found['is_active']);
        $this->assertNull($this->collections->findBySlug('__test-collection-does-not-exist'));
    }
}
