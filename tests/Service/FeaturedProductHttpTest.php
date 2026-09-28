<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\FeaturedProductRepository;
use App\Repository\OrderFieldRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\ProductRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\ContactRateLimiter;
use App\Service\FeaturedProductContent;
use App\Service\PageContent;
use App\Service\SectionRegistry;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;
use Tests\Support\ShopStockFixture;

/**
 * Uitgelicht product through the real editor, endpoint and page, over PHP's
 * built-in server — once with the Shop on, once with it off:
 *
 *   - the four guards and the `<page>:<key>` gate; with the Shop off the
 *     editor is closed, the endpoint refuses before reading anything, the
 *     page skips the block and the row is untouched;
 *   - a block saves without a product; a chosen product, the settings and
 *     the words of one language are one save, and the other language stays;
 *   - an unknown product, a word off a closed list and a switch that is not
 *     "1" are refused at their field and nothing is stored;
 *   - an inactive product can be chosen, and the editor says the block
 *     cannot show it; the editor lists the catalogue and has no free id field;
 *   - what a visitor's browser sends from the block is judged by the Shop's
 *     own endpoints: api/cart-check.php refuses too many, sold out, "op
 *     aanvraag", a missing answer and a forged variant, and the back-in-stock
 *     form stores one request for exactly the unit on show;
 *   - the page carries exactly the payload GET /api/product.php answers.
 *
 * The page, its blocks, the products and the accounts are this test's own
 * and are removed in tearDown(). Without a server the test skips itself.
 */
final class FeaturedProductHttpTest extends TestCase
{
    private const KEY = 'zz-featured-product-http';

    private const ENDPOINT = '/api/admin/update-featured-product.php';

    private static ?BuiltInServer $server = null;

    private static ?BuiltInServer $shopOff = null;

    private AdminTestSession $accounts;

    private ShopStockFixture $shop;

    public static function setUpBeforeClass(): void
    {
        $mail = ['MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '9', 'MAIL_FROM_ADDRESS' => 'shop@example.invalid'];
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true'] + $mail);
        self::$shopOff = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'false', 'MODULE_PERSONALIZATION_ENABLED' => 'false'] + $mail);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$shopOff?->stop();
        self::$server = null;
        self::$shopOff = null;
    }

    protected function setUp(): void
    {
        $this->accounts = new AdminTestSession();
        $this->shop = new ShopStockFixture();

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        $this->removePage();
        PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'Uitgelicht-http');
        $this->forgetRateLimit();
    }

    protected function tearDown(): void
    {
        $this->removePage();
        $this->shop->cleanUp();
        $this->accounts->forget();
        $this->forgetRateLimit();
        FeaturedProductContent::clearCache();
        BlockLocalization::clearCache();
        ShopLocalization::clearCache();
    }

    // --------------------------------------------------------------- guards

    public function testTheFourGuardsAndTheSectionGate(): void
    {
        [$section] = $this->place();
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        self::assertSame(401, self::$server->request('POST', self::ENDPOINT, null, ['section' => $section])['status'], 'not signed in');

        [$other, $otherCsrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        self::assertSame(403, self::$server->request('POST', self::ENDPOINT, $other, ['section' => $section, 'csrf_token' => $otherCsrf])['status'], 'no pages.manage');

        self::assertSame(405, self::$server->request('GET', self::ENDPOINT . '?section=' . urlencode($section), $session)['status']);
        self::assertSame(403, self::$server->request('POST', self::ENDPOINT, $session, ['section' => $section, 'csrf_token' => 'wrong'])['status']);

        foreach ([self::KEY . ':custom-nothere', 'no-such-page:custom-x', self::KEY, ''] as $unknown) {
            self::assertSame(404, self::$server->request('POST', self::ENDPOINT, $session, ['section' => $unknown, 'csrf_token' => $csrf, 'language_code' => 'nl'])['status'], $unknown);
        }
        self::assertSame(404, self::$server->request('GET', '/admin/featured-product.php?section=' . urlencode(self::KEY . ':custom-nothere'), $session)['status']);
        self::assertSame(200, self::$server->request('GET', '/admin/featured-product.php?section=' . urlencode($section), $session)['status']);
    }

    public function testWithTheShopOffTheEditorIsClosedTheBlockSkippedAndTheRowKept(): void
    {
        if (self::$shopOff === null || !self::$shopOff->answers()) {
            $this->markTestSkipped('no second server with the Shop off');
        }

        $product = $this->shop->product('ZZ Uitgelicht uit', null, 11.00);
        [$section, $id] = $this->place(['product_id' => $product]);
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE], true);
        $before = (new FeaturedProductRepository())->findById($id);

        self::assertSame(403, self::$shopOff->request('GET', '/admin/featured-product.php?section=' . urlencode($section), $session)['status'], 'the CMS\'s no-access page');
        $refused = self::$shopOff->request('POST', self::ENDPOINT, $session, $this->fields($section, $csrf, ['product_id' => '', 'ordering' => 'view']));
        self::assertSame(404, $refused['status'], 'App\\Module\\ModuleGuard refuses before anything is read');

        $page = self::$shopOff->request('GET', '/pagina.php?slug=' . self::KEY);
        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('featured-product-section', $page['body']);
        self::assertStringNotContainsString('data-product-detail', $page['body']);
        self::assertStringNotContainsString('ZZ Uitgelicht uit', $page['body']);
        self::assertStringNotContainsString('Fatal error', $page['body']);

        self::assertSame($before, (new FeaturedProductRepository())->findById($id), 'nothing written, nothing lost');
        self::assertStringContainsString('ZZ Uitgelicht uit', self::$server->request('GET', '/pagina.php?slug=' . self::KEY)['body'], 'the Shop back on: the block is back as it was');
    }

    // -------------------------------------------------------------- saving

    public function testABlockIsSavedWithoutAProductAndShowsNothing(): void
    {
        [$section, $id] = $this->place();
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $response = self::$server->request('POST', self::ENDPOINT, $session, $this->fields($section, $csrf, ['product_id' => '', 'intro' => 'Straks komt hier een product']));

        self::assertSame(302, $response['status']);
        self::assertStringEndsWith('&saved=1', $response['location']);
        self::assertNull((new FeaturedProductRepository())->findById($id)['product_id']);
        self::assertSame('Straks komt hier een product', BlockLocalization::raw('featured_products', $id, 'intro', 'nl'));

        $page = self::$server->request('GET', '/pagina.php?slug=' . self::KEY)['body'];
        self::assertStringNotContainsString('featured-product-section', $page, 'no product, no block');
        self::assertStringNotContainsString('data-product-payload', $page);
        self::assertStringNotContainsString('Straks komt hier een product', $page);

        $editor = self::$server->request('GET', '/admin/featured-product.php?section=' . urlencode($section), $session)['body'];
        self::assertMatchesRegularExpression('/data-featured-product-none-note>/', $editor, 'the editor says there is none yet, and not hidden');
    }

    public function testAProductItsSettingsAndOneLanguagesWordsAreOneSave(): void
    {
        $product = $this->shop->product('ZZ Wolvenpenning', null, 24.50);
        ShopLocalization::saveProduct($product, 'en', [ShopLocalization::NAME => 'ZZ Wolf token', ShopLocalization::DESCRIPTION => '', ShopLocalization::META_TITLE => '', ShopLocalization::META_DESCRIPTION => '']);
        [$section, $id] = $this->place();
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $response = self::$server->request('POST', self::ENDPOINT, $session, $this->fields($section, $csrf, [
            'product_id' => (string) $product,
            'image_mode' => 'main',
            'image_position' => 'right',
            'image_size' => 'small',
            'content_align' => 'center',
            'ordering' => 'view',
            'show_specifications' => '1',
            'intro' => 'Een van onze populairste wolvenpenningen.',
            'link_label' => 'Meer informatie',
        ], ['show_price']));
        self::assertSame(302, $response['status']);

        $row = (new FeaturedProductRepository())->findById($id);
        self::assertSame($product, (int) $row['product_id']);
        self::assertSame(
            ['main', 'right', 'small', 'center', 'view', 1, 0, 1, 1, 1],
            [$row['image_mode'], $row['image_position'], $row['image_size'], $row['content_align'], $row['ordering'], (int) $row['show_name'], (int) $row['show_price'], (int) $row['show_description'], (int) $row['show_specifications'], (int) $row['show_product_link']]
        );

        // The editor posts the whole form again in the other language: the
        // same settings, the English words.
        $english = self::$server->request('POST', self::ENDPOINT, $session, $this->fields($section, $csrf, [
            'language_code' => 'en',
            'product_id' => (string) $product,
            'image_mode' => 'main',
            'image_position' => 'right',
            'image_size' => 'small',
            'content_align' => 'center',
            'ordering' => 'view',
            'show_specifications' => '1',
            'intro' => 'One of our most popular wolf tokens.',
            'link_label' => '',
        ], ['show_price']));
        self::assertSame(302, $english['status']);
        self::assertSame('Een van onze populairste wolvenpenningen.', BlockLocalization::raw('featured_products', $id, 'intro', 'nl'), 'the other language stays');
        self::assertSame('Meer informatie', BlockLocalization::raw('featured_products', $id, 'link_label', 'nl'));
        self::assertSame('One of our most popular wolf tokens.', BlockLocalization::raw('featured_products', $id, 'intro', 'en'));

        $page = self::$server->request('GET', '/pagina.php?slug=' . self::KEY)['body'];
        self::assertStringContainsString('>ZZ Wolvenpenning</h2>', $page);
        self::assertStringContainsString('Een van onze populairste wolvenpenningen.', $page);
        self::assertStringContainsString('>Meer informatie<span class="visually-hidden">: ', $page);
        self::assertStringContainsString('featured-product--image-right featured-product--image-small featured-product--align-center', $page);
        self::assertStringContainsString('data-gallery-main-only', $page);
        self::assertStringNotContainsString('data-product-add-to-cart', $page, 'view only');
        self::assertStringContainsString('/assets/css/shop/featured-product.css', $page, 'the block asks for its own stylesheet');
        self::assertStringContainsString('/assets/js/shop/shop.js', $page);
        self::assertStringContainsString('/assets/js/shop/product-gallery.js', $page);
    }

    public function testAnUnknownProductAWordOffTheListAndAForgedSwitchAreRefused(): void
    {
        $product = $this->shop->product('ZZ Blijft', null, 5.00);
        [$section, $id] = $this->place(['product_id' => $product]);
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $before = (new FeaturedProductRepository())->findById($id);

        $cases = [
            'product_id' => ['product_id' => '999999999'],
            'image_size' => ['image_size' => 'huge'],
            'ordering' => ['ordering' => 'buy-now'],
            'image_position' => ['image_position' => 'top'],
            'show_price' => ['show_price' => 'yes'],
        ];
        foreach ($cases as $field => $posted) {
            $response = self::$server->request('POST', self::ENDPOINT, $session, $this->fields($section, $csrf, $posted + ['product_id' => (string) $product]));
            self::assertSame(302, $response['status'], $field);
            self::assertStringNotContainsString('saved=1', $response['location'], $field);
            self::assertArrayHasKey($field, (array) $this->accounts->read($session, 'admin_featured_product_field_errors'), $field . ' is refused at its field');
            self::assertSame($before, (new FeaturedProductRepository())->findById($id), $field . ': nothing stored');
        }

        $letters = self::$server->request('POST', self::ENDPOINT, $session, $this->fields($section, $csrf, ['product_id' => 'abc']));
        self::assertStringNotContainsString('saved=1', $letters['location']);
        self::assertSame($before, (new FeaturedProductRepository())->findById($id));
    }

    public function testAnInactiveProductMayBeChosenAndTheEditorSaysTheBlockCannotShowIt(): void
    {
        $product = $this->shop->product('ZZ Inactief gekozen', null, 7.00);
        (new ProductRepository())->setActive($product, false);
        [$section, $id] = $this->place();
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        self::assertSame(302, self::$server->request('POST', self::ENDPOINT, $session, $this->fields($section, $csrf, ['product_id' => (string) $product]))['status']);
        self::assertSame($product, (int) (new FeaturedProductRepository())->findById($id)['product_id']);

        $editor = self::$server->request('GET', '/admin/featured-product.php?section=' . urlencode($section), $session)['body'];
        self::assertMatchesRegularExpression('/data-featured-product-unavailable>/', $editor, 'said, and not hidden');
        self::assertStringContainsString('Het gekozen product is niet beschikbaar', $editor);

        self::assertStringNotContainsString('ZZ Inactief gekozen', self::$server->request('GET', '/pagina.php?slug=' . self::KEY)['body'], 'never on the page');
    }

    public function testTheEditorListsTheCatalogueAsChoicesAndHasNoFreeIdField(): void
    {
        $first = $this->shop->product('ZZ Keuze Een', null, 5.00);
        $second = $this->shop->product('ZZ Keuze Twee', null, 6.00);
        [$section] = $this->place(['product_id' => $second]);
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $editor = self::$server->request('GET', '/admin/featured-product.php?section=' . urlencode($section), $session)['body'];

        self::assertMatchesRegularExpression('/<input type="radio" name="product_id" value="' . $first . '">/', $editor);
        self::assertMatchesRegularExpression('/<input type="radio" name="product_id" value="' . $second . '" checked>/', $editor, 'the stored choice is checked');
        self::assertMatchesRegularExpression('/<input type="radio" name="product_id" value="" *>/', $editor, '"Geen product" is a choice');
        self::assertDoesNotMatchRegularExpression('/<input type="(?:text|number)"[^>]*name="product_id"/', $editor, 'no free product id');
        self::assertStringContainsString('data-featured-product-search', $editor);
        foreach (['Product', 'Inhoud', 'Afbeeldingen', 'Bestellen', 'Productknop', 'Uitlijning'] as $card) {
            self::assertStringContainsString('<h2 class="admin-collapse__title">' . $card . '</h2>', $editor, $card);
        }
        self::assertStringContainsString('data-admin-collapse-group="featured-product"', $editor);
    }

    // ------------------------------------------ what a visitor's browser sends

    public function testTheShopDecidesEveryLineTheBlockSends(): void
    {
        $two = $this->shop->product('ZZ Twee over', 2, 12.00);
        $none = $this->shop->product('ZZ Niets over', 0, 8.00);
        $inquiry = $this->shop->product('ZZ Op aanvraag', null, 99.00);
        (new ProductRepository())->updatePurchaseMode($inquiry, 'inquiry');
        ['product' => $plate, 'variants' => $variants] = $this->shop->variantProduct('ZZ Plaat', ['A' => 0, 'B' => 5]);
        ['variants' => $foreign] = $this->shop->variantProduct('ZZ Ander', ['X' => 3]);
        $asks = $this->shop->product('ZZ Vraagt', null, 19.95);
        $fields = new OrderFieldRepository();
        $fields->setEnabled($asks, true);
        $name = $fields->createField($asks, 'text', true, 20, 0);
        ShopLocalization::saveOrderField($name, 'nl', [ShopLocalization::LABEL => 'Naam op het bord']);

        $check = function (array $item): array {
            $response = self::$server->postJson('/api/cart-check.php', ['items' => [$item]]);
            self::assertSame(200, $response['status']);

            return (array) json_decode($response['body'], true)['lines'][0];
        };

        self::assertSame(['status' => 'ok', 'available' => 2], $check(['id' => $two, 'qty' => 2]));
        self::assertSame(['status' => 'insufficient', 'available' => 2], $check(['id' => $two, 'qty' => 3]), 'three when two are left');
        self::assertSame('sold_out', $check(['id' => $none, 'qty' => 1])['status']);
        self::assertSame('inquiry', $check(['id' => $inquiry, 'qty' => 1])['status'], 'never through the cart');
        self::assertSame('sold_out', $check(['id' => $plate, 'variant_id' => $variants['A'], 'qty' => 1])['status']);
        self::assertSame(['status' => 'ok', 'available' => 5], $check(['id' => $plate, 'variant_id' => $variants['B'], 'qty' => 5]));
        self::assertSame('unavailable', $check(['id' => $plate, 'variant_id' => $foreign['X'], 'qty' => 1])['status'], 'a forged variant of another product');
        self::assertSame('order_fields', $check(['id' => $asks, 'qty' => 1])['status'], 'a required answer missing');
        self::assertSame('ok', $check(['id' => $asks, 'qty' => 3, 'order_fields' => [(string) $name => 'Luna']])['status'], 'three with the answer');
    }

    public function testTheBackInStockFormStoresOneRequestForTheUnitOnShow(): void
    {
        ['product' => $plate, 'variants' => $variants] = $this->shop->variantProduct('ZZ Plaat melding', ['A' => 0, 'B' => 5]);
        $this->place(['product_id' => $plate]);

        $page = self::$server->request('GET', '/pagina.php?slug=' . self::KEY)['body'];
        self::assertMatchesRegularExpression('/data-product-sold-out hidden>.*?<form class="product-detail__notify" data-product-notify/s', $page, 'the product page\'s own form');

        $request = ['product_id' => $plate, 'variant_id' => $variants['A'], 'email' => 'block-restock@example.com', 'language' => 'nl'];
        $first = self::$server->postJson('/api/stock-notification.php', $request);
        $again = self::$server->postJson('/api/stock-notification.php', $request);

        self::assertSame(['ok' => true, 'available' => false], json_decode($first['body'], true));
        self::assertSame($first['body'], $again['body']);

        $stmt = Database::connection()->prepare('SELECT variant_id FROM stock_notifications WHERE product_id = :id');
        $stmt->execute(['id' => $plate]);
        self::assertSame([$variants['A']], array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)), 'one request, for exactly the variant on show');
    }

    /**
     * "Prijs tonen" off on a block that sells: the page a visitor gets holds
     * no price anywhere, and what the cart line asks when the visitor adds —
     * GET /api/product.php, and api/cart-check.php before it — still answers,
     * with the server's current price for the unit chosen.
     */
    public function testWithThePriceOffThePageHasNoPriceAndTheCartAsksTheServer(): void
    {
        ['product' => $plate, 'variants' => $variants] = $this->shop->variantProduct('ZZ Prijs verborgen', ['A' => 2, 'B' => 5], true, 73.19);
        Database::connection()->prepare('UPDATE product_variants SET price = 81.37 WHERE id = :id')->execute(['id' => $variants['B']]);
        $this->place(['product_id' => $plate, 'show_price' => false]);

        $page = self::$server->request('GET', '/pagina.php?slug=' . self::KEY);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('data-product-add-to-cart', $page['body'], 'the block still sells');
        foreach (['73.19', '73,19', '81.37', '81,37'] as $amount) {
            self::assertStringNotContainsString($amount, $page['body'], $amount);
        }

        $api = json_decode(self::$server->request('GET', '/api/product.php?id=' . $plate . '&lang=nl')['body'], true)['data'];
        self::assertSame('73.19', $api['price'], 'the product page\'s own endpoint answers the price');
        self::assertSame('81.37', array_column($api['variants'], 'price', 'id')[$variants['B']]);

        $check = self::$server->postJson('/api/cart-check.php', ['items' => [['id' => $plate, 'variant_id' => $variants['B'], 'qty' => 3]]]);
        self::assertSame(['status' => 'ok', 'available' => 5], json_decode($check['body'], true)['lines'][0], 'three of variant B may be added');
    }

    public function testThePageCarriesExactlyThePayloadTheApiAnswers(): void
    {
        ['product' => $plate] = $this->shop->variantProduct('ZZ Zelfde payload', ['A' => 0, 'B' => 5]);
        $this->place(['product_id' => $plate]);

        $page = self::$server->request('GET', '/pagina.php?slug=' . self::KEY)['body'];
        self::assertSame(1, preg_match('#<script type="application/json" data-product-payload>(.*?)</script>#s', $page, $match));

        $api = json_decode(self::$server->request('GET', '/api/product.php?id=' . $plate . '&lang=nl')['body'], true)['data'];
        self::assertSame($api, json_decode($match[1], true));
    }

    // ------------------------------------------------------------- helpers

    /**
     * Places a block on the test page, as the page builder does.
     *
     * @param array<string, mixed> $settings
     * @return array{0: string, 1: int} the `<page>:<key>` parameter and the row id
     */
    private function place(array $settings = []): array
    {
        [$id, $key] = SectionRegistry::create('featured_product', self::KEY);
        $page = (new PageRepository())->findByContentKey(self::KEY);
        (new PageSectionRepository())->create((int) $page['id'], self::KEY, 'featured_product', $key, (int) $id);
        if ($settings !== []) {
            (new FeaturedProductRepository())->update((int) $id, $settings);
        }
        FeaturedProductContent::clearCache();

        return [self::KEY . ':' . $key, (int) $id];
    }

    /**
     * A complete form as the editor posts it: every switch on that starts on,
     * every choice at its default, in Dutch — then $overrides, and minus the
     * switches in $off (an unticked checkbox sends nothing).
     *
     * @param array<string, string> $overrides
     * @param list<string> $off
     * @return array<string, string>
     */
    private function fields(string $section, string $csrf, array $overrides = [], array $off = []): array
    {
        $fields = $overrides + [
            'section' => $section,
            'csrf_token' => $csrf,
            'language_code' => 'nl',
            'product_id' => '',
            'image_mode' => 'gallery',
            'image_position' => 'left',
            'image_size' => 'medium',
            'content_align' => 'left',
            'ordering' => 'direct',
            'show_name' => '1',
            'show_price' => '1',
            'show_description' => '1',
            'show_product_link' => '1',
            'intro' => '',
            'link_label' => '',
        ];

        foreach ($off as $switch) {
            unset($fields[$switch]);
        }

        return $fields;
    }

    private function removePage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::KEY);
        if ($page === null) {
            return;
        }

        $sections = new PageSectionRepository();
        foreach ($sections->findForPage((int) $page['id']) as $row) {
            if (SectionRegistry::exists((string) $row['section_type'])) {
                SectionRegistry::delete($row, $sections);
            }
        }
        Database::connection()->prepare('DELETE FROM featured_products WHERE page_slug = :slug')->execute(['slug' => self::KEY]);
        Database::connection()->prepare('DELETE FROM pages WHERE id = :id')->execute(['id' => (int) $page['id']]);
    }

    private function forgetRateLimit(): void
    {
        Database::connection()->prepare('DELETE FROM contact_rate_limit_hits WHERE ip_hash = :hash')
            ->execute(['hash' => hash('sha256', '127.0.0.1|' . ContactRateLimiter::STOCK_NOTIFICATION_SALT)]);
    }
}
