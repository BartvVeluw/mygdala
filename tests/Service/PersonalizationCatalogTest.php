<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\Personalization\PersonalizationCatalog;
use App\Service\Personalization\PersonalizationRules;
use App\Service\Personalization\PersonalizationValidationException;
use App\Service\Personalization\PersonalizationValidator;
use App\Service\Personalization\ProductPersonalizationContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonalizationTestConfig;

/**
 * Where a product is allowed to be sold, now that "publicly visible" and "in
 * the shop" are no longer the same question.
 *
 * `products.active` stays the master switch; `in_shop` and
 * `in_personalization_catalog` say which public catalogue a visible product
 * belongs to (see
 * db/migrations/20260908220000_add_product_availability_channels.php). The
 * three states that matter are shop-only, personalization-only, and both.
 *
 * The one that has to be airtight is PERSONALIZATION-ONLY: such a product has
 * no ordinary purchase path at all, so it must be absent from the shop grid,
 * absent from collection pages and related products, and impossible to order
 * blank — not merely hidden in the UI, but refused server-side.
 */
final class PersonalizationCatalogTest extends TestCase
{
    private const SLUG_PREFIX = 'zz-test-channel-';

    private ProductRepository $products;
    private CollectionRepository $collections;
    private PersonalizationValidator $validator;

    /** @var list<int> */
    private array $productIds = [];
    /** @var list<int> */
    private array $collectionIds = [];

    protected function setUp(): void
    {
        $this->products = new ProductRepository();
        $this->collections = new CollectionRepository();
        $this->validator = new PersonalizationValidator();

        ProductPersonalizationContent::clearCache();
        PersonalizationCatalog::clearCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->collectionIds as $id) {
            $db->prepare('DELETE FROM collections WHERE id = :id')->execute(['id' => $id]);
        }

        $this->productIds = [];
        $this->collectionIds = [];

        ProductPersonalizationContent::clearCache();
        PersonalizationCatalog::clearCache();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createProduct(array $overrides = []): int
    {
        $id = $this->products->create($overrides + [
            'name' => 'Testproduct kanaal',
            'name_en' => null,
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)),
            'description' => null,
            'description_en' => null,
            'price' => 19.95,
            'image_path' => null,
            'active' => true,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 40,
            'requires_parcel' => false,
        ]);

        $this->productIds[] = $id;

        return $id;
    }

    /** A product that is ONLY in the personalization catalogue, fully configured. */
    private function createPersonalizationOnlyProduct(array $zoneOverrides = []): int
    {
        $productId = $this->createProduct([
            'in_shop' => false,
            'in_personalization_catalog' => true,
        ]);

        PersonalizationTestConfig::singleZone($productId, $zoneOverrides);

        return $productId;
    }

    /** @return list<int> the ids the public shop grid would render */
    private function shopGridIds(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->products->findAllActive()
        );
    }

    /* ------------------------------------------------------------------ */
    /* Defaults: nothing about an existing product changes                 */
    /* ------------------------------------------------------------------ */

    public function testANewProductIsAnOrdinaryShopProduct(): void
    {
        $productId = $this->createProduct();

        $stored = $this->products->findByIdForAdmin($productId);

        $this->assertSame(1, (int) $stored['in_shop']);
        $this->assertSame(0, (int) $stored['in_personalization_catalog']);

        $this->assertContains($productId, $this->shopGridIds());
        $this->assertTrue($this->products->isShopPurchasable($productId));
    }

    public function testAnOrdinaryProductIsNotInThePersonalizationCatalogue(): void
    {
        $productId = $this->createProduct();

        $catalog = PersonalizationCatalog::forPublicPage();

        $this->assertFalse(PersonalizationCatalog::contains($productId));
        if ($catalog !== null) {
            $this->assertNotContains($productId, $catalog['product_ids']);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Personalization-only: hidden from the shop                          */
    /* ------------------------------------------------------------------ */

    public function testAPersonalizationOnlyProductIsHiddenFromTheShopGrid(): void
    {
        $productId = $this->createPersonalizationOnlyProduct();

        $this->assertNotContains(
            $productId,
            $this->shopGridIds(),
            'a personalization-only product must not appear in the shop'
        );
    }

    public function testAPersonalizationOnlyProductIsHiddenFromCollectionPages(): void
    {
        $productId = $this->createPersonalizationOnlyProduct();
        $shopProductId = $this->createProduct();

        $collectionId = $this->collections->create([
            'name' => 'Testcollectie kanaal',
            'name_en' => null,
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)),
            'description' => null,
            'description_en' => null,
            'image_path' => null,
            'image_alt' => null,
            'is_active' => true,
            'show_related_products' => true,
            'related_heading_nl' => null,
            'related_heading_en' => null,
            'seo_title' => null,
            'seo_title_en' => null,
            'seo_description' => null,
            'seo_description_en' => null,
            'og_image_path' => null,
        ]);
        $this->collectionIds[] = $collectionId;

        $this->collections->setCollectionProducts($collectionId, [$productId, $shopProductId]);

        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->products->findAllActive($collectionId)
        );

        $this->assertContains($shopProductId, $ids);
        $this->assertNotContains(
            $productId,
            $ids,
            'a personalization-only product must not leak onto a collection page — nor into related products, '
            . 'which are built from exactly this query'
        );
    }

    /**
     * Its own product page must keep working, though: that is the page the
     * Personalisatie catalogue links to.
     */
    public function testAPersonalizationOnlyProductStillHasItsOwnPublicProductRow(): void
    {
        $productId = $this->createPersonalizationOnlyProduct();

        $product = $this->products->findActiveById($productId);

        $this->assertNotNull($product, 'a personalization-only product keeps its own public product page');
        // The existing price stays the authority — nothing about the
        // product's own data changes with the channel.
        $this->assertSame('19.95', (string) $product['price']);

        // The channels themselves stay out of the public product payload;
        // the one question a caller has is answered directly.
        $this->assertArrayNotHasKey('in_shop', $product);
        $this->assertFalse($this->products->isShopPurchasable($productId));

        $stored = $this->products->findByIdForAdmin($productId);
        $this->assertSame(0, (int) $stored['in_shop']);
        $this->assertSame(1, (int) $stored['in_personalization_catalog']);
    }

    /* ------------------------------------------------------------------ */
    /* ...and present on the Personalisatie page                           */
    /* ------------------------------------------------------------------ */

    public function testAPersonalizationOnlyProductAppearsInTheCatalogue(): void
    {
        $productId = $this->createPersonalizationOnlyProduct();

        $catalog = PersonalizationCatalog::forPublicPage();

        $this->assertNotNull($catalog);
        $this->assertContains($productId, $catalog['product_ids']);
        $this->assertTrue(PersonalizationCatalog::contains($productId));
    }

    public function testAProductInBothChannelsAppearsInBoth(): void
    {
        $productId = $this->createProduct([
            'in_shop' => true,
            'in_personalization_catalog' => true,
        ]);
        PersonalizationTestConfig::singleZone($productId);
        PersonalizationCatalog::clearCache();

        $this->assertContains($productId, $this->shopGridIds());
        $this->assertTrue(PersonalizationCatalog::contains($productId));
    }

    /**
     * Being flagged for the catalogue is not enough: the page only lists
     * products that can ACTUALLY be personalized, or it would advertise a
     * configurator that never appears.
     */
    public function testAFlaggedProductWithoutAUsableConfigurationIsNotListed(): void
    {
        // Flagged, but never configured at all.
        $unconfigured = $this->createProduct([
            'in_shop' => false,
            'in_personalization_catalog' => true,
        ]);

        // Flagged and configured, but the one view has no image of its own.
        $incomplete = $this->createProduct([
            'in_shop' => false,
            'in_personalization_catalog' => true,
        ]);
        PersonalizationTestConfig::singleZone($incomplete, [], [], null);

        // Flagged and configured, but personalization is switched off.
        $switchedOff = $this->createProduct([
            'in_shop' => false,
            'in_personalization_catalog' => true,
        ]);
        PersonalizationTestConfig::singleZone($switchedOff, [], ['is_enabled' => false]);

        PersonalizationCatalog::clearCache();
        $catalog = PersonalizationCatalog::forPublicPage();
        $listed = $catalog === null ? [] : $catalog['product_ids'];

        $this->assertNotContains($unconfigured, $listed);
        $this->assertNotContains($incomplete, $listed);
        $this->assertNotContains($switchedOff, $listed);
    }

    /* ------------------------------------------------------------------ */
    /* Purchasability                                                      */
    /* ------------------------------------------------------------------ */

    public function testAPersonalizationOnlyProductIsNotShopPurchasable(): void
    {
        $productId = $this->createPersonalizationOnlyProduct();

        $this->assertFalse($this->products->isShopPurchasable($productId));
    }

    /**
     * The rule that makes it safe: a personalization-only product resolves to
     * "personalization required" whatever its own mode says, because there is
     * no other way to buy it.
     */
    public function testAPersonalizationOnlyProductIsAlwaysRequiredEvenWhenConfiguredOptional(): void
    {
        $productId = $this->createPersonalizationOnlyProduct();

        $config = ProductPersonalizationContent::forProduct($productId);

        // Its stored setting really is "optional"...
        $this->assertSame(PersonalizationRules::PURCHASE_OPTIONAL, $config['mode']);
        // ...and it is still required, because it has no shop purchase path.
        $this->assertTrue($config['is_required']);
        $this->assertTrue($config['is_personalization_only']);
        $this->assertFalse($config['is_shop_purchasable']);
    }

    public function testAPersonalizationOnlyProductCannotBeOrderedBlank(): void
    {
        $productId = $this->createPersonalizationOnlyProduct();

        foreach ([null, [], ['zones' => []], ['zone_key' => 'default', 'text' => '  ']] as $submitted) {
            try {
                $this->validator->validate($productId, $submitted);
                $this->fail('a personalization-only product must not validate empty: ' . var_export($submitted, true));
            } catch (PersonalizationValidationException $e) {
                $this->assertStringContainsString('personalize', $e->getMessage());
            }
        }
    }

    public function testAPersonalizationOnlyProductIsAcceptedOnceItIsPersonalized(): void
    {
        $productId = $this->createPersonalizationOnlyProduct();

        $result = $this->validator->validate($productId, ['zone_key' => 'default', 'text' => 'Bart']);

        $this->assertNotNull($result);
        $this->assertSame('Bart', $result['zones'][0]['text_value']);
        // The order records that this was a personalization-only product, so
        // the reason personalization was mandatory stays legible later.
        $this->assertTrue($result['zones'][0]['config_snapshot']['personalization_only']);
    }

    /**
     * Putting the product back in the shop restores exactly the behaviour the
     * owner configured — the derived rule never overwrites the stored one.
     */
    public function testPuttingItBackInTheShopRestoresTheConfiguredMode(): void
    {
        $productId = $this->createPersonalizationOnlyProduct();

        Database::connection()
            ->prepare('UPDATE products SET in_shop = 1 WHERE id = :id')
            ->execute(['id' => $productId]);
        ProductPersonalizationContent::clearCache();

        $config = ProductPersonalizationContent::forProduct($productId);

        $this->assertTrue($config['is_shop_purchasable']);
        $this->assertFalse($config['is_required'], 'its own setting is "optional" again');
        $this->assertNull($this->validator->validate($productId, null));
    }

    /* ------------------------------------------------------------------ */
    /* The bypass the UI cannot prevent on its own                         */
    /* ------------------------------------------------------------------ */

    /**
     * A personalization-only product whose configuration is switched off or
     * unfinished resolves to NO configuration, so the validator has nothing
     * to require and lets the line through. api/checkout.php has to catch
     * that case itself, or such a product would be sold blank — which is
     * exactly the thing it must never be.
     */
    public function testCheckoutRefusesAPersonalizationOnlyProductThatCannotBePersonalized(): void
    {
        $productId = $this->createProduct([
            'in_shop' => false,
            'in_personalization_catalog' => true,
        ]);

        // No configuration at all: the validator sees nothing to enforce.
        $this->assertNull($this->validator->validate($productId, null));
        $this->assertFalse($this->products->isShopPurchasable($productId));

        // ...which is precisely the pair of conditions the checkout guard
        // tests for, before anything is priced or ordered.
        $checkout = (string) file_get_contents(dirname(__DIR__, 2) . '/api/checkout.php');

        $this->assertStringContainsString(
            '$personalization === null && !$productRepository->isShopPurchasable($id)',
            $checkout
        );

        $guardPos = strpos($checkout, 'isShopPurchasable($id)');
        $orderPos = strpos($checkout, '$orderRepository->create(');
        $this->assertIsInt($guardPos);
        $this->assertIsInt($orderPos);
        $this->assertLessThan($orderPos, $guardPos, 'the guard must run before the order is created');
    }

    /* ------------------------------------------------------------------ */
    /* The public page                                                     */
    /* ------------------------------------------------------------------ */

    public function testTheCataloguePageRendersTheShopsOwnProductGrid(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/personaliseren.php');

        // The same grid hook the shop and collection pages use — one product
        // card implementation, not a second one.
        $this->assertStringContainsString('class="shop-grid" data-products-grid', $page);
        $this->assertStringContainsString('data-product-ids=', $page);
        $this->assertStringNotContainsString('product-card', $page, 'no second card markup may live here');

        // An empty catalogue is an explained state, never an empty grid.
        $this->assertStringContainsString('$catalog === null', $page);
        $this->assertStringContainsString('geen producten om te personaliseren', $page);
    }

    /**
     * The catalogue is one fixed route answered in every published language,
     * so the sitemap lists every language's address, each naming all of them,
     * and the page declares the same set for hreflang and the language
     * switch. Before phase 7 wave E only the default address was listed.
     */
    public function testTheCatalogueIsListedInEveryPublishedLanguage(): void
    {
        if (!\App\Module\ModuleRegistry::isEnabled('personalization')) {
            $this->markTestSkipped('the Personalisatie module is off here');
        }

        $this->createPersonalizationOnlyProduct();
        PersonalizationCatalog::clearCache();

        $expected = [];
        foreach (\App\Service\Language\SiteLanguages::activeCodes() as $code) {
            $expected[$code] = \App\Service\AppUrl::canonical(
                \App\Service\Routing\LocalizedUrl::path(PersonalizationCatalog::publicPath(), $code)
            );
        }

        $entries = [];
        foreach (\App\Service\Sitemap::entries() as $entry) {
            if (in_array($entry['loc'], $expected, true)) {
                $entries[$entry['loc']] = $entry;
            }
        }

        $this->assertSame(array_values($expected), array_keys($entries), 'one entry per published language');
        foreach ($entries as $loc => $entry) {
            $this->assertSame(count($expected) > 1 ? $expected : [], $entry['alternates'], $loc . ' names every version, itself included');
        }

        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/personaliseren.php');
        $this->assertStringContainsString('LanguageAlternates::declareVersions($personalizationVersions)', $page);
    }

    public function testTheCataloguePathIsReservedAgainstCmsPageSlugs(): void
    {
        $this->assertSame('/personaliseren.php', PersonalizationCatalog::publicPath());
        $this->assertTrue(\App\Service\ReservedRoutes::isReserved('personaliseren'));
    }
}
