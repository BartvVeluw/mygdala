<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\CollectionContent;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * Collection SEO: which title, description and social image
 * /collecties/{slug} gets, and that all of it falls back to the collection's
 * own CMS content when the SEO fields are left empty.
 *
 * The fallback ORDER for the social image is asserted against real rows —
 * "the first photo of the first active product in the collection" is a
 * database question, and it is the one step of the chain that could silently
 * start returning something else.
 */
final class CollectionSeoTest extends TestCase
{
    /**
     * The site name and base URL this test gives the installation itself:
     * both are site configuration, so asserting whatever the test database
     * or the container happens to hold would test that, not the convention.
     */
    private const SITE = 'Voorbeeldwinkel';
    private const BASE_URL = 'https://www.voorbeeldwinkel.example';
    private const SLUG_PREFIX = '__test-seo-collection-';

    private CollectionRepository $collections;
    private ProductRepository $products;

    /** @var list<int> */
    private array $collectionIds = [];
    /** @var list<int> */
    private array $productIds = [];

    private ?string $appUrlBefore = null;

    protected function setUp(): void
    {
        $this->collections = new CollectionRepository();
        $this->products = new ProductRepository();
        CollectionContent::clearCache();

        $this->appUrlBefore = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = self::BASE_URL;
    }

    protected function tearDown(): void
    {
        if ($this->appUrlBefore === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->appUrlBefore;
        }
        SiteSettings::overrideForTests(null);

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

    /** @param array<string, mixed> $overrides */
    private function createCollection(array $overrides = []): array
    {
        $slug = self::SLUG_PREFIX . bin2hex(random_bytes(4));

        $id = $this->collections->create($overrides + [
            'name' => 'Onderzetters',
            'name_en' => 'Coasters',
            'slug' => $slug,
            'description' => '<p>Onderzetters van berkenhout.</p>',
            'description_en' => '<p>Birch coasters.</p>',
            'image_path' => null,
            'is_active' => true,
        ]);
        $this->collectionIds[] = $id;

        $collection = CollectionContent::forPublicPage($slug);
        $this->assertNotNull($collection);
        CollectionContent::clearCache();

        return $collection;
    }

    private function createProduct(string $name, ?string $imagePath, bool $active = true): int
    {
        $id = $this->products->create([
            'name' => $name,
            'name_en' => null,
            'slug' => '__test-seo-product-' . bin2hex(random_bytes(6)),
            'description' => null,
            'description_en' => null,
            'price' => 9.95,
            'image_path' => $imagePath,
            'active' => $active,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 20,
            'requires_parcel' => false,
        ]);

        $this->productIds[] = $id;

        return $id;
    }

    // ---------------------------------------------------------------- title

    public function testACustomSeoTitleIsUsedVerbatim(): void
    {
        $collection = $this->createCollection([
            'meta_title' => 'Onderzetters uit Nijmegen',
            'meta_title_en' => 'Coasters from Nijmegen',
        ]);

        $this->assertSame('Onderzetters uit Nijmegen', CollectionContent::seoTitle($collection, 'nl'));
        $this->assertSame('Coasters from Nijmegen', CollectionContent::seoTitle($collection, 'en'));
    }

    public function testTheTitleFallsBackToTheCollectionNamePlusTheSiteTitleConvention(): void
    {
        $collection = $this->createCollection();
        SiteSettings::overrideForTests(['site_name' => self::SITE]);

        $this->assertSame('Onderzetters | Shop — ' . self::SITE, CollectionContent::seoTitle($collection, 'nl'));
        $this->assertSame('Coasters | Shop — ' . self::SITE, CollectionContent::seoTitle($collection, 'en'));
    }

    public function testAnEmptyEnglishSeoTitleFallsBackToTheDutchOne(): void
    {
        $collection = $this->createCollection(['meta_title' => 'Alleen Nederlands']);

        $this->assertSame('Alleen Nederlands', CollectionContent::seoTitle($collection, 'en'));
    }

    // ---------------------------------------------------------- description

    public function testACustomMetaDescriptionIsUsedVerbatim(): void
    {
        $collection = $this->createCollection([
            'meta_description' => 'Handgemaakte onderzetters, gegraveerd in Nijmegen.',
            'meta_description_en' => 'Handmade coasters, engraved in Nijmegen.',
        ]);

        $this->assertSame(
            'Handgemaakte onderzetters, gegraveerd in Nijmegen.',
            CollectionContent::metaDescription($collection, 'nl')
        );
        $this->assertSame(
            'Handmade coasters, engraved in Nijmegen.',
            CollectionContent::metaDescription($collection, 'en')
        );
    }

    public function testTheDescriptionFallsBackToPlainTextFromTheCollectionDescription(): void
    {
        $collection = $this->createCollection();

        $this->assertSame('Onderzetters van berkenhout.', CollectionContent::metaDescription($collection, 'nl'));
        $this->assertSame('Birch coasters.', CollectionContent::metaDescription($collection, 'en'));
    }

    public function testACollectionWithNothingToSayGetsNoDescriptionAtAll(): void
    {
        $collection = $this->createCollection(['description' => null, 'description_en' => null]);

        $this->assertSame('', CollectionContent::metaDescription($collection, 'nl'));
    }

    // ------------------------------------------------------- canonical URLs

    public function testTheCanonicalUrlIsTheCollectionPageItself(): void
    {
        $collection = $this->createCollection();

        $this->assertSame(
            self::BASE_URL . '/collecties/' . $collection['slug'],
            CollectionContent::canonicalUrl($collection)
        );
    }

    // --------------------------------------------------------- social image

    public function testACustomSocialImageWinsOverTheCollectionImage(): void
    {
        $collection = $this->createCollection([
            'image_path' => 'assets/images/sections/collection.png',
        ]);
        $this->collections->updateOgImagePath((int) $collection['id'], 'assets/images/sections/social.png');
        CollectionContent::clearCache();

        $reloaded = CollectionContent::forPublicPage($collection['slug']);

        $this->assertSame('assets/images/sections/social.png', CollectionContent::socialImagePath($reloaded));
    }

    public function testTheSocialImageFallsBackToTheCollectionsOwnImage(): void
    {
        $collection = $this->createCollection(['image_path' => 'assets/images/sections/collection.png']);

        $this->assertSame('assets/images/sections/collection.png', CollectionContent::socialImagePath($collection));
    }

    public function testWithoutACollectionImageTheFirstActiveProductPhotoIsUsed(): void
    {
        $collection = $this->createCollection();

        $withoutPhoto = $this->createProduct('__test zonder foto', null);
        $withPhoto = $this->createProduct('__test met foto', 'assets/images/products/eerste.png');

        $this->collections->setCollectionProducts((int) $collection['id'], [$withoutPhoto, $withPhoto]);

        $this->assertSame('assets/images/products/eerste.png', CollectionContent::socialImagePath($collection));
    }

    public function testAnInactiveProductNeverContributesTheSocialImage(): void
    {
        $collection = $this->createCollection();

        $hidden = $this->createProduct('__test verborgen', 'assets/images/products/verborgen.png', false);
        $visible = $this->createProduct('__test zichtbaar', 'assets/images/products/zichtbaar.png');

        $this->collections->setCollectionProducts((int) $collection['id'], [$hidden, $visible]);

        $this->assertSame('assets/images/products/zichtbaar.png', CollectionContent::socialImagePath($collection));
    }

    public function testAnEmptyCollectionIsLeftToTheSiteWideSocialImage(): void
    {
        $collection = $this->createCollection();

        $this->assertNull(CollectionContent::socialImagePath($collection));
    }
}
