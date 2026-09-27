<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\DashboardRepository;
use App\Repository\OrderRepository;
use App\Repository\SiteSettingRepository;
use App\Service\Payment\MollieConfiguration;
use App\Service\Payment\MolliePaymentProvider;
use App\Service\Payment\PaymentRequest;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;
use Tests\Support\FakeMollie;
use Tests\Support\InvoiceOrderFixture;

/**
 * api/mollie-webhook.php over real HTTP against a fake Mollie
 * (tests/Support/fake-mollie.php):
 *
 *  - the payment is always asked for again at Mollie; the request only says
 *    WHICH payment, and a "status" in it is ignored;
 *  - paid, failed, canceled and expired reach the order; a second delivery
 *    changes nothing and issues no second invoice;
 *  - 400 for something that is not a Mollie payment id, before any request
 *    to Mollie; 405 for a GET;
 *  - 200 for a payment nobody knows: asking again would not help. A payment
 *    id no order holds is answered without asking Mollie at all, so an
 *    anonymous POST cannot spend the shop's requests there;
 *  - 503 with Retry-After when Mollie is down, refuses the key, or there is
 *    no key: the payment is real, so Mollie must deliver again — and the
 *    order is left as it was;
 *  - an order is looked up with the key of the mode its payment was made in
 *    (orders.payment_mode), whatever mode the shop is in now, and with no
 *    other key; without that mode's key: 503, and Mollie is not asked;
 *  - the release regression: a test order started before the owner switches
 *    the shop to Live is settled with the TEST key after the switch, is
 *    paid, stays a test order, and touches neither the invoice counter, the
 *    invoices nor the revenue; a live order is invoiced and counted as ever;
 *  - an order from before the mode was recorded (NULL) is still found with
 *    the other mode's stored key.
 *
 * A paid order gets its invoice as in production, in an invoice directory of
 * this test's own; no mail leaves (no notification address). Orders,
 * invoices, keys and settings are removed in tearDown().
 */
final class MollieWebhookHttpTest extends TestCase
{
    private const TEST_KEY = 'test_webhookwebhookwebhookwebhook12';
    private const LIVE_KEY = 'live_webhookwebhookwebhookwebhook34';

    private static ?BuiltInServer $environmentKey = null;
    private static ?BuiltInServer $storedKeys = null;
    private static string $directory = '';

    private InvoiceOrderFixture $fixture;
    private ?string $invoiceStorageBefore = null;

    /** @var array<string, string|null> */
    private array $settingsBefore = [];

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/mygdala-webhook-http-' . bin2hex(random_bytes(6));
        mkdir(self::$directory . '/invoices-base', 0700, true);

        $environment = [
            'MODULE_SHOP_ENABLED' => 'true',
            'APP_KEY' => '',
            'SECRETS_STORAGE_PATH' => self::$directory,
            'FAKE_MOLLIE_SCENARIO' => self::$directory . '/scenario.json',
            'INVOICE_STORAGE_PATH' => self::$directory . '/invoices-base',
            'SHOP_NOTIFICATION_EMAIL' => '',
            'MAIL_HOST' => '127.0.0.1',
            'MAIL_PORT' => '9',
            'APP_URL' => 'https://winkel.example.nl',
        ];
        $ini = ['auto_prepend_file' => dirname(__DIR__, 2) . '/tests/Support/fake-mollie.php'];

        self::$environmentKey = BuiltInServer::start(['MOLLIE_API_KEY' => self::TEST_KEY] + $environment, null, $ini);
        self::$storedKeys = BuiltInServer::start(['MOLLIE_API_KEY' => ''] + $environment, null, $ini);
    }

    public static function tearDownAfterClass(): void
    {
        self::$environmentKey?->stop();
        self::$storedKeys?->stop();
        self::$environmentKey = self::$storedKeys = null;

        self::remove(self::$directory);
    }

    protected function setUp(): void
    {
        if (self::$environmentKey === null || !self::$environmentKey->answers() || self::$storedKeys === null || !self::$storedKeys->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        // The fixture removes the invoices it caused from the same directory
        // the server writes them to.
        $this->invoiceStorageBefore = $_ENV['INVOICE_STORAGE_PATH'] ?? null;
        $_ENV['INVOICE_STORAGE_PATH'] = self::$directory . '/invoices-base';
        $this->fixture = new InvoiceOrderFixture();

        FakeMollie::write($this->scenario(), [
            'keys' => [self::TEST_KEY => 'ok', self::LIVE_KEY => 'ok'],
            'payments' => [
                'tr_whpaid' => ['mode' => 'test', 'status' => 'paid', 'paidAt' => '2026-09-27T10:00:00+00:00'],
                'tr_whfailed' => ['mode' => 'test', 'status' => 'failed'],
                'tr_whcanceled' => ['mode' => 'test', 'status' => 'canceled'],
                'tr_whexpired' => ['mode' => 'test', 'status' => 'expired'],
                'tr_whopen' => ['mode' => 'test', 'status' => 'open'],
                'tr_whnoorder' => ['mode' => 'test', 'status' => 'paid', 'paidAt' => '2026-09-27T10:00:00+00:00'],
                'tr_whlive' => ['mode' => 'live', 'status' => 'paid', 'paidAt' => '2026-09-27T10:00:00+00:00'],
            ],
        ]);
        @unlink($this->scenario() . '.log');

        $db = Database::connection();
        $statement = $db->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :key');
        $statement->execute(['key' => MollieConfiguration::MODE_SETTING]);
        $value = $statement->fetchColumn();
        $this->settingsBefore[MollieConfiguration::MODE_SETTING] = $value === false ? null : (string) $value;
        $this->clearStoredKeys();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanUp();
        $this->clearStoredKeys();

        $db = Database::connection();
        foreach ($this->settingsBefore as $key => $value) {
            $db->prepare('DELETE FROM site_settings WHERE setting_key = :key')->execute(['key' => $key]);
            if ($value !== null) {
                (new SiteSettingRepository())->upsertMany([$key => $value]);
            }
        }
        SiteSettings::clearCache();

        if ($this->invoiceStorageBefore === null) {
            unset($_ENV['INVOICE_STORAGE_PATH']);
        } else {
            $_ENV['INVOICE_STORAGE_PATH'] = $this->invoiceStorageBefore;
        }
    }

    private function scenario(): string
    {
        return self::$directory . '/scenario.json';
    }

    private function clearStoredKeys(): void
    {
        $statement = Database::connection()->prepare('DELETE FROM secret_settings WHERE slot = :slot');
        foreach (MollieConfiguration::SLOTS as $slot) {
            $statement->execute(['slot' => $slot]);
        }
    }

    private function pendingOrder(string $paymentId, ?string $mode = null): int
    {
        $orderId = $this->fixture->order('pending');
        (new OrderRepository())->setMolliePaymentId($orderId, $paymentId, $mode);

        return $orderId;
    }

    /** Both keys stored in the CMS, the shop in $mode: the stored-keys server's configuration. */
    private function storedKeysIn(string $mode): MollieConfiguration
    {
        $configuration = new MollieConfiguration(['MOLLIE_API_KEY' => '', 'APP_KEY' => '', 'SECRETS_STORAGE_PATH' => self::$directory]);
        $configuration->storeKey('test', self::TEST_KEY);
        $configuration->storeKey('live', self::LIVE_KEY);
        $this->switchShopTo($mode);

        return $configuration;
    }

    private function switchShopTo(string $mode): void
    {
        (new SiteSettingRepository())->upsertMany([MollieConfiguration::MODE_SETTING => $mode]);
        SiteSettings::clearCache();
    }

    private function invoiceCounter(): ?int
    {
        $statement = Database::connection()->prepare('SELECT last_number FROM invoice_number_counters WHERE year = :year');
        $statement->execute(['year' => (int) date('Y')]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /** @return array{order_count: int, gross_total: string, refunded_total: string} today's paid orders, as the dashboard counts them */
    private function revenue(): array
    {
        return (new DashboardRepository())->orderTotalsBetween(['paid'], date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+1 day')));
    }

    /** @param array<string, mixed> $changes */
    private function atMollie(string $paymentId, array $changes): void
    {
        $scenario = json_decode((string) file_get_contents($this->scenario()), true);
        $scenario['payments'][$paymentId] = $changes + (array) ($scenario['payments'][$paymentId] ?? []);
        FakeMollie::write($this->scenario(), $scenario);
    }

    /** @return array<string, mixed> */
    private function order(int $orderId): array
    {
        return (array) (new OrderRepository())->findById($orderId);
    }

    private function invoiceCount(int $orderId): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM invoices WHERE order_id = :id');
        $statement->execute(['id' => $orderId]);

        return (int) $statement->fetchColumn();
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    private function deliver(array $fields, ?BuiltInServer $server = null): array
    {
        return ($server ?? self::$environmentKey)->request('POST', '/api/mollie-webhook.php', null, $fields);
    }

    public function testAPaidPaymentReachesItsOrderOnceAndASecondDeliveryChangesNothing(): void
    {
        $orderId = $this->pendingOrder('tr_whpaid');

        $this->assertSame(200, $this->deliver(['id' => 'tr_whpaid'])['status']);

        $order = $this->order($orderId);
        $this->assertSame('paid', $order['status']);
        $this->assertSame('paid', $order['mollie_status']);
        $this->assertSame(1, $this->invoiceCount($orderId), 'paid: the invoice is issued, as the webhook always did');

        $updatedAt = $order['updated_at'];
        $this->assertSame(200, $this->deliver(['id' => 'tr_whpaid'])['status']);
        $this->assertSame(1, $this->invoiceCount($orderId), 'a duplicate delivery issues nothing new');
        $this->assertSame($updatedAt, $this->order($orderId)['updated_at'], 'and writes nothing');

        $requests = FakeMollie::requests($this->scenario());
        $this->assertSame(['GetPaymentRequest', 'GetPaymentRequest'], array_column($requests, 'request'), 'asked again at Mollie every time');
        $this->assertSame('/v2/payments/tr_whpaid', $requests[0]['path']);
    }

    public function testFailedCanceledAndExpiredReachTheOrder(): void
    {
        foreach (['tr_whfailed' => 'failed', 'tr_whcanceled' => 'canceled', 'tr_whexpired' => 'expired'] as $paymentId => $status) {
            $orderId = $this->pendingOrder($paymentId);

            $this->assertSame(200, $this->deliver(['id' => $paymentId])['status'], $status);
            $this->assertSame($status, $this->order($orderId)['status']);
            $this->assertSame(0, $this->invoiceCount($orderId));
        }
    }

    public function testTheRequestCannotSayHowThePaymentWent(): void
    {
        $orderId = $this->pendingOrder('tr_whopen');

        $this->assertSame(200, $this->deliver(['id' => 'tr_whopen', 'status' => 'paid', 'paidAt' => '2026-09-27'])['status']);

        $this->assertSame('pending', $this->order($orderId)['status'], 'only Mollie says paid');
        $this->assertSame('open', $this->order($orderId)['mollie_status']);
    }

    public function testSomethingThatIsNotAMolliePaymentIdIsRefusedBeforeMollieIsAsked(): void
    {
        foreach ([[], ['id' => ''], ['id' => 'ord_123'], ['id' => 'tr_abc/../x'], ['id' => 'tr_' . str_repeat('a', 61)], ['id' => ['tr_abc']]] as $fields) {
            $this->assertSame(400, $this->deliver($fields)['status'], json_encode($fields));
        }

        $this->assertSame(405, self::$environmentKey->request('GET', '/api/mollie-webhook.php?id=tr_whpaid')['status']);
        $this->assertSame([], FakeMollie::requests($this->scenario()));
    }

    public function testAPaymentNobodyKnowsIsAnsweredOnceAndForAll(): void
    {
        $this->assertSame(200, $this->deliver(['id' => 'tr_unknownatmollie'])['status'], 'Mollie does not know it');
        $this->assertSame(200, $this->deliver(['id' => 'tr_whnoorder'])['status'], 'no order holds it');
        $this->assertSame([], FakeMollie::requests($this->scenario()), 'no order holds either: Mollie is not asked');

        // An order holds it, Mollie does not know it: asked once, and final.
        $orderId = $this->pendingOrder('tr_whgone');
        $this->assertSame(200, $this->deliver(['id' => 'tr_whgone'])['status']);
        $this->assertSame(['GetPaymentRequest'], array_column(FakeMollie::requests($this->scenario()), 'request'));
        $this->assertSame('pending', $this->order($orderId)['status']);
    }

    public function testMollieDownARefusedKeyOrNoKeyAsksMollieToDeliverAgain(): void
    {
        $orderId = $this->pendingOrder('tr_whpaid');

        $scenario = json_decode((string) file_get_contents($this->scenario()), true);
        foreach (['down', 'error', 'unauthorized'] as $state) {
            $scenario['keys'][self::TEST_KEY] = $state;
            FakeMollie::write($this->scenario(), $scenario);

            $answer = $this->deliver(['id' => 'tr_whpaid']);
            $this->assertSame(503, $answer['status'], $state);
            $this->assertNotSame('', BuiltInServer::header($answer, 'Retry-After'), $state);
            $this->assertSame('pending', $this->order($orderId)['status'], $state . ': the order is left as it was');
        }

        // No key at all (the stored-keys server with nothing stored).
        $this->assertSame(503, $this->deliver(['id' => 'tr_whpaid'], self::$storedKeys)['status']);
        $this->assertSame('pending', $this->order($orderId)['status']);
    }

    public function testAnOrderFromBeforeTheModeWasRecordedIsFoundWithTheOtherModesStoredKey(): void
    {
        $this->storedKeysIn('test');
        $orderId = $this->pendingOrder('tr_whlive');
        $this->assertNull($this->order($orderId)['payment_mode'], 'precondition: a legacy order');

        $this->assertSame(200, $this->deliver(['id' => 'tr_whlive'], self::$storedKeys)['status']);

        $this->assertSame('paid', $this->order($orderId)['status']);
        $this->assertSame(['test', 'live'], array_column(FakeMollie::requests($this->scenario()), 'mode'), 'the active key first, then the other one');
    }

    /**
     * The release regression, step by step: a test order that is still open
     * when the owner switches the shop to Live.
     */
    public function testATestOrderPaidAfterTheSwitchToLiveIsSettledWithTheTestKeyAndStaysATest(): void
    {
        // 1. The shop is in Test.
        $configuration = $this->storedKeysIn('test');

        // 2. An order, and 3. its payment started exactly as api/checkout.php
        // starts it: the provider's answer names the mode, and that is stored.
        $orderId = $this->fixture->order('pending');
        FakeMollie::install($this->scenario());
        try {
            $payment = (new MolliePaymentProvider($configuration, 'https://winkel.example.nl', 'Winkel'))
                ->createPayment(new PaymentRequest($this->order($orderId), 'ideal', 'nl'));
        } finally {
            FakeMollie::uninstall();
        }
        (new OrderRepository())->setMolliePaymentId($orderId, $payment->id, $payment->mode);

        $this->assertSame('test', $payment->mode);
        $this->assertSame('test', $this->order($orderId)['payment_mode']);

        // 4. At Mollie the payment is still open; the order is pending.
        $this->assertSame('pending', $this->order($orderId)['status']);

        // 5. The owner switches the shop to Live.
        $this->switchShopTo('live');
        $counterBefore = $this->invoiceCounter();
        $revenueBefore = $this->revenue();

        // 6. The customer pays the test payment after all, and Mollie calls
        // the webhook for it.
        $this->atMollie($payment->id, ['status' => 'paid', 'paidAt' => '2026-09-27T11:00:00+00:00']);
        @unlink($this->scenario() . '.log');
        $this->assertSame(200, $this->deliver(['id' => $payment->id], self::$storedKeys)['status']);

        // 7. Looked up with the test key, because of the order, and with no
        // other key: not the live key the shop now uses.
        $requests = FakeMollie::requests($this->scenario());
        $this->assertSame(['GetPaymentRequest'], array_column($requests, 'request'));
        $this->assertSame('test', $requests[0]['mode']);
        $this->assertSame(substr(self::TEST_KEY, -4), $requests[0]['key_end']);

        // 8. The order is paid, and 9. stays a test order.
        $order = $this->order($orderId);
        $this->assertSame('paid', $order['status']);
        $this->assertSame('test', $order['payment_mode']);
        $this->assertTrue(OrderRepository::isTestOrder($order));

        // 10. The invoice counter did not move, 11. there is no invoice and
        // no PDF, 12. and the revenue is what it was.
        $this->assertSame($counterBefore, $this->invoiceCounter());
        $this->assertSame(0, $this->invoiceCount($orderId));
        $this->assertSame([], glob(self::$directory . '/invoices-base/invoices/*/*') ?: []);
        $this->assertSame($revenueBefore, $this->revenue());
    }

    public function testALiveOrderIsSettledWithTheLiveKeyWhileTheShopIsInTestAndIsInvoicedAndCounted(): void
    {
        $this->storedKeysIn('test');
        $orderId = $this->pendingOrder('tr_whlive', 'live');
        $counterBefore = (int) $this->invoiceCounter();
        $revenueBefore = $this->revenue();

        $this->assertSame(200, $this->deliver(['id' => 'tr_whlive'], self::$storedKeys)['status']);

        $this->assertSame(['live'], array_column(FakeMollie::requests($this->scenario()), 'mode'), 'the order\'s own key, not the active test key first');
        $order = $this->order($orderId);
        $this->assertSame('paid', $order['status']);
        $this->assertSame('live', $order['payment_mode']);
        $this->assertSame(1, $this->invoiceCount($orderId), 'a live order is invoiced as ever');
        $this->assertSame($counterBefore + 1, $this->invoiceCounter());

        $revenue = $this->revenue();
        $this->assertSame($revenueBefore['order_count'] + 1, $revenue['order_count']);
        $this->assertEqualsWithDelta((float) $revenueBefore['gross_total'] + 39.20, (float) $revenue['gross_total'], 0.001);
    }

    public function testWithoutAKeyForTheOrdersModeMollieMustDeliverAgainAndNoOtherKeyIsTried(): void
    {
        $configuration = new MollieConfiguration(['MOLLIE_API_KEY' => '', 'APP_KEY' => '', 'SECRETS_STORAGE_PATH' => self::$directory]);
        $configuration->storeKey('live', self::LIVE_KEY);
        $this->switchShopTo('live');
        $orderId = $this->pendingOrder('tr_whpaid', 'test');

        $answer = $this->deliver(['id' => 'tr_whpaid'], self::$storedKeys);

        $this->assertSame(503, $answer['status'], 'the payment is real; the test key may come back');
        $this->assertSame([], FakeMollie::requests($this->scenario()), 'the live key is never asked for a test payment');
        $this->assertSame('pending', $this->order($orderId)['status']);
    }

    private static function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
