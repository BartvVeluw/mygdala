<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\CollectionService;
use PHPUnit\Framework\TestCase;

/**
 * The write-side rules for collections: slug normalisation/generation/
 * validation and the never-trust-the-frontend id filtering.
 *
 * Same shape as tests/Service/PageServiceTest.php — the pure string helpers
 * need no database, the uniqueness rules do, so the few tests that need real
 * rows create and clean up their own.
 */
final class CollectionServiceTest extends TestCase
{
    /**
     * Deliberately made of characters a real slug is made of (`[a-z0-9-]`):
     * CollectionService::sanitizeSlug() legitimately strips leading
     * underscores/dashes, so an `__`-prefixed fixture would come back out
     * un-prefixed and the assertions below would be testing nothing. The
     * `zz-` prefix keeps it obviously fake and last in any listing.
     */
    private const SLUG_PREFIX = 'zz-test-service-collection-';

    private CollectionRepository $collections;

    /** @var list<int> */
    private array $collectionIds = [];
    /** @var list<int> */
    private array $productIds = [];

    protected function setUp(): void
    {
        $this->collections = new CollectionRepository();
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

    private function createCollection(string $name, string $slug): int
    {
        $id = $this->collections->create([
            'name' => $name,
            'name_en' => null,
            'slug' => $slug,
            'description' => null,
            'description_en' => null,
            'image_path' => null,
            'is_active' => true,
        ]);

        $this->collectionIds[] = $id;

        return $id;
    }

    private function createProduct(string $name): int
    {
        $id = (new ProductRepository())->create([
            'name' => $name,
            'name_en' => null,
            'slug' => '__test_service_product_' . bin2hex(random_bytes(6)),
            'description' => null,
            'description_en' => null,
            'price' => 5.00,
            'image_path' => null,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 10,
            'requires_parcel' => false,
        ]);

        $this->productIds[] = $id;

        return $id;
    }

    /* ------------------------------------------------------------------
       Slug handling (pure)
       ------------------------------------------------------------------ */

    public function testSanitizeSlugLowercasesAndCollapsesSeparators(): void
    {
        $this->assertSame('sleutelhangers', CollectionService::sanitizeSlug('Sleutelhangers'));
        $this->assertSame('vier-daagse', CollectionService::sanitizeSlug('Vier  Daagse'));
        $this->assertSame('kerst-2026', CollectionService::sanitizeSlug('  Kerst / 2026!  '));
        $this->assertSame('a-b', CollectionService::sanitizeSlug('---a---b---'));
    }

    public function testSanitizeSlugReturnsEmptyForUnsalvageableInput(): void
    {
        $this->assertSame('', CollectionService::sanitizeSlug(''));
        $this->assertSame('', CollectionService::sanitizeSlug('!!! ???'));
    }

    public function testSanitizeSlugCapsTheLength(): void
    {
        $slug = CollectionService::sanitizeSlug(str_repeat('a', 400));

        $this->assertLessThanOrEqual(CollectionService::MAX_SLUG_LENGTH, strlen($slug));
    }

    /* ------------------------------------------------------------------
       Slug generation / validation (needs the collections table)
       ------------------------------------------------------------------ */

    public function testGenerateSlugDerivesTheSlugFromTheName(): void
    {
        $slug = CollectionService::generateSlug($this->collections, self::SLUG_PREFIX . 'Sleutelhangers');

        $this->assertStringStartsWith(self::SLUG_PREFIX, $slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
    }

    public function testGenerateSlugFallsBackToCollectieForANamelessCollection(): void
    {
        $slug = CollectionService::generateSlug($this->collections, '!!!');

        $this->assertStringStartsWith('collectie', $slug);
    }

    public function testGenerateSlugSuffixesUntilItIsUnique(): void
    {
        $base = self::SLUG_PREFIX . 'uniek-' . bin2hex(random_bytes(3));

        $this->createCollection('Eerste', $base);
        $this->createCollection('Tweede', $base . '-2');

        $this->assertSame($base . '-3', CollectionService::generateSlug($this->collections, $base));
    }

    public function testValidateSlugRejectsAnAlreadyUsedSlug(): void
    {
        $slug = self::SLUG_PREFIX . 'bezet-' . bin2hex(random_bytes(3));
        $existing = $this->createCollection('Bezet', $slug);

        $this->assertNotNull(CollectionService::validateSlug($this->collections, $slug, null));
        $this->assertNull(
            CollectionService::validateSlug($this->collections, $slug, $existing),
            'a collection keeping its own slug is not a collision'
        );
    }

    public function testValidateSlugRejectsAnEmptyOrMalformedSlug(): void
    {
        $this->assertNotNull(CollectionService::validateSlug($this->collections, '', null));
        $this->assertNotNull(CollectionService::validateSlug($this->collections, 'Met Spaties', null));
        $this->assertNotNull(CollectionService::validateSlug($this->collections, '-leading', null));
        $this->assertNotNull(CollectionService::validateSlug($this->collections, 'trailing-', null));
        $this->assertNotNull(CollectionService::validateSlug($this->collections, 'dubbele--koppelteken', null));
    }

    public function testValidateSlugAcceptsAWellFormedUnusedSlug(): void
    {
        $this->assertNull(
            CollectionService::validateSlug($this->collections, self::SLUG_PREFIX . 'vrij-' . bin2hex(random_bytes(3)), null)
        );
    }

    /* ------------------------------------------------------------------
       Never trusting ids/ordering from the frontend
       ------------------------------------------------------------------ */

    public function testNormalizeIdListAcceptsArraysAndCommaSeparatedStringsAndDropsJunk(): void
    {
        $this->assertSame([3, 1, 2], CollectionService::normalizeIdList(['3', '1', '2']));
        $this->assertSame([3, 1, 2], CollectionService::normalizeIdList('3,1,2'));
        $this->assertSame([1], CollectionService::normalizeIdList(['1', '1', '0', '-4', 'abc']));
        $this->assertSame([], CollectionService::normalizeIdList(null));
        $this->assertSame([], CollectionService::normalizeIdList(''));
        $this->assertSame([], CollectionService::normalizeIdList(['nested' => ['1']]));
    }

    public function testNormalizeIdListPreservesTheSubmittedOrder(): void
    {
        // The submitted ORDER is the only ordering input the endpoints use —
        // sort_order values themselves are always derived from position.
        $this->assertSame([9, 4, 7], CollectionService::normalizeIdList('9,4,7'));
    }

    public function testValidateProductIdsDropsIdsThatDoNotExist(): void
    {
        $real = $this->createProduct('Bestaand product');

        $validated = CollectionService::validateProductIds([$real, 999999999], new ProductRepository());

        $this->assertSame([$real], $validated);
    }

    public function testValidateProductIdsKeepsTheSubmittedOrder(): void
    {
        $first = $this->createProduct('Volgorde een');
        $second = $this->createProduct('Volgorde twee');

        $this->assertSame(
            [$second, $first],
            CollectionService::validateProductIds([$second, $first], new ProductRepository())
        );
    }

    public function testValidateCollectionIdsDropsIdsThatDoNotExist(): void
    {
        $real = $this->createCollection('Bestaand', self::SLUG_PREFIX . 'bestaand-' . bin2hex(random_bytes(3)));

        $validated = CollectionService::validateCollectionIds([$real, 999999999], $this->collections);

        $this->assertSame([$real], $validated);
    }

    public function testValidateCollectionIdsIsEmptyForNoSelection(): void
    {
        $this->assertSame([], CollectionService::validateCollectionIds(null, $this->collections));
        $this->assertSame([], CollectionService::validateCollectionIds([], $this->collections));
    }

    /* ------------------------------------------------------------------
       Deletion
       ------------------------------------------------------------------ */

    public function testDeleteIsAHarmlessNoOpForAnUnknownOrInvalidId(): void
    {
        $this->assertFalse(CollectionService::delete(0, $this->collections));
        $this->assertFalse(CollectionService::delete(-1, $this->collections));
        $this->assertFalse(CollectionService::delete(999999999, $this->collections));
    }

    public function testDeleteRemovesTheCollectionAndReportsSuccess(): void
    {
        $id = $this->createCollection('Weg', self::SLUG_PREFIX . 'weg-' . bin2hex(random_bytes(3)));

        $this->assertTrue(CollectionService::delete($id, $this->collections));
        $this->assertNull($this->collections->findById($id));
    }
}
