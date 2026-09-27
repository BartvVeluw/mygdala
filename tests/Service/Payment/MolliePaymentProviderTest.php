<?php

declare(strict_types=1);

namespace Tests\Service\Payment;

use App\Database;
use App\Service\Payment\MollieConfiguration;
use App\Service\Payment\MolliePaymentProvider;
use App\Service\Payment\MollieSetupStatus;
use App\Service\Payment\PaymentProviderException;
use App\Service\Payment\PaymentRequest;
use App\Service\Payment\PaymentSnapshot;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMollie;

/**
 * App\Service\Payment\MolliePaymentProvider against Mollie's own SDK and a
 * fake Mollie in a file (Tests\Support\FakeMollie): no network, no account.
 *
 *  - createPayment: the body comes from the stored order, the return and
 *    webhook addresses from the configured base URL (a subfolder included),
 *    and no webhook where Mollie cannot call back;
 *  - createPayment names the mode the payment was made in;
 *  - fetchPayment: every Mollie status in the Shop's words, the refunds on
 *    request; with the order's mode only that mode's key is asked, whatever
 *    mode the shop is in, and without that key nothing is asked; without a
 *    mode (an order from before it was recorded) the other stored key is
 *    asked once after a not-found;
 *  - live payments need a public https site address, test payments do not,
 *    and the status card says why a working live key cannot take payments;
 *  - availableMethods and checkKey: one, several or no methods, named in the
 *    language asked, and a key of the wrong shape is refused before any
 *    request;
 *  - every failure is one kind — invalid credentials, temporary, not found,
 *    not configured — and no message carries the key.
 */
final class MolliePaymentProviderTest extends TestCase
{
    private const TEST_KEY = 'test_providerproviderprovider123456';
    private const LIVE_KEY = 'live_providerproviderprovider567890';

    private string $scenario;
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mygdala-provider-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->scenario = $this->directory . '/scenario.json';
        FakeMollie::write($this->scenario, [
            'keys' => [self::TEST_KEY => 'ok', self::LIVE_KEY => 'ok'],
            'methods' => [
                'test' => [
                    ['id' => 'ideal', 'description' => 'iDEAL'],
                    ['id' => 'creditcard', 'description' => ['nl_NL' => 'Creditcard', 'en_US' => 'Credit card']],
                    ['id' => 'bancontact', 'description' => 'Bancontact'],
                ],
                'live' => [['id' => 'ideal', 'description' => 'iDEAL']],
            ],
            'payments' => [
                'tr_paid' => ['mode' => 'test', 'status' => 'paid', 'paidAt' => '2026-09-27T10:00:00+00:00'],
                'tr_open' => ['mode' => 'test', 'status' => 'open'],
                'tr_failed' => ['mode' => 'test', 'status' => 'failed'],
                'tr_canceled' => ['mode' => 'test', 'status' => 'canceled'],
                'tr_expired' => ['mode' => 'test', 'status' => 'expired'],
                'tr_authorized' => ['mode' => 'test', 'status' => 'authorized'],
                'tr_live' => ['mode' => 'live', 'status' => 'paid', 'paidAt' => '2026-09-27T10:00:00+00:00'],
                'tr_refunded' => ['mode' => 'test', 'status' => 'paid', 'paidAt' => '2026-09-27T10:00:00+00:00',
                    'refunds' => [['id' => 're_one', 'amount' => '4.00', 'status' => 'refunded', 'description' => 'Deels terug']]],
            ],
            'created' => ['id' => 'tr_created', 'checkoutUrl' => 'https://www.mollie.com/checkout/test-mode?method=ideal&token=6.fake'],
        ]);
        FakeMollie::install($this->scenario);
        $this->clearStoredKeys();
    }

    protected function tearDown(): void
    {
        FakeMollie::uninstall();
        SiteSettings::overrideForTests(null);
        $this->clearStoredKeys();
        foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob($this->directory . '/secrets/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->directory . '/secrets');
        @rmdir($this->directory);
    }

    private function clearStoredKeys(): void
    {
        $statement = Database::connection()->prepare('DELETE FROM secret_settings WHERE slot = :slot');
        foreach (MollieConfiguration::SLOTS as $slot) {
            $statement->execute(['slot' => $slot]);
        }
    }

    private function configuration(string $environmentKey = self::TEST_KEY): MollieConfiguration
    {
        return new MollieConfiguration(['MOLLIE_API_KEY' => $environmentKey, 'APP_KEY' => '', 'SECRETS_STORAGE_PATH' => $this->directory]);
    }

    private function provider(string $environmentKey = self::TEST_KEY, string $baseUrl = 'https://winkel.example.nl'): MolliePaymentProvider
    {
        return new MolliePaymentProvider($this->configuration($environmentKey), $baseUrl, 'Winkel & Co');
    }

    /** @return array<string, mixed> */
    private static function order(): array
    {
        return ['id' => 42, 'order_number' => 'ORD-2026-000042', 'total' => '39.20', 'currency' => 'EUR'];
    }

    /** @return array<string, mixed> */
    private function lastRequest(): array
    {
        $requests = FakeMollie::requests($this->scenario);

        return (array) end($requests);
    }

    public function testCreatePaymentSendsTheStoredOrderAndTheConfiguredAddresses(): void
    {
        $created = $this->provider()->createPayment(new PaymentRequest(self::order(), 'bancontact', 'nl'));

        $this->assertSame('tr_created', $created->id);
        $this->assertStringStartsWith('https://www.mollie.com/checkout/', $created->checkoutUrl);

        $body = $this->lastRequest()['body'];
        $this->assertSame(['currency' => 'EUR', 'value' => '39.20'], $body['amount']);
        $this->assertSame('bancontact', $body['method']);
        $this->assertSame('Winkel & Co — bestelling ORD-2026-000042', $body['description']);
        $this->assertSame('https://winkel.example.nl/bestelling-status.php?order=42', $body['redirectUrl']);
        $this->assertSame('https://winkel.example.nl/api/mollie-webhook.php', $body['webhookUrl']);
        $this->assertSame(['order_id' => 42, 'order_number' => 'ORD-2026-000042'], $body['metadata']);
        $this->assertSame('test', $this->lastRequest()['mode']);
    }

    public function testASubfolderBaseUrlKeepsItsPath(): void
    {
        $this->provider(self::TEST_KEY, 'https://example.nl/winkel')->createPayment(new PaymentRequest(self::order(), 'ideal', 'nl'));

        $body = $this->lastRequest()['body'];
        $this->assertSame('https://example.nl/winkel/bestelling-status.php?order=42', $body['redirectUrl']);
        $this->assertSame('https://example.nl/winkel/api/mollie-webhook.php', $body['webhookUrl']);
    }

    public function testNoWebhookWhereMollieCannotCallBack(): void
    {
        foreach (['http://localhost:8300', 'https://shop.localhost', 'https://mygdala.test', 'http://192.168.1.20', 'http://10.0.0.5', 'http://127.0.0.1:8080', 'http://mygdala-php', 'https://[::1]'] as $base) {
            $this->assertFalse(MolliePaymentProvider::acceptsWebhooks($base), $base);
            $this->assertNull($this->provider(self::TEST_KEY, $base)->webhookUrl(), $base);
        }

        $this->provider(self::TEST_KEY, 'http://localhost:8300')->createPayment(new PaymentRequest(self::order(), 'ideal', 'nl'));
        $this->assertArrayNotHasKey('webhookUrl', $this->lastRequest()['body']);

        foreach (['https://www.example.nl', 'http://93.184.216.34', 'https://shop.example.co.uk/sub'] as $base) {
            $this->assertTrue(MolliePaymentProvider::acceptsWebhooks($base), $base);
        }
    }

    public function testEveryMollieStatusInTheShopsWords(): void
    {
        $expected = [
            'tr_paid' => [PaymentSnapshot::PAID, 'paid'],
            'tr_open' => [PaymentSnapshot::PENDING, 'open'],
            'tr_authorized' => [PaymentSnapshot::PENDING, 'authorized'],
            'tr_failed' => [PaymentSnapshot::FAILED, 'failed'],
            'tr_canceled' => [PaymentSnapshot::CANCELED, 'canceled'],
            'tr_expired' => [PaymentSnapshot::EXPIRED, 'expired'],
        ];

        foreach ($expected as $id => [$status, $providerStatus]) {
            $snapshot = $this->provider()->fetchPayment($id);
            $this->assertSame($id, $snapshot->id);
            $this->assertSame($status, $snapshot->status, $id);
            $this->assertSame($providerStatus, $snapshot->providerStatus, $id);
            $this->assertFalse($snapshot->hasRefunds);
        }
    }

    public function testRefundsArriveWithTheSnapshotWhenAskedFor(): void
    {
        $snapshot = $this->provider()->fetchPayment('tr_refunded');

        $this->assertTrue($snapshot->hasRefunds);
        $this->assertSame(4.0, $snapshot->amountRefunded);
        $refunds = $snapshot->refunds();
        $this->assertCount(1, $refunds);
        $this->assertSame('re_one', $refunds[0]->id);
        $this->assertSame(4.0, $refunds[0]->amount);
        $this->assertSame('refunded', $refunds[0]->status);
    }

    public function testAfterAModeSwitchThePaymentIsAskedForWithTheOtherStoredKey(): void
    {
        $cms = $this->configuration('');
        $cms->storeKey('test', self::TEST_KEY);
        $cms->storeKey('live', self::LIVE_KEY);
        SiteSettings::overrideForTests([MollieConfiguration::MODE_SETTING => 'test']);
        $provider = new MolliePaymentProvider($cms, 'https://winkel.example.nl', 'Winkel');

        $snapshot = $provider->fetchPayment('tr_live');

        $this->assertSame(PaymentSnapshot::PAID, $snapshot->status);
        $modes = array_column(FakeMollie::requests($this->scenario), 'mode');
        $this->assertSame(['test', 'live'], array_slice($modes, -2), 'the active key first, then the other one');

        $this->assertNotFound(fn () => $provider->fetchPayment('tr_nowhere'));
    }

    public function testWithOnlyOneKeyANotFoundPaymentIsNotFound(): void
    {
        $this->assertNotFound(fn () => $this->provider()->fetchPayment('tr_live'));
        $this->assertCount(1, FakeMollie::requests($this->scenario), 'the environment has one key: asked once');
    }

    public function testMethodsAreWhatMollieOffersNamedInTheLanguageAsked(): void
    {
        $dutch = $this->provider()->availableMethods('nl');
        $this->assertSame(['ideal', 'creditcard', 'bancontact'], array_map(fn ($m) => $m->id, $dutch));
        $this->assertSame('Creditcard', $dutch[1]->name);
        $this->assertSame('Credit card', $this->provider()->availableMethods('en')[1]->name);

        $this->assertSame(['ideal'], array_map(fn ($m) => $m->id, $this->provider(self::LIVE_KEY)->availableMethods('nl')), 'one method');

        $scenario = json_decode((string) file_get_contents($this->scenario), true);
        $scenario['methods']['test'] = [];
        FakeMollie::write($this->scenario, $scenario);
        $this->assertSame([], $this->provider()->availableMethods('nl'), 'no methods');
    }

    public function testAKeyOfTheWrongShapeIsRefusedBeforeAnyRequest(): void
    {
        try {
            $this->provider()->checkKey('test_short', 'nl');
            $this->fail('a key of the wrong shape must be refused');
        } catch (PaymentProviderException $e) {
            $this->assertSame(PaymentProviderException::NOT_CONFIGURED, $e->kind);
            $this->assertStringNotContainsString('test_short', $e->getMessage());
        }

        $this->assertSame([], FakeMollie::requests($this->scenario));
    }

    public function testEveryFailureIsOneKindAndNoMessageCarriesTheKey(): void
    {
        $refused = 'test_refusedrefusedrefusedrefused99';
        $forbidden = 'test_forbiddenforbiddenforbiddenx88';
        $down = 'test_downdowndowndowndowndowndown77';
        $error = 'test_errorerrorerrorerrorerrorerr66';

        $scenario = json_decode((string) file_get_contents($this->scenario), true);
        $scenario['keys'] += [$refused => 'unauthorized', $forbidden => 'forbidden', $down => 'down', $error => 'error'];
        FakeMollie::write($this->scenario, $scenario);

        $cases = [
            $refused => PaymentProviderException::INVALID_CREDENTIALS,
            $forbidden => PaymentProviderException::INVALID_CREDENTIALS,
            $down => PaymentProviderException::TEMPORARY,
            $error => PaymentProviderException::TEMPORARY,
        ];

        foreach ($cases as $key => $kind) {
            foreach ([
                'checkKey' => fn () => $this->provider()->checkKey($key, 'nl'),
                'fetchPayment' => fn () => $this->provider($key)->fetchPayment('tr_paid'),
                'createPayment' => fn () => $this->provider($key)->createPayment(new PaymentRequest(self::order(), 'ideal', 'nl')),
            ] as $call => $run) {
                try {
                    $run();
                    $this->fail($call . ' with ' . $kind . ' must fail');
                } catch (PaymentProviderException $e) {
                    $this->assertSame($kind, $e->kind, $call);
                    $this->assertStringNotContainsString(substr($key, 5), $e->getMessage(), $call . ' must not carry the key');
                }
            }
        }
    }

    public function testNotConfiguredIsKnownWithoutARequest(): void
    {
        $provider = $this->provider('');

        $this->assertFalse($provider->isConfigured());
        foreach ([
            fn () => $provider->fetchPayment('tr_paid'),
            fn () => $provider->availableMethods('nl'),
            fn () => $provider->createPayment(new PaymentRequest(self::order(), 'ideal', 'nl')),
        ] as $call) {
            try {
                $call();
                $this->fail('nothing to pay with');
            } catch (PaymentProviderException $e) {
                $this->assertSame(PaymentProviderException::NOT_CONFIGURED, $e->kind);
            }
        }
        $this->assertSame([], FakeMollie::requests($this->scenario));
        $this->assertTrue($this->provider()->isConfigured());
    }

    public function testACreatedPaymentNamesTheModeItWasMadeIn(): void
    {
        $this->assertSame('test', $this->provider()->createPayment(new PaymentRequest(self::order(), 'ideal', 'nl'))->mode);
        $this->assertSame('live', $this->provider(self::LIVE_KEY)->createPayment(new PaymentRequest(self::order(), 'ideal', 'nl'))->mode);
    }

    public function testWithTheOrdersModeOnlyThatModesKeyIsAskedWhateverTheShopIsIn(): void
    {
        $cms = $this->configuration('');
        $cms->storeKey('test', self::TEST_KEY);
        $cms->storeKey('live', self::LIVE_KEY);
        SiteSettings::overrideForTests([MollieConfiguration::MODE_SETTING => 'live']);
        $provider = new MolliePaymentProvider($cms, 'https://winkel.example.nl', 'Winkel');

        $this->assertSame(PaymentSnapshot::PAID, $provider->fetchPayment('tr_paid', 'test')->status, 'a test order in a live shop');
        $this->assertSame(['test'], array_column(FakeMollie::requests($this->scenario), 'mode'));

        // Not under the order's own key: not found, and no other key is tried.
        $this->assertNotFound(fn () => $provider->fetchPayment('tr_live', 'test'));
        $this->assertSame(['test', 'test'], array_column(FakeMollie::requests($this->scenario), 'mode'));

        SiteSettings::overrideForTests([MollieConfiguration::MODE_SETTING => 'test']);
        $this->assertSame(PaymentSnapshot::PAID, $provider->fetchPayment('tr_live', 'live')->status, 'a live order in a test shop');
        $this->assertSame('live', $this->lastRequest()['mode']);
    }

    public function testWithoutAKeyForTheOrdersModeNothingIsAsked(): void
    {
        $cms = $this->configuration('');
        $cms->storeKey('live', self::LIVE_KEY);
        SiteSettings::overrideForTests([MollieConfiguration::MODE_SETTING => 'live']);
        $cmsProvider = new MolliePaymentProvider($cms, 'https://winkel.example.nl', 'Winkel');

        foreach ([
            'no stored test key' => fn () => $cmsProvider->fetchPayment('tr_paid', 'test'),
            'an unknown mode' => fn () => $cmsProvider->fetchPayment('tr_paid', 'production'),
            'the environment pins a test key' => fn () => $this->provider(self::TEST_KEY)->fetchPayment('tr_live', 'live'),
        ] as $case => $call) {
            try {
                $call();
                $this->fail($case . ': must not be looked up');
            } catch (PaymentProviderException $e) {
                $this->assertSame(PaymentProviderException::NOT_CONFIGURED, $e->kind, $case);
            }
        }

        $this->assertSame([], FakeMollie::requests($this->scenario), 'no other key is ever tried');
    }

    public function testLivePaymentsNeedAPublicHttpsSiteAddressAndTestPaymentsDoNot(): void
    {
        $this->assertSame('missing', MolliePaymentProvider::liveBaseUrlProblemFor('http://localhost', false));
        $this->assertSame('not_https', MolliePaymentProvider::liveBaseUrlProblemFor('http://winkel.example.nl', true));
        $this->assertSame('not_public', MolliePaymentProvider::liveBaseUrlProblemFor('https://mygdala.localhost', true));
        $this->assertSame('not_public', MolliePaymentProvider::liveBaseUrlProblemFor('https://192.168.1.20', true));
        $this->assertNull(MolliePaymentProvider::liveBaseUrlProblemFor('https://winkel.example.nl', true));

        $log = $this->directory . '/error.log';
        $previousLog = (string) ini_set('error_log', $log);
        try {
            foreach (['http://winkel.example.nl', 'https://shop.localhost', 'https://10.0.0.5'] as $base) {
                $live = $this->provider(self::LIVE_KEY, $base);
                $this->assertFalse($live->isConfigured(), $base);
                try {
                    $live->createPayment(new PaymentRequest(self::order(), 'ideal', 'nl'));
                    $this->fail($base . ': no live payment');
                } catch (PaymentProviderException $e) {
                    $this->assertSame(PaymentProviderException::NOT_CONFIGURED, $e->kind, $base);
                    $this->assertStringNotContainsString(substr(self::LIVE_KEY, 5), $e->getMessage());
                }

                $this->assertTrue($this->provider(self::TEST_KEY, $base)->isConfigured(), $base . ': test mode stays usable');
            }
        } finally {
            ini_set('error_log', $previousLog);
        }

        $this->assertSame([], FakeMollie::requests($this->scenario), 'refused before Mollie is asked');
        $this->assertStringContainsString('live payments refused', (string) file_get_contents($log));
        $this->assertStringNotContainsString(substr(self::LIVE_KEY, 5), (string) file_get_contents($log));
        $this->assertTrue($this->provider(self::LIVE_KEY)->isConfigured(), 'a public https address');
    }

    public function testTheStatusCardSaysWhyAWorkingLiveKeyCannotTakePayments(): void
    {
        $status = MollieSetupStatus::current($this->configuration(self::LIVE_KEY), $this->provider(self::LIVE_KEY, 'http://winkel.example.nl'), 'nl');
        $this->assertSame(MollieSetupStatus::PROBLEM, $status->state);
        $this->assertSame('not_https', $status->urlProblem);
        $this->assertTrue($status->connection?->ok(), 'the key itself works');

        $this->assertSame(MollieSetupStatus::LIVE, MollieSetupStatus::current($this->configuration(self::LIVE_KEY), $this->provider(self::LIVE_KEY), 'nl')->state);
        $this->assertSame(MollieSetupStatus::TEST, MollieSetupStatus::current($this->configuration(), $this->provider(self::TEST_KEY, 'http://mygdala.localhost'), 'nl')->state);
    }

    public function testRedactionRemovesEveryKeyShapedWord(): void
    {
        $this->assertSame(
            'failed for test_[redacted] and live_[redacted] (access_[redacted])',
            PaymentProviderException::redact('failed for test_abcdefghij1234567890 and live_zyxwvutsrq0987654321 (access_tokentokentoken)')
        );
    }

    private function assertNotFound(callable $call): void
    {
        try {
            $call();
            $this->fail('expected NOT_FOUND');
        } catch (PaymentProviderException $e) {
            $this->assertSame(PaymentProviderException::NOT_FOUND, $e->kind);
        }
    }
}
