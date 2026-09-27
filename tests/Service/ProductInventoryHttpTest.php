<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ShopModule;
use App\Repository\InventoryRepository;
use App\Repository\ProductRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\FakeMollie;
use Tests\Support\ShopStockFixture;

/**
 * Stock over real HTTP, against PHP's built-in server with the Shop on (Shop
 * Product & Ordering 2.0):
 *
 *   - the product editor stores "Voorraad bijhouden" and a stock in its one
 *     save, and draws the status beside it;
 *   - a save that did not change the stock never touches it, and a changed
 *     stock over a value that sold in the meantime is refused (422) with
 *     nothing stored, the price included;
 *   - a variant's stock is part of its row, with the same rule;
 *   - an invalid stock is refused under its field;
 *   - api/product.php publishes "sold out" and a maximum, never the figure;
 *   - api/cart-check.php answers per line;
 *   - api/checkout.php refuses a sold-out or too-short line with a message
 *     in the customer's language, before anything is stored.
 */
final class ProductInventoryHttpTest extends TestCase
{
    private const JSON = ['Accept: application/json'];
    private const TEST_KEY = 'test_inventoryinventoryinventory12345';

    private static ?BuiltInServer $server = null;
    private static string $directory = '';

    private AdminTestSession $accounts;
    private ShopStockFixture $fixture;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/mygdala-inventory-' . bin2hex(random_bytes(6));
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

    public function testTheEditorStoresTrackingAndStockAndShowsTheStatus(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $productId = $this->fixture->product('ZZ Voorraad Editor');

        $page = self::$server->request('GET', '/admin/product-form.php?id=' . $productId, $session);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('name="inventory_present"', $page['body']);
        self::assertStringContainsString('Voorraad niet bijgehouden', $page['body']);
        self::assertMatchesRegularExpression('/<input[^>]*name="track_stock"(?![^>]*checked)[^>]*>/', $page['body'], 'off for an existing product');

        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, [
            'inventory_present' => '1', 'track_stock' => '1', 'stock' => '5', 'stock_seen' => '0',
        ]), [], self::JSON);
        self::assertSame(200, $response['status'], $response['body']);

        $state = (new InventoryRepository())->productState($productId);
        self::assertSame(['track_stock' => true, 'stock' => 5], $state);

        $page = self::$server->request('GET', '/admin/product-form.php?id=' . $productId, $session);
        self::assertStringContainsString('5 op voorraad', $page['body']);
        self::assertStringContainsString('name="stock_seen" value="5"', $page['body']);
    }

    public function testASaveThatDidNotChangeTheStockNeverTouchesIt(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $productId = $this->fixture->product('ZZ Voorraad Ongewijzigd', 5);

        // A customer takes one after the screen showed 5.
        (new InventoryRepository())->takeFromProduct($productId, 1);

        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, [
            'price' => '12,00', 'inventory_present' => '1', 'track_stock' => '1', 'stock' => '5', 'stock_seen' => '5',
        ]), [], self::JSON);

        self::assertSame(200, $response['status']);
        self::assertSame(4, $this->fixture->productStock($productId), 'the sale stands');
        self::assertSame('12.00', (string) (new ProductRepository())->findByIdForAdmin($productId)['price']);
    }

    public function testAChangedStockOverASaleIsRefusedWithNothingStored(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $productId = $this->fixture->product('ZZ Voorraad Conflict', 5);
        (new InventoryRepository())->takeFromProduct($productId, 1);

        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, [
            'price' => '99,00', 'inventory_present' => '1', 'track_stock' => '1', 'stock' => '8', 'stock_seen' => '5',
        ]), [], self::JSON);

        self::assertSame(422, $response['status']);
        $body = json_decode($response['body'], true);
        self::assertArrayHasKey('stock', $body['errors']);
        self::assertStringContainsString('er zijn er nu 4', implode(' ', (array) $body['errors']['stock']));
        self::assertSame(4, $this->fixture->productStock($productId));
        self::assertSame('10.00', (string) (new ProductRepository())->findByIdForAdmin($productId)['price'], 'the whole save rolled back');
    }

    public function testAnInvalidStockIsRefusedUnderItsField(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $productId = $this->fixture->product('ZZ Voorraad Ongeldig');

        foreach (['-1', 'veel', '1.5', ''] as $value) {
            $response = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, [
                'inventory_present' => '1', 'track_stock' => '1', 'stock' => $value, 'stock_seen' => '0',
            ]), [], self::JSON);

            self::assertSame(422, $response['status'], var_export($value, true));
            self::assertArrayHasKey('stock', json_decode($response['body'], true)['errors']);
        }
        self::assertFalse((new InventoryRepository())->productState($productId)['track_stock']);
    }

    public function testARequestWithoutTheSectionLeavesTheStockAlone(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $productId = $this->fixture->product('ZZ Voorraad Zonder Sectie', 3);

        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($productId, $csrf, []), [], self::JSON);

        self::assertSame(200, $response['status']);
        self::assertSame(['track_stock' => true, 'stock' => 3], (new InventoryRepository())->productState($productId));
    }

    public function testAVariantsStockIsPartOfItsRow(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $made = $this->fixture->variantProduct('ZZ Voorraad Varianten', ['A' => 0, 'B' => 2]);
        $a = $made['variants']['A'];
        $b = $made['variants']['B'];

        $page = self::$server->request('GET', '/admin/product-form.php?id=' . $made['product'], $session);
        self::assertStringContainsString('name="variants[' . $a . '][stock]"', $page['body']);
        self::assertStringContainsString('Uitverkocht', $page['body']);
        self::assertStringContainsString('2 varianten, waarvan 1 uitverkocht.', $page['body']);
        self::assertStringNotContainsString('name="stock"', $page['body'], 'a variant product has no stock of its own on the screen');

        (new InventoryRepository())->takeFromVariant($made['product'], $b, 1);

        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($made['product'], $csrf, [
            'inventory_present' => '1', 'track_stock' => '1',
            'variants_present' => '1',
            'variants' => [
                $a => ['price' => '', 'active' => '1', 'stock' => '6', 'stock_seen' => '0'],
                $b => ['price' => '', 'active' => '1', 'stock' => '5', 'stock_seen' => '2'],
            ],
        ]), [], self::JSON);

        self::assertSame(422, $response['status']);
        self::assertArrayHasKey('variants[' . $b . '][stock]', json_decode($response['body'], true)['errors']);
        self::assertSame(0, $this->fixture->variantStock($a), 'nothing stored: A waits for B');

        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($made['product'], $csrf, [
            'inventory_present' => '1', 'track_stock' => '1',
            'variants_present' => '1',
            'variants' => [
                $a => ['price' => '', 'active' => '1', 'stock' => '6', 'stock_seen' => '0'],
                $b => ['price' => '', 'active' => '1', 'stock' => '1', 'stock_seen' => '1'],
            ],
        ]), [], self::JSON);

        self::assertSame(200, $response['status']);
        self::assertSame(6, $this->fixture->variantStock($a));
        self::assertSame(1, $this->fixture->variantStock($b));
    }

    public function testTheProductApiPublishesSoldOutAndAMaximumButNeverTheFigure(): void
    {
        $untracked = $this->fixture->product('ZZ Api Onbeperkt');
        $empty = $this->fixture->product('ZZ Api Leeg', 0);
        $made = $this->fixture->variantProduct('ZZ Api Varianten', ['A' => 0, 'B' => 3]);

        $data = $this->product($untracked);
        self::assertArrayNotHasKey('stock', $data);
        self::assertFalse($data['stock_tracked']);
        self::assertFalse($data['sold_out']);
        self::assertNull($data['max_quantity']);

        $data = $this->product($empty);
        self::assertTrue($data['stock_tracked']);
        self::assertTrue($data['sold_out']);
        self::assertSame(0, $data['max_quantity']);

        $data = $this->product($made['product']);
        $byId = array_column($data['variants'], null, 'id');
        self::assertTrue($byId[$made['variants']['A']]['sold_out']);
        self::assertFalse($byId[$made['variants']['B']]['sold_out']);
        self::assertSame(3, $byId[$made['variants']['B']]['max_quantity']);
        self::assertArrayNotHasKey('stock', $byId[$made['variants']['B']]);
    }

    public function testCartCheckAnswersPerLine(): void
    {
        $three = $this->fixture->product('ZZ Check Drie', 3);
        $made = $this->fixture->variantProduct('ZZ Check Varianten', ['A' => 0, 'B' => 2]);

        $response = self::$server->postJson('/api/cart-check.php', ['items' => [
            ['id' => $three, 'qty' => 2],
            ['id' => $three, 'qty' => 2, 'variant_id' => null],
            ['id' => $made['product'], 'variant_id' => $made['variants']['A'], 'qty' => 1],
            ['id' => $made['product'], 'variant_id' => $made['variants']['B'], 'qty' => 1],
            ['id' => 'forged', 'qty' => 1],
        ]]);

        self::assertSame(200, $response['status']);
        self::assertSame([
            ['status' => 'insufficient', 'available' => 3],
            ['status' => 'insufficient', 'available' => 3],
            ['status' => 'sold_out', 'available' => 0],
            ['status' => 'ok', 'available' => 2],
            ['status' => 'unavailable', 'available' => null],
        ], json_decode($response['body'], true)['lines']);

        self::assertSame(400, self::$server->postJson('/api/cart-check.php', ['items' => 'x'])['status']);
        self::assertSame(405, self::$server->request('GET', '/api/cart-check.php')['status']);
    }

    public function testTheCheckoutRefusesASoldOutOrShortLineBeforeAnythingIsStored(): void
    {
        $empty = $this->fixture->product('ZZ Checkout Uitverkocht', 0);
        $two = $this->fixture->product('ZZ Checkout Twee', 2);

        [$status, $body] = $this->checkout(['items' => [['id' => $empty, 'qty' => 1]]]);
        self::assertSame(409, $status);
        self::assertSame('ZZ Checkout Uitverkocht is uitverkocht.', $body['error']);

        [$status, $body] = $this->checkout(['items' => [['id' => $two, 'qty' => 3]], 'language' => 'en']);
        self::assertSame(409, $status);
        self::assertSame('Only 2 of ZZ Checkout Twee left in stock.', $body['error']);

        self::assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM orders o JOIN customers c ON c.id = o.customer_id WHERE c.email = 'inventory-checkout@example.invalid'")->fetchColumn());
        self::assertSame(2, $this->fixture->productStock($two));
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function product(int $id): array
    {
        $response = self::$server->request('GET', '/api/product.php?id=' . $id);
        self::assertSame(200, $response['status']);

        return json_decode($response['body'], true)['data'];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function checkout(array $overrides): array
    {
        $response = self::$server->postJson('/api/checkout.php', $overrides + [
            'voornaam' => 'Test', 'achternaam' => 'Klant', 'email' => 'inventory-checkout@example.invalid', 'telefoon' => '',
            'verzendmethode' => 'verzenden', 'land' => 'BE', 'postcode' => '1000', 'huisnummer' => '1',
            'straat' => 'Grote Markt', 'plaats' => 'Brussel', 'betaalmethode' => 'ideal',
            'terms_accepted' => true, 'facturatie_zelfde' => true, 'language' => 'nl',
        ]);

        return [$response['status'], (array) json_decode($response['body'], true)];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function fields(int $productId, string $csrf, array $overrides): array
    {
        return $overrides + [
            'csrf_token' => $csrf,
            'id' => (string) $productId,
            'language_code' => 'nl',
            'name' => 'ZZ Voorraad',
            'description' => '',
            'price' => '10',
            'active' => '1',
            'in_shop' => '1',
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => '20',
        ];
    }
}
