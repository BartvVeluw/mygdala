<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ShopModule;
use App\Repository\ProductRepository;
use App\Service\Inventory\StockNotifications;
use App\Service\ProductSeo;
use App\Service\PurchaseMode;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\FakeMollie;
use Tests\Support\ShopStockFixture;

/**
 * "Op aanvraag" (Shop Product & Ordering 2.0, App\Service\PurchaseMode,
 * MODULES.md "Op aanvraag") over real HTTP with the Shop on:
 *
 *   - the product page shows no price, no quantity and no cart, but keeps
 *     the variant picker, the description and a way to get in touch;
 *   - the product API and the card API send no price at all, for the
 *     product and for every variant, and say "inquiry";
 *   - the structured data has no Offer; a direct product keeps its Offer,
 *     and says OutOfStock only when every unit is sold out;
 *   - the cart check and the checkout refuse the product whatever the
 *     browser sends, and nobody can ask for a back-in-stock mail for it;
 *   - the product editor stores the choice and refuses a value off the list.
 */
final class PurchaseModeHttpTest extends TestCase
{
    private const TEST_KEY = 'test_purchasemodepurchasemode1234567';

    private static ?BuiltInServer $server = null;
    private static string $directory = '';

    private AdminTestSession $accounts;
    private ShopStockFixture $fixture;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/mygdala-purchase-mode-' . bin2hex(random_bytes(6));
        mkdir(self::$directory, 0700, true);

        self::$server = BuiltInServer::start([
            'MODULE_SHOP_ENABLED' => 'true',
            'MOLLIE_API_KEY' => self::TEST_KEY,
            'APP_KEY' => '',
            'SECRETS_STORAGE_PATH' => self::$directory,
            'FAKE_MOLLIE_SCENARIO' => self::$directory . '/scenario.json',
        ], null, ['auto_prepend_file' => dirname(__DIR__, 2) . '/tests/Support/fake-mollie.php']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        foreach (glob(self::$directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir(self::$directory);
    }

    protected function setUp(): void
    {
        $this->accounts = new AdminTestSession();
        $this->fixture = new ShopStockFixture();

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        FakeMollie::write(self::$directory . '/scenario.json', ['keys' => [self::TEST_KEY => 'ok']]);
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanUp();
        $this->accounts->forget();
    }

    public function testTheProductPageShowsTheProductButNoPriceQuantityOrCart(): void
    {
        $made = $this->fixture->variantProduct('ZZ Aanvraag Bord', ['Eiken' => 0, 'Noten' => 0], false, 125.00);
        (new ProductRepository())->updatePurchaseMode($made['product'], PurchaseMode::INQUIRY);

        $page = self::$server->request('GET', '/product.php?id=' . $made['product']);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('data-product-inquiry', $page['body']);
        self::assertStringContainsString('Prijs en bestellen op aanvraag', $page['body']);
        self::assertStringContainsString('data-product-variants', $page['body'], 'the variant picker stays');
        self::assertStringNotContainsString('data-product-add-to-cart', $page['body']);
        self::assertStringNotContainsString('data-product-qty', $page['body']);
        self::assertStringNotContainsString('125.00', $page['body'], 'no price anywhere in the page, the structured data included');
        self::assertStringNotContainsString('125,00', $page['body']);
        self::assertStringNotContainsString('"offers"', $page['body']);
        self::assertStringContainsString('"@type":"Product"', str_replace(' ', '', $page['body']));
    }

    public function testTheApisSendNoPriceForTheProductOrItsVariants(): void
    {
        $made = $this->fixture->variantProduct('ZZ Aanvraag Api', ['A' => 0], false, 88.00);
        $direct = $this->fixture->product('ZZ Direct Api', null, 12.50);
        (new ProductRepository())->updatePurchaseMode($made['product'], PurchaseMode::INQUIRY);

        $data = json_decode(self::$server->request('GET', '/api/product.php?id=' . $made['product'])['body'], true)['data'];
        self::assertTrue($data['inquiry']);
        self::assertNull($data['price']);
        self::assertArrayNotHasKey('purchase_mode', $data);
        self::assertCount(1, $data['variants'], 'the variants are still there to choose');
        self::assertNull($data['variants'][0]['price']);

        $cards = json_decode(self::$server->request('GET', '/api/products.php?ids=' . $made['product'] . ',' . $direct)['body'], true)['data'];
        $byId = array_column($cards, null, 'id');
        self::assertTrue($byId[$made['product']]['inquiry']);
        self::assertNull($byId[$made['product']]['price'], 'a card shows no price either');
        self::assertFalse($byId[$direct]['inquiry']);
        self::assertSame('12.50', (string) $byId[$direct]['price']);
    }

    public function testTheStructuredDataHasNoOfferAndADirectProductSaysWhetherItIsInStock(): void
    {
        $inquiry = $this->fixture->product('ZZ Aanvraag Seo', null, 40.00);
        (new ProductRepository())->updatePurchaseMode($inquiry, PurchaseMode::INQUIRY);
        $soldOut = $this->fixture->product('ZZ Uitverkocht Seo', 0, 40.00);
        $inStock = $this->fixture->product('ZZ Op Voorraad Seo', 3, 40.00);

        $jsonLd = static fn (int $id): array => ProductSeo::resolve((new ProductRepository())->findActiveByIdWithSeo($id))['json_ld'];

        self::assertArrayNotHasKey('offers', $jsonLd($inquiry));
        self::assertSame('ZZ Aanvraag Seo', $jsonLd($inquiry)['name']);
        self::assertSame('https://schema.org/OutOfStock', $jsonLd($soldOut)['offers']['availability']);
        self::assertSame('https://schema.org/InStock', $jsonLd($inStock)['offers']['availability']);
        self::assertSame('40.00', $jsonLd($inStock)['offers']['price']);
    }

    public function testTheCartCheckAndTheCheckoutRefuseItWhateverTheBrowserSends(): void
    {
        $inquiry = $this->fixture->product('ZZ Aanvraag Kassa', null, 30.00);
        (new ProductRepository())->updatePurchaseMode($inquiry, PurchaseMode::INQUIRY);

        $check = self::$server->postJson('/api/cart-check.php', ['items' => [['id' => $inquiry, 'qty' => 1]]]);
        self::assertSame([['status' => 'inquiry', 'available' => null]], json_decode($check['body'], true)['lines']);

        $checkout = self::$server->postJson('/api/checkout.php', [
            'voornaam' => 'Test', 'achternaam' => 'Klant', 'email' => 'purchase-mode@example.invalid', 'telefoon' => '',
            'verzendmethode' => 'verzenden', 'land' => 'BE', 'postcode' => '1000', 'huisnummer' => '1',
            'straat' => 'Grote Markt', 'plaats' => 'Brussel', 'betaalmethode' => 'ideal',
            'terms_accepted' => true, 'facturatie_zelfde' => true, 'language' => 'nl',
            'items' => [['id' => $inquiry, 'qty' => 1]],
        ]);
        self::assertSame(409, $checkout['status']);
        self::assertSame('ZZ Aanvraag Kassa is alleen op aanvraag te bestellen. Neem contact met ons op.', json_decode($checkout['body'], true)['error']);

        self::assertSame(StockNotifications::UNAVAILABLE, (new StockNotifications())->subscribe($inquiry, null, 'x@example.com', 'nl'));
    }

    public function testTheEditorStoresTheChoiceAndRefusesAnythingElse(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $product = $this->fixture->product('ZZ Aanvraag Editor');

        $page = self::$server->request('GET', '/admin/product-form.php?id=' . $product, $session);
        self::assertMatchesRegularExpression('/<option value="direct" selected>Direct bestellen<\/option>/', $page['body']);

        $fields = [
            'csrf_token' => $csrf, 'id' => (string) $product, 'language_code' => 'nl', 'name' => 'ZZ Aanvraag Editor',
            'description' => '', 'price' => '10', 'active' => '1', 'in_shop' => '1',
            'shipping_profile' => 'letter', 'shipping_weight_grams' => '20',
        ];

        $refused = self::$server->request('POST', '/api/admin/update-product.php', $session, $fields + ['purchase_mode' => 'gratis'], [], ['Accept: application/json']);
        self::assertSame(422, $refused['status']);
        self::assertArrayHasKey('purchase_mode', json_decode($refused['body'], true)['errors']);
        self::assertSame(PurchaseMode::DIRECT, (new ProductRepository())->purchaseMode($product));

        $saved = self::$server->request('POST', '/api/admin/update-product.php', $session, $fields + ['purchase_mode' => 'inquiry'], [], ['Accept: application/json']);
        self::assertSame(200, $saved['status']);
        self::assertSame(PurchaseMode::INQUIRY, (new ProductRepository())->purchaseMode($product));

        $withoutField = self::$server->request('POST', '/api/admin/update-product.php', $session, $fields, [], ['Accept: application/json']);
        self::assertSame(200, $withoutField['status']);
        self::assertSame(PurchaseMode::INQUIRY, (new ProductRepository())->purchaseMode($product), 'a request without the field leaves it alone');

        $list = self::$server->request('GET', '/admin/products.php', $session);
        self::assertStringContainsString('Op aanvraag', $list['body']);
    }
}
