<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ShopModule;
use App\Repository\OrderFieldRepository;
use App\Repository\OrderItemFieldRepository;
use App\Repository\ProductRepository;
use App\Service\PurchaseMode;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\FakeMollie;
use Tests\Support\ShopStockFixture;

/**
 * Order questions over real HTTP (Shop Product & Ordering 2.0, MODULES.md
 * "Bestelvelden"), with the Shop on:
 *
 *   - the product editor stores new questions and choices in its one save
 *     and draws them again with their ids;
 *   - the product page asks them above the quantity and the button, in the
 *     page's language, and asks nothing for a product "op aanvraag";
 *   - the cart check says when a line's answers no longer fit its questions;
 *   - the checkout refuses a missing or forged answer before it stores
 *     anything, with the question named in the customer's language;
 *   - the order screen shows the answers under their line.
 */
final class OrderFieldsHttpTest extends TestCase
{
    private const JSON = ['Accept: application/json'];
    private const TEST_KEY = 'test_orderfieldsorderfieldsorder12345';

    private static ?BuiltInServer $server = null;
    private static string $directory = '';

    private AdminTestSession $accounts;
    private ShopStockFixture $fixture;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/mygdala-order-fields-' . bin2hex(random_bytes(6));
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
        ShopLocalization::clearCache();
    }

    public function testTheEditorStoresNewQuestionsInItsOneSaveAndDrawsThemAgain(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE]);
        $product = $this->fixture->product('ZZ Http Vragen');

        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($product, $csrf, [
            'order_fields_present' => '1', 'order_fields_enabled' => '1',
            'order_fields' => [
                'new0' => ['present' => '1', 'type' => 'text', 'label' => 'Naam op het bord', 'help' => '', 'required' => '1', 'max_length' => '12'],
                'new1' => ['present' => '1', 'type' => 'radio', 'label' => 'Houtsoort', 'help' => '', 'required' => '1', 'max_length' => ''],
            ],
            'order_field_options' => ['new1' => ['new0' => ['present' => '1', 'label' => 'Eiken'], 'new1' => ['present' => '1', 'label' => 'Noten']]],
        ]), [], self::JSON);
        self::assertSame(200, $response['status'], $response['body']);

        $fields = (new OrderFieldRepository())->fieldsForProduct($product);
        self::assertSame(['text', 'radio'], array_column($fields, 'field_type'));

        $page = self::$server->request('GET', '/admin/product-form.php?id=' . $product, $session);
        self::assertStringContainsString('name="order_fields[' . $fields[0]['id'] . '][label]"', $page['body'], 'drawn again with its id');
        self::assertStringContainsString('value="Naam op het bord"', $page['body']);
        self::assertStringContainsString('name="order_field_options[' . $fields[1]['id'] . '][' . $fields[1]['options'][0]['id'] . '][label]"', $page['body']);

        $refused = self::$server->request('POST', '/api/admin/update-product.php', $session, $this->fields($product, $csrf, [
            'order_fields_present' => '1', 'order_fields_enabled' => '1',
            'order_fields' => ['new0' => ['present' => '1', 'type' => 'select', 'label' => '', 'help' => '', 'max_length' => '']],
        ]), [], self::JSON);
        self::assertSame(422, $refused['status']);
        $errors = json_decode($refused['body'], true)['errors'];
        self::assertArrayHasKey('order_fields[new0][label]', $errors);
        self::assertArrayHasKey('order_fields[new0][options]', $errors);
        self::assertCount(2, (new OrderFieldRepository())->fieldsForProduct($product), 'a refused save changes nothing');
    }

    public function testTheProductPageAsksTheQuestionsAboveTheButton(): void
    {
        $product = $this->fixture->product('ZZ Http Bord');
        $ids = $this->ask($product);

        $page = self::$server->request('GET', '/product.php?id=' . $product);
        self::assertSame(200, $page['status']);
        self::assertMatchesRegularExpression('/data-product-order-fields.*data-product-add-row/s', $page['body'], 'above the quantity and the button');
        self::assertStringContainsString('data-order-field="' . $ids['name'] . '" data-order-field-type="text" data-order-field-required', $page['body']);
        self::assertStringContainsString('maxlength="12"', $page['body']);
        self::assertStringContainsString('Naam op het bord', $page['body']);

        // The page asks in its own language (this server has no language
        // routes, so the words are read the way product.php reads them).
        self::assertSame('Name on the sign', (new \App\Service\OrderFields\OrderFields())->questions($product, 'en')[0]['label']);

        (new ProductRepository())->updatePurchaseMode($product, PurchaseMode::INQUIRY);
        $inquiry = self::$server->request('GET', '/product.php?id=' . $product);
        self::assertStringNotContainsString('data-product-order-fields', $inquiry['body'], 'no questions without a cart');
    }

    public function testTheCartCheckSaysWhenALinesAnswersNoLongerFit(): void
    {
        $product = $this->fixture->product('ZZ Http Controle');
        $ids = $this->ask($product);

        $response = self::$server->postJson('/api/cart-check.php?lang=nl', ['items' => [
            ['id' => $product, 'qty' => 1, 'order_fields' => [(string) $ids['name'] => 'Luna', (string) $ids['wood'] => (string) $ids['oak']]],
            ['id' => $product, 'qty' => 1, 'order_fields' => [(string) $ids['wood'] => (string) $ids['oak']]],
        ]]);

        $lines = json_decode($response['body'], true)['lines'];
        self::assertSame('ok', $lines[0]['status']);
        self::assertSame('order_fields', $lines[1]['status']);
        self::assertSame('Vul "Naam op het bord" in.', $lines[1]['message']);
    }

    public function testTheCheckoutRefusesAMissingOrForgedAnswerBeforeAnythingIsStored(): void
    {
        $product = $this->fixture->product('ZZ Http Kassa');
        $ids = $this->ask($product);

        [$status, $body] = $this->checkout([['id' => $product, 'qty' => 1, 'order_fields' => [(string) $ids['wood'] => (string) $ids['oak']]]], 'nl');
        self::assertSame(422, $status);
        self::assertSame('Vul "Naam op het bord" in.', $body['error']);

        [$status, $body] = $this->checkout([['id' => $product, 'qty' => 1, 'order_fields' => [(string) $ids['name'] => 'Luna', (string) $ids['wood'] => '999999']]], 'en');
        self::assertSame(422, $status);
        self::assertSame('The answer for "Wood" cannot be chosen. Please reload the page.', $body['error']);

        self::assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM orders o JOIN customers c ON c.id = o.customer_id WHERE c.email = 'order-fields@example.invalid'")->fetchColumn());
    }

    public function testTheOrderScreenShowsTheAnswersUnderTheirLine(): void
    {
        [$session] = $this->accounts->signIn(['orders.view']);
        $product = $this->fixture->product('ZZ Http Bestelling');
        $order = $this->fixture->order([['product_id' => $product, 'quantity' => 1]], 'tr_zzfields' . bin2hex(random_bytes(3)), 'paid');
        $itemId = (int) Database::connection()->query('SELECT id FROM order_items WHERE order_id = ' . $order)->fetchColumn();
        (new OrderItemFieldRepository())->create($itemId, [
            ['field_id' => null, 'field_type' => 'text', 'label' => 'Naam op het bord', 'value' => 'Luna <b>', 'option_id' => null],
        ]);

        $page = self::$server->request('GET', '/admin/order.php?id=' . $order, $session);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<dt>Naam op het bord</dt>', $page['body']);
        self::assertStringContainsString('<dd>Luna &lt;b&gt;</dd>', $page['body']);
    }

    /* ------------------------------------------------------------------ */

    /**
     * A required short text (max 12) and a required radio, in Dutch and in
     * English.
     *
     * @return array{name: int, wood: int, oak: int}
     */
    private function ask(int $product): array
    {
        $repository = new OrderFieldRepository();
        $repository->setEnabled($product, true);
        $name = $repository->createField($product, 'text', true, 12, 0);
        $wood = $repository->createField($product, 'radio', true, null, 1);
        $oak = $repository->createOption($wood, 0);
        $walnut = $repository->createOption($wood, 1);

        ShopLocalization::saveOrderField($name, 'nl', [ShopLocalization::LABEL => 'Naam op het bord']);
        ShopLocalization::saveOrderField($name, 'en', [ShopLocalization::LABEL => 'Name on the sign']);
        ShopLocalization::saveOrderField($wood, 'nl', [ShopLocalization::LABEL => 'Houtsoort']);
        ShopLocalization::saveOrderField($wood, 'en', [ShopLocalization::LABEL => 'Wood']);
        ShopLocalization::saveOrderFieldOption($oak, 'nl', 'Eiken');
        ShopLocalization::saveOrderFieldOption($walnut, 'nl', 'Noten');
        ShopLocalization::clearCache();

        return ['name' => $name, 'wood' => $wood, 'oak' => $oak];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function checkout(array $items, string $language): array
    {
        $response = self::$server->postJson('/api/checkout.php', [
            'voornaam' => 'Test', 'achternaam' => 'Klant', 'email' => 'order-fields@example.invalid', 'telefoon' => '',
            'verzendmethode' => 'verzenden', 'land' => 'BE', 'postcode' => '1000', 'huisnummer' => '1',
            'straat' => 'Grote Markt', 'plaats' => 'Brussel', 'betaalmethode' => 'ideal',
            'terms_accepted' => true, 'facturatie_zelfde' => true, 'language' => $language,
            'items' => $items,
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
            'csrf_token' => $csrf, 'id' => (string) $productId, 'language_code' => 'nl', 'name' => 'ZZ Http Vragen',
            'description' => '', 'price' => '10', 'active' => '1', 'in_shop' => '1',
            'shipping_profile' => 'letter', 'shipping_weight_grams' => '20',
        ];
    }
}
