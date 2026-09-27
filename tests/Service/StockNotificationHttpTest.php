<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ShopModule;
use App\Service\ContactRateLimiter;
use App\Service\ShopLocalizedSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\ShopStockFixture;

/**
 * Back in stock over real HTTP (Shop Product & Ordering 2.0), against PHP's
 * built-in server with the Shop on and a mail server that refuses every
 * connection — so nothing is ever delivered, and a failed mail is exactly
 * what a test can see:
 *
 *   - api/stock-notification.php stores a request for a sold-out unit and
 *     answers a known address exactly like a new one (no enumeration), says
 *     "available" for a unit that is, refuses a non-address, stores nothing
 *     for a filled honeypot, and stops a visitor who asks too often;
 *   - the product page offers the form in its sold-out block;
 *   - the owner edits the mail per website language on Shop-instellingen →
 *     E-mails, and an emptied field falls back on the standard text;
 *   - a save in the product editor that brings a unit back tries the mail at
 *     once; a failure leaves the request active, and "Wachtende meldingen nu
 *     versturen" tries it again.
 */
final class StockNotificationHttpTest extends TestCase
{
    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;
    private ShopStockFixture $fixture;

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start([
            'MODULE_SHOP_ENABLED' => 'true',
            // Every mail fails at the door: nothing leaves this test.
            'MAIL_HOST' => '127.0.0.1',
            'MAIL_PORT' => '9',
            'MAIL_FROM_ADDRESS' => 'shop@example.invalid',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        $this->accounts = new AdminTestSession();
        $this->fixture = new ShopStockFixture();

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        $this->forgetRateLimit();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach (array_keys(ShopLocalizedSettings::KEYS) as $key) {
            $db->prepare('DELETE FROM site_setting_translations WHERE setting_key = :key')->execute(['key' => $key]);
        }
        ShopLocalizedSettings::clearCache();
        $this->forgetRateLimit();
        $this->fixture->cleanUp();
        $this->accounts->forget();
    }

    public function testAKnownAddressGetsTheSameAnswerAsANewOne(): void
    {
        $product = $this->fixture->product('ZZ Http Melding', 0);

        $first = self::$server->postJson('/api/stock-notification.php', ['product_id' => $product, 'email' => 'Http@Example.com', 'language' => 'nl']);
        $second = self::$server->postJson('/api/stock-notification.php', ['product_id' => $product, 'email' => 'http@example.com', 'language' => 'en']);

        self::assertSame(200, $first['status']);
        self::assertSame($first['status'], $second['status']);
        self::assertSame($first['body'], $second['body'], 'nothing says the address was already known');
        self::assertSame(['ok' => true, 'available' => false], json_decode($first['body'], true));
        self::assertSame(1, $this->requestCount($product));
    }

    public function testAnAvailableUnitANonAddressAndAHoneypotStoreNothing(): void
    {
        $inStock = $this->fixture->product('ZZ Http Voorraad', 2);
        $soldOut = $this->fixture->product('ZZ Http Leeg', 0);

        $available = self::$server->postJson('/api/stock-notification.php', ['product_id' => $inStock, 'email' => 'a@example.com']);
        self::assertSame(['ok' => true, 'available' => true], json_decode($available['body'], true));

        $invalid = self::$server->postJson('/api/stock-notification.php', ['product_id' => $soldOut, 'email' => 'geen adres', 'language' => 'nl']);
        self::assertSame(422, $invalid['status']);
        self::assertSame('Vul een geldig e-mailadres in.', json_decode($invalid['body'], true)['error']);

        $invalidEn = self::$server->postJson('/api/stock-notification.php', ['product_id' => $soldOut, 'email' => 'no address', 'language' => 'en']);
        self::assertSame('Please enter a valid email address.', json_decode($invalidEn['body'], true)['error']);

        $bot = self::$server->postJson('/api/stock-notification.php', ['product_id' => $soldOut, 'email' => 'bot@example.com', 'hp-note' => 'spam']);
        self::assertSame(['ok' => true, 'available' => false], json_decode($bot['body'], true), 'a script gets the ordinary answer');

        $forged = self::$server->postJson('/api/stock-notification.php', ['product_id' => 'x', 'email' => 'c@example.com']);
        self::assertSame(422, $forged['status']);

        self::assertSame(0, $this->requestCount($inStock));
        self::assertSame(0, $this->requestCount($soldOut));
        self::assertSame(405, self::$server->request('GET', '/api/stock-notification.php')['status']);
    }

    public function testTheProductPageOffersTheFormInItsSoldOutBlock(): void
    {
        $product = $this->fixture->product('ZZ Http Pagina', 0);
        $page = self::$server->request('GET', '/product.php?id=' . $product);

        self::assertSame(200, $page['status']);
        self::assertMatchesRegularExpression('/data-product-sold-out hidden>.*?<form class="product-detail__notify" data-product-notify/s', $page['body']);
        self::assertStringContainsString('Mail mij als dit weer beschikbaar is', $page['body']);
        self::assertStringContainsString('name="hp-note"', $page['body']);
    }

    public function testTheOwnerEditsTheMailPerLanguage(): void
    {
        [$session, $csrf] = $this->accounts->signIn(['settings.manage']);

        $page = self::$server->request('GET', '/admin/shop-settings.php', $session);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('action="/api/admin/update-stock-notification-mail.php"', $page['body']);
        self::assertStringContainsString('{{product_url}}', $page['body']);

        $saved = self::$server->request('POST', '/api/admin/update-stock-notification-mail.php', $session, [
            'csrf_token' => $csrf, 'language_code' => 'en',
            ShopLocalizedSettings::STOCK_SUBJECT => 'Back: {{product_name}}',
            ShopLocalizedSettings::STOCK_BODY => "Line one\r\n\r\nLine two",
        ]);
        self::assertSame(302, $saved['status']);

        ShopLocalizedSettings::clearCache();
        self::assertSame('Back: {{product_name}}', ShopLocalizedSettings::raw(ShopLocalizedSettings::STOCK_SUBJECT, 'en'));
        self::assertSame("Line one\n\nLine two", ShopLocalizedSettings::raw(ShopLocalizedSettings::STOCK_BODY, 'en'));
        self::assertSame('', ShopLocalizedSettings::raw(ShopLocalizedSettings::STOCK_SUBJECT, 'nl'), 'another language stays as it is');

        $tooLong = self::$server->request('POST', '/api/admin/update-stock-notification-mail.php', $session, [
            'csrf_token' => $csrf, 'language_code' => 'en',
            ShopLocalizedSettings::STOCK_SUBJECT => str_repeat('x', 300),
            ShopLocalizedSettings::STOCK_BODY => '',
        ]);
        self::assertSame(302, $tooLong['status']);
        ShopLocalizedSettings::clearCache();
        self::assertSame('Back: {{product_name}}', ShopLocalizedSettings::raw(ShopLocalizedSettings::STOCK_SUBJECT, 'en'), 'a refused save writes nothing');

        self::$server->request('POST', '/api/admin/update-stock-notification-mail.php', $session, [
            'csrf_token' => $csrf, 'language_code' => 'en',
            ShopLocalizedSettings::STOCK_SUBJECT => '', ShopLocalizedSettings::STOCK_BODY => '',
        ]);
        ShopLocalizedSettings::clearCache();
        self::assertSame('{{product_name}} is back in stock', ShopLocalizedSettings::value(ShopLocalizedSettings::STOCK_SUBJECT, 'en'), 'empty is the standard text again');

        self::assertSame(403, self::$server->request('POST', '/api/admin/update-stock-notification-mail.php', $session, ['csrf_token' => 'wrong'])['status']);
    }

    public function testARestockInTheEditorTriesTheMailAndAFailureStaysDueForARetry(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PRODUCTS_MANAGE, 'settings.manage']);
        $product = $this->fixture->product('ZZ Http Restock', 0);
        self::$server->postJson('/api/stock-notification.php', ['product_id' => $product, 'email' => 'restock@example.com', 'language' => 'nl']);

        $response = self::$server->request('POST', '/api/admin/update-product.php', $session, [
            'csrf_token' => $csrf, 'id' => (string) $product, 'language_code' => 'nl', 'name' => 'ZZ Http Restock',
            'description' => '', 'price' => '10', 'active' => '1', 'in_shop' => '1',
            'shipping_profile' => 'letter', 'shipping_weight_grams' => '20',
            'inventory_present' => '1', 'track_stock' => '1', 'stock' => '2', 'stock_seen' => '0',
        ], [], ['Accept: application/json']);
        self::assertSame(200, $response['status'], 'a mail that fails never fails the save');
        self::assertSame(2, $this->fixture->productStock($product));

        $row = $this->row($product);
        self::assertSame('active', $row['status'], 'the mail server refused: not sent');
        self::assertSame(1, (int) $row['attempts'], 'but it was tried at once');

        $page = self::$server->request('GET', '/admin/shop-settings.php', $session);
        self::assertStringContainsString('1 aanvragen wachten op een melding; 1 daarvan kunnen nu verstuurd worden', $page['body']);

        $retry = self::$server->request('POST', '/api/admin/send-stock-notifications.php', $session, ['csrf_token' => $csrf]);
        self::assertSame(302, $retry['status']);
        self::assertSame(2, (int) $this->row($product)['attempts'], 'tried again, still active');
        self::assertSame('active', $this->row($product)['status']);
    }

    /** Last on purpose: it spends this visitor's budget (tearDown gives it back). */
    public function testAVisitorWhoAsksTooOftenIsStopped(): void
    {
        $product = $this->fixture->product('ZZ Http Limiet', 0);

        $statuses = [];
        for ($i = 0; $i < 21; $i++) {
            $statuses[] = self::$server->postJson('/api/stock-notification.php', ['product_id' => $product, 'email' => 'limiet' . $i . '@example.com'])['status'];
        }

        self::assertSame(array_fill(0, 20, 200), array_slice($statuses, 0, 20));
        self::assertSame(429, $statuses[20]);
        self::assertSame(20, $this->requestCount($product));
    }

    private function requestCount(int $productId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM stock_notifications WHERE product_id = :id');
        $stmt->execute(['id' => $productId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function row(int $productId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM stock_notifications WHERE product_id = :id ORDER BY id LIMIT 1');
        $stmt->execute(['id' => $productId]);

        return (array) $stmt->fetch();
    }

    private function forgetRateLimit(): void
    {
        Database::connection()->prepare('DELETE FROM contact_rate_limit_hits WHERE ip_hash = :hash')
            ->execute(['hash' => hash('sha256', '127.0.0.1|' . ContactRateLimiter::STOCK_NOTIFICATION_SALT)]);
    }
}
