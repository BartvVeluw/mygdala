<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Repository\ProductRepository;
use App\Service\Personalization\ProductPersonalizationContent;
use App\Service\ProductDetail;
use App\Service\ProductPurchasePath;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonalizationTestConfig;
use Tests\Support\ShopStockFixture;

/**
 * The two pieces the product page and the Uitgelicht product block share, on
 * the test database:
 *
 *   - App\Service\ProductDetail: one active product as a browser gets it —
 *     nothing for an inactive or unknown product, no price at all for one
 *     "op aanvraag" (not the product's, not a variant's), the stock as
 *     "sold out" and a maximum, and withoutPrices() for a place that neither
 *     shows a price nor sells;
 *   - App\Service\ProductPurchasePath: the four answers in their order —
 *     inquiry before a configurator, a configurator before "not orderable",
 *     and the ordinary cart otherwise — and no configurator for an inquiry
 *     product even when one is set up.
 */
final class ProductPurchasePathTest extends TestCase
{
    private ShopStockFixture $shop;

    protected function setUp(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'multilingual' => true]);
        $this->shop = new ShopStockFixture();
        ProductPersonalizationContent::clearCache();
    }

    protected function tearDown(): void
    {
        $this->shop->cleanUp();
        ProductPersonalizationContent::clearCache();
        ShopLocalization::clearCache();
        ModuleRegistry::overrideForTests(null);
    }

    // --------------------------------------------------------- ProductDetail

    public function testOnlyAnActiveProductHasAPayload(): void
    {
        $product = $this->shop->product('ZZ Detail', 4, 12.50);

        $payload = ProductDetail::forPublic($product, 'nl');
        self::assertIsArray($payload);
        self::assertSame('ZZ Detail', $payload['name']);
        self::assertSame('12.50', $payload['price']);
        self::assertFalse($payload['inquiry']);
        self::assertArrayNotHasKey('purchase_mode', $payload, 'the mode is said as `inquiry`, never as the column');
        self::assertTrue($payload['stock_tracked']);
        self::assertSame(['sold_out' => false, 'max_quantity' => 4], ['sold_out' => $payload['sold_out'], 'max_quantity' => $payload['max_quantity']]);
        self::assertArrayNotHasKey('stock', $payload, 'never the figure as a column');

        (new ProductRepository())->setActive($product, false);
        self::assertNull(ProductDetail::forPublic($product, 'nl'));
        self::assertNull(ProductDetail::forPublic(0, 'nl'));
        self::assertNull(ProductDetail::forPublic(999999999, 'nl'));
    }

    public function testAnInquiryProductSendsNoPriceAtAll(): void
    {
        ['product' => $product] = $this->shop->variantProduct('ZZ Aanvraag', ['Eiken' => 0, 'Noten' => 0], false, 99.00);
        \App\Database::connection()->prepare('UPDATE product_variants SET price = 120.00 WHERE product_id = :id')->execute(['id' => $product]);
        (new ProductRepository())->updatePurchaseMode($product, 'inquiry');

        $payload = ProductDetail::forPublic($product, 'nl');

        self::assertTrue($payload['inquiry']);
        self::assertNull($payload['price']);
        self::assertCount(2, $payload['variants']);
        foreach ($payload['variants'] as $variant) {
            self::assertNull($variant['price']);
        }
        // The amounts as the payload would carry them; a bare "99" could be
        // part of a random slug or an id.
        self::assertStringNotContainsString('99.00', (string) json_encode($payload));
        self::assertStringNotContainsString('120.00', (string) json_encode($payload));
    }

    public function testWithoutPricesLeavesEverythingElseAlone(): void
    {
        ['product' => $product] = $this->shop->variantProduct('ZZ Zonder prijs', ['A' => 1, 'B' => 0], true, 30.00);
        $payload = ProductDetail::forPublic($product, 'nl');
        $stripped = ProductDetail::withoutPrices($payload);

        self::assertNull($stripped['price']);
        foreach ($stripped['variants'] as $index => $variant) {
            self::assertNull($variant['price']);
            unset($payload['variants'][$index]['price'], $stripped['variants'][$index]['price']);
        }
        unset($payload['price'], $stripped['price']);
        self::assertSame($payload, $stripped, 'only the prices go');
    }

    // --------------------------------------------------- ProductPurchasePath

    public function testAnOrdinaryProductGoesInTheCart(): void
    {
        $product = $this->shop->product('ZZ Gewoon', null, 10.00);

        self::assertSame(['path' => ProductPurchasePath::CART, 'personalization' => null], ProductPurchasePath::forProduct($product));
    }

    public function testInquiryComesFirstAndOffersNoConfigurator(): void
    {
        $product = $this->shop->product('ZZ Aanvraag met configurator', null, 10.00);
        PersonalizationTestConfig::singleZone($product);
        (new ProductRepository())->updatePurchaseMode($product, 'inquiry');
        ProductPersonalizationContent::clearCache();

        self::assertSame(['path' => ProductPurchasePath::INQUIRY, 'personalization' => null], ProductPurchasePath::forProduct($product));
    }

    public function testAConfiguratorHoldsThePurchaseAction(): void
    {
        $product = $this->shop->product('ZZ Personaliseerbaar', null, 10.00);
        PersonalizationTestConfig::singleZone($product);
        ProductPersonalizationContent::clearCache();

        $purchase = ProductPurchasePath::forProduct($product);
        self::assertSame(ProductPurchasePath::PERSONALIZE, $purchase['path']);
        self::assertIsArray($purchase['personalization']);

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => false, 'portfolio' => true, 'multilingual' => true]);
        ProductPersonalizationContent::clearCache();
        self::assertSame(ProductPurchasePath::CART, ProductPurchasePath::forProduct($product)['path'], 'Personalisatie off: the ordinary cart');
    }

    public function testAPersonalizationOnlyProductWithNothingToConfigureCannotBeOrdered(): void
    {
        $product = (new ProductRepository())->create([
            'slug' => '__test_purchase_' . bin2hex(random_bytes(4)),
            'price' => 9.00,
            'image_path' => null,
            'active' => true,
            'in_shop' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 10,
            'requires_parcel' => false,
        ]);
        $this->shop->trackProduct($product);

        self::assertSame(['path' => ProductPurchasePath::UNORDERABLE, 'personalization' => null], ProductPurchasePath::forProduct($product));

        PersonalizationTestConfig::singleZone($product);
        ProductPersonalizationContent::clearCache();
        self::assertSame(ProductPurchasePath::PERSONALIZE, ProductPurchasePath::forProduct($product)['path'], 'with something to configure it is ordered through it');
    }
}
