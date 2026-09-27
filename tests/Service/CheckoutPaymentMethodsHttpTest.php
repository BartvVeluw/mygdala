<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\SiteSettingRepository;
use App\Service\Payment\ShopPaymentMethods;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;
use Tests\Support\FakeMollie;

/**
 * The checkout and the payment methods the owner offers (Shop → Betalingen),
 * over real HTTP — checkout.php, cart.php and api/checkout.php up to the
 * point where a payment would start, so no address service, Turnstile or
 * Mollie is ever reached:
 *
 *  - without a choice the checkout offers exactly what it always offered:
 *    iDEAL (chosen) and credit card, with their old words;
 *  - a choice is offered in its order, one method as the one chosen, and the
 *    cart names the real methods;
 *  - api/checkout.php refuses up front, with nothing stored, when there is no
 *    payment key; with one, it refuses a method the shop does not offer and
 *    lets an offered one (and the old "kaart") through to the next check.
 */
final class CheckoutPaymentMethodsHttpTest extends TestCase
{
    private const TEST_KEY = 'test_checkoutcheckoutcheckout123456';

    private static ?BuiltInServer $configured = null;
    private static ?BuiltInServer $unconfigured = null;
    private static string $directory = '';

    /** @var array<string, string|null> */
    private array $settingsBefore = [];

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/mygdala-checkout-methods-' . bin2hex(random_bytes(6));
        mkdir(self::$directory, 0700, true);

        $environment = [
            'MODULE_SHOP_ENABLED' => 'true',
            'APP_KEY' => '',
            'SECRETS_STORAGE_PATH' => self::$directory,
            'FAKE_MOLLIE_SCENARIO' => self::$directory . '/scenario.json',
        ];
        $ini = ['auto_prepend_file' => dirname(__DIR__, 2) . '/tests/Support/fake-mollie.php'];

        self::$configured = BuiltInServer::start(['MOLLIE_API_KEY' => self::TEST_KEY] + $environment, null, $ini);
        self::$unconfigured = BuiltInServer::start(['MOLLIE_API_KEY' => 'test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'] + $environment, null, $ini);
    }

    public static function tearDownAfterClass(): void
    {
        self::$configured?->stop();
        self::$unconfigured?->stop();
        self::$configured = self::$unconfigured = null;

        foreach (glob(self::$directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir(self::$directory);
    }

    protected function setUp(): void
    {
        if (self::$configured === null || !self::$configured->answers() || self::$unconfigured === null || !self::$unconfigured->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        FakeMollie::write(self::$directory . '/scenario.json', ['keys' => [self::TEST_KEY => 'ok']]);
        @unlink(self::$directory . '/scenario.json.log');

        $db = Database::connection();
        foreach ([ShopPaymentMethods::SETTING_KEY, ShopPaymentMethods::NAMES_SETTING_KEY] as $key) {
            $statement = $db->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :key');
            $statement->execute(['key' => $key]);
            $value = $statement->fetchColumn();
            $this->settingsBefore[$key] = $value === false ? null : (string) $value;
            $db->prepare('DELETE FROM site_settings WHERE setting_key = :key')->execute(['key' => $key]);
        }
        SiteSettings::clearCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->settingsBefore as $key => $value) {
            $db->prepare('DELETE FROM site_settings WHERE setting_key = :key')->execute(['key' => $key]);
            if ($value !== null) {
                (new SiteSettingRepository())->upsertMany([$key => $value]);
            }
        }
        SiteSettings::clearCache();
    }

    /** @return list<array{0: string, 1: string, 2: bool}> value, name, checked */
    private function radios(string $html): array
    {
        preg_match_all('#<input type="radio" name="betaalmethode" value="([^"]+)"( checked)?>\s*<span class="radio-card__label">\s*<strong>([^<]*)</strong>#', $html, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match): array => [$match[1], $match[3], $match[2] !== ''], $matches);
    }

    /** @param array<string, mixed> $overrides */
    private function checkout(BuiltInServer $server, array $overrides): array
    {
        $body = $overrides + [
            'voornaam' => 'Test', 'achternaam' => 'Klant', 'email' => 'checkout-methods@example.invalid', 'telefoon' => '',
            'verzendmethode' => 'verzenden', 'land' => 'BE', 'postcode' => '1000', 'huisnummer' => '1',
            'straat' => 'Grote Markt', 'plaats' => 'Brussel', 'betaalmethode' => 'ideal',
            'items' => [['id' => 1, 'qty' => 1]], 'terms_accepted' => false, 'facturatie_zelfde' => true,
        ];

        $handle = curl_init('http://127.0.0.1:' . $server->port . '/api/checkout.php');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [$status, (array) json_decode($response, true)];
    }

    private function orderCount(): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM orders o JOIN customers c ON c.id = o.customer_id WHERE c.email = 'checkout-methods@example.invalid'")->fetchColumn();
    }

    public function testWithoutAChoiceTheCheckoutOffersWhatItAlwaysOffered(): void
    {
        $page = self::$configured->request('GET', '/checkout.php');

        $this->assertSame(200, $page['status']);
        $this->assertSame([['ideal', 'iDEAL', true], ['creditcard', 'Creditcard', false]], $this->radios($page['body']));
        $this->assertStringContainsString('Direct betalen via je eigen bank', $page['body']);
        $this->assertStringContainsString('Visa, Mastercard', $page['body']);
        $this->assertStringContainsString('Betalen met iDEAL, Creditcard, via Mollie.', self::$configured->request('GET', '/cart.php')['body']);
        $this->assertSame([], FakeMollie::requests(self::$directory . '/scenario.json'), 'a page view asks Mollie nothing');
    }

    public function testAChoiceIsOfferedInItsOrder(): void
    {
        (new SiteSettingRepository())->upsertMany([
            ShopPaymentMethods::SETTING_KEY => 'bancontact,ideal',
            ShopPaymentMethods::NAMES_SETTING_KEY => '{"bancontact":{"nl":"Bancontact","en":"Bancontact"},"ideal":{"nl":"iDEAL","en":"iDEAL"}}',
        ]);

        $this->assertSame([['bancontact', 'Bancontact', true], ['ideal', 'iDEAL', false]], $this->radios(self::$configured->request('GET', '/checkout.php')['body']));

        (new SiteSettingRepository())->upsertMany([ShopPaymentMethods::SETTING_KEY => 'paypal', ShopPaymentMethods::NAMES_SETTING_KEY => '{"paypal":{"nl":"PayPal"}}']);
        $this->assertSame([['paypal', 'PayPal', true]], $this->radios(self::$configured->request('GET', '/checkout.php')['body']), 'one method is simply the one chosen');
    }

    public function testWithoutAPaymentKeyTheCheckoutRefusesBeforeAnythingIsStored(): void
    {
        [$status, $body] = $this->checkout(self::$unconfigured, ['terms_accepted' => true]);

        $this->assertSame(503, $status);
        $this->assertStringContainsString('not available', (string) ($body['error'] ?? ''));
        $this->assertSame(0, $this->orderCount());
    }

    public function testOnlyAnOfferedMethodGetsThrough(): void
    {
        [$status, $body] = $this->checkout(self::$configured, ['betaalmethode' => 'bancontact']);
        $this->assertSame(400, $status);
        $this->assertSame('Invalid payment method.', $body['error'] ?? null);

        foreach (['ideal', 'creditcard', 'kaart'] as $offered) {
            [$status, $body] = $this->checkout(self::$configured, ['betaalmethode' => $offered]);
            $this->assertSame(400, $status, $offered);
            $this->assertStringContainsString('Terms & Conditions', (string) ($body['error'] ?? ''), $offered . ' passed the method check and stopped at the next one');
        }

        (new SiteSettingRepository())->upsertMany([ShopPaymentMethods::SETTING_KEY => 'bancontact', ShopPaymentMethods::NAMES_SETTING_KEY => '{}']);
        [, $body] = $this->checkout(self::$configured, ['betaalmethode' => 'bancontact']);
        $this->assertStringContainsString('Terms & Conditions', (string) ($body['error'] ?? ''));
        [, $body] = $this->checkout(self::$configured, ['betaalmethode' => 'ideal']);
        $this->assertSame('Invalid payment method.', $body['error'] ?? null, 'switched off: refused');

        $this->assertSame(0, $this->orderCount());
        $this->assertSame([], FakeMollie::requests(self::$directory . '/scenario.json'));
    }
}
