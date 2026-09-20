<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\CollectionContent;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;

/**
 * The public read model: what a visitor can and cannot see.
 *
 * The visibility rules are the security-relevant part here — an unpublished
 * collection must be indistinguishable from one that does not exist — so
 * they are asserted against real rows rather than mocked.
 *
 * A mapped row carries its words as one LocalizedValue each since
 * Multilingual 2.0 phase 5 wave C (they are rows in
 * `collection_translations`, not columns), so the assertions below read
 * `$collection['name']->in('en')` rather than a `name_en` key.
 */
final class CollectionContentTest extends TestCase
{
    private const SLUG_PREFIX = '__test-content-collection-';

    private CollectionRepository $collections;

    /** @var list<int> */
    private array $collectionIds = [];
    /** @var list<int> */
    private array $productIds = [];

    protected function setUp(): void
    {
        $this->collections = new CollectionRepository();
        CollectionContent::clearCache();
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

    private function createCollection(string $name, bool $isActive, ?string $description = null, ?string $nameEn = null): string
    {
        $slug = self::SLUG_PREFIX . bin2hex(random_bytes(4));

        $id = $this->collections->create([
            'slug' => $slug,
            'image_path' => null,
            'is_active' => $isActive,
        ]);
        $this->collectionIds[] = $id;

        ShopLocalization::saveCollection($id, 'nl', [
            ShopLocalization::NAME => $name,
            ShopLocalization::DESCRIPTION => (string) $description,
        ]);
        ShopLocalization::saveCollection($id, 'en', [ShopLocalization::NAME => (string) $nameEn]);
        ShopLocalization::clearCache();

        return $slug;
    }

    private function createProduct(): int
    {
        $id = (new ProductRepository())->create([
            'slug' => '__test_content_product_' . bin2hex(random_bytes(6)),
            'price' => 7.50,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 10,
            'requires_parcel' => false,
        ]);

        ShopLocalization::saveProduct($id, 'nl', [ShopLocalization::NAME => 'Content testproduct']);
        ShopLocalization::clearCache();

        $this->productIds[] = $id;

        return $id;
    }

    public function testPublicPathIsTheCollectiesNamespace(): void
    {
        $this->assertSame('/collecties/sleutelhangers', CollectionContent::publicPath('sleutelhangers'));
    }

    public function testAnActiveCollectionIsPubliclyAvailable(): void
    {
        $slug = $this->createCollection('Publiek', true, '<p>Hallo</p>', 'Public');

        $collection = CollectionContent::forPublicPage($slug);

        $this->assertNotNull($collection);
        $this->assertSame('Publiek', $collection['name']->in('nl'));
        $this->assertSame('Public', $collection['name']->in('en'));
        $this->assertSame('/collecties/' . $slug, $collection['url']);
    }

    public function testAnInactiveCollectionIsNotPubliclyAvailable(): void
    {
        $slug = $this->createCollection('Verborgen', false);

        $this->assertNull(
            CollectionContent::forPublicPage($slug),
            'an unpublished collection must be indistinguishable from a non-existent one'
        );
    }

    /**
     * REGRESSION, found by flipping the default language on a real site. The
     * neutral `collections.slug` answers for the default language, but only
     * for a collection that has NO address of its own there
     * (App\Service\Routing\LocalizedSlug::answersTo()).
     */
    public function testTheNeutralSlugIsNoSecondAddressForACollectionThatHasItsOwn(): void
    {
        $default = ShopLocalization::defaultLanguage();
        $slug = $this->createCollection('Eigen adres', true);

        $collection = CollectionContent::forPublicPage($slug, $default);
        $this->assertNotNull($collection, 'a collection with only its neutral slug is still reachable');

        ShopLocalization::saveCollection((int) $collection['id'], $default, [
            ShopLocalization::SLUG => $slug . '-eigen',
        ]);
        ShopLocalization::clearCache();

        $this->assertNotNull(CollectionContent::forPublicPage($slug . '-eigen', $default));
        $this->assertNull(CollectionContent::forPublicPage($slug, $default), 'one version, one address');
    }

    public function testAnUnknownOrEmptySlugIsNotPubliclyAvailable(): void
    {
        $this->assertNull(CollectionContent::forPublicPage(self::SLUG_PREFIX . 'bestaat-niet'));
        $this->assertNull(CollectionContent::forPublicPage(''));
    }

    public function testABlankEnglishNameFallsBackToTheDutchOne(): void
    {
        $slug = $this->createCollection('Alleen Nederlands', true);

        $collection = CollectionContent::forPublicPage($slug);

        $this->assertNotNull($collection);
        $this->assertSame('Alleen Nederlands', $collection['name']->in('en'));
    }

    public function testDescriptionsAreSanitizedOnRead(): void
    {
        // Written straight into the database, bypassing the endpoint's own
        // sanitizer, to prove the read path is a second, independent boundary.
        $slug = $this->createCollection('Onveilig', true, '<p>Veilig</p><script>alert(1)</script>');

        $collection = CollectionContent::forPublicPage($slug);

        $this->assertNotNull($collection);
        $this->assertStringNotContainsString('<script', $collection['description']->in('nl'));
        $this->assertStringContainsString('Veilig', $collection['description']->in('nl'));
    }

    public function testActiveForShopListsActiveCollectionsWithActiveProductsInSortOrder(): void
    {
        $product = $this->createProduct();

        $firstSlug = $this->createCollection('Shop een', true);
        $secondSlug = $this->createCollection('Shop twee', true);
        $hiddenSlug = $this->createCollection('Shop verborgen', false);

        $firstId = $this->collectionIds[count($this->collectionIds) - 3];
        $secondId = $this->collectionIds[count($this->collectionIds) - 2];
        $hiddenId = $this->collectionIds[count($this->collectionIds) - 1];

        $this->collections->setCollectionProducts($firstId, [$product]);
        $this->collections->setCollectionProducts($secondId, [$product]);
        $this->collections->setCollectionProducts($hiddenId, [$product]);

        // Put "twee" before "een" and check the shop honours sort_order.
        $this->collections->reorderCollections([$secondId, $firstId]);
        CollectionContent::clearCache();

        $slugs = array_column(CollectionContent::activeForShop(), 'slug');

        $this->assertContains($firstSlug, $slugs);
        $this->assertContains($secondSlug, $slugs);
        $this->assertNotContains($hiddenSlug, $slugs, 'an inactive collection never appears on /shop');

        $this->assertLessThan(
            array_search($firstSlug, $slugs, true),
            array_search($secondSlug, $slugs, true),
            'the /shop collection row follows the CMS sort_order'
        );
    }

    public function testActiveForShopSkipsACollectionWithoutProducts(): void
    {
        $slug = $this->createCollection('Leeg', true);

        $this->assertNotContains($slug, array_column(CollectionContent::activeForShop(), 'slug'));
    }

    public function testExcerptStripsMarkupCollapsesWhitespaceAndTruncatesOnAWordBoundary(): void
    {
        $this->assertSame('Hallo wereld', CollectionContent::excerpt('<p>Hallo   <strong>wereld</strong></p>'));
        $this->assertSame('', CollectionContent::excerpt(''));

        $long = CollectionContent::excerpt('<p>' . str_repeat('woord ', 60) . '</p>', 40);
        $this->assertLessThanOrEqual(41, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
        $this->assertStringNotContainsString('<', $long);
    }

    public function testMetaDescriptionUsesTheCollectionsOwnDescriptionAndFallsBackToDutch(): void
    {
        $slug = $this->createCollection('Beschreven', true, '<p>Nederlandse tekst.</p>');
        $collection = CollectionContent::forPublicPage($slug);
        $this->assertNotNull($collection);

        $this->assertSame('Nederlandse tekst.', CollectionContent::metaDescription($collection, 'nl'));
        $this->assertSame('Nederlandse tekst.', CollectionContent::metaDescription($collection, 'en'));

        ShopLocalization::saveCollection((int) $collection['id'], 'en', [
            ShopLocalization::DESCRIPTION => '<p>English text.</p>',
        ]);
        ShopLocalization::clearCache();

        $this->assertSame('English text.', CollectionContent::metaDescription($collection, 'en'));
    }

    public function testMetaDescriptionIsEmptyWithoutADescriptionSoTheTagCanBeOmitted(): void
    {
        $slug = $this->createCollection('Woordloos', true);
        $collection = CollectionContent::forPublicPage($slug);
        $this->assertNotNull($collection);

        $this->assertSame('', CollectionContent::metaDescription($collection));
    }
}
