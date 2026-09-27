<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ShopModule;
use App\Repository\SiteSettingRepository;
use App\Service\AdminPermissions;
use App\Service\Payment\MollieConfiguration;
use App\Service\Payment\ShopPaymentMethods;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\FakeMollie;

/**
 * Shop → Betalingen over real HTTP, for real signed-in accounts, against a
 * fake Mollie (tests/Support/fake-mollie.php, prepended to PHP's built-in
 * server): admin/payments.php, api/admin/update-payment-settings.php and
 * api/admin/test-payment-connection.php.
 *
 *  - the guards: signed out, without payments.manage, with the Shop off,
 *    without the CSRF token, and a GET;
 *  - the editor contract: 422 with messages keyed by field, 200 when saved,
 *    and the redirect with a flash for a form posted without the script;
 *  - A KEY IS NEVER SENT BACK: not in a JSON answer, not in the page after
 *    saving (masked only, the fields empty), not in a session flash, not in a
 *    connection test's answer;
 *  - the connection test changes nothing: no stored key, no setting, and at
 *    Mollie nothing but the read-only methods call;
 *  - with MOLLIE_API_KEY in the environment the screen says so, shows no key
 *    fields, and a crafted post cannot store a key.
 *
 * Every server has a key directory and a scenario of this test's own; the
 * stored keys and settings are put back in tearDown(). Without a server the
 * test skips itself.
 */
final class PaymentSettingsHttpTest extends TestCase
{
    private const TEST_KEY = 'test_httphttphttphttphttphttphttp1234';
    private const REFUSED_KEY = 'test_nopenopenopenopenopenopenope9999';
    private const DOWN_KEY = 'test_downdowndowndowndowndowndown5555';

    private static ?BuiltInServer $shop = null;
    private static ?BuiltInServer $pinned = null;
    private static ?BuiltInServer $noShop = null;
    private static string $directory = '';

    private AdminTestSession $accounts;

    /** @var array<string, string|null> */
    private array $settingsBefore = [];

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/mygdala-payments-http-' . bin2hex(random_bytes(6));
        mkdir(self::$directory, 0700, true);

        $environment = [
            'MODULE_SHOP_ENABLED' => 'true',
            'MOLLIE_API_KEY' => '',
            'APP_KEY' => '',
            'SECRETS_STORAGE_PATH' => self::$directory,
            'FAKE_MOLLIE_SCENARIO' => self::$directory . '/scenario.json',
            'APP_URL' => 'https://winkel.example.nl',
        ];
        $ini = ['auto_prepend_file' => dirname(__DIR__, 2) . '/tests/Support/fake-mollie.php'];

        self::$shop = BuiltInServer::start($environment, null, $ini);
        self::$pinned = BuiltInServer::start(['MOLLIE_API_KEY' => self::TEST_KEY] + $environment, null, $ini);
        self::$noShop = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'false'] + $environment, null, $ini);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$shop, self::$pinned, self::$noShop] as $server) {
            $server?->stop();
        }
        self::$shop = self::$pinned = self::$noShop = null;

        foreach (array_merge(glob(self::$directory . '/{,.}*', GLOB_BRACE) ?: [], glob(self::$directory . '/secrets/{,.}*', GLOB_BRACE) ?: []) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir(self::$directory . '/secrets');
        @rmdir(self::$directory);
    }

    protected function setUp(): void
    {
        $this->accounts = new AdminTestSession();

        foreach ([self::$shop, self::$pinned, self::$noShop] as $server) {
            if ($server === null || !$server->answers()) {
                $this->markTestSkipped("could not start PHP's built-in web server for this test");
            }
        }

        FakeMollie::write($this->scenario(), [
            'keys' => [self::TEST_KEY => 'ok', self::REFUSED_KEY => 'unauthorized', self::DOWN_KEY => 'error'],
            'methods' => ['test' => [['id' => 'ideal', 'description' => 'iDEAL'], ['id' => 'creditcard', 'description' => 'Creditcard']]],
        ]);
        @unlink($this->scenario() . '.log');

        $db = Database::connection();
        foreach ([MollieConfiguration::MODE_SETTING, ShopPaymentMethods::SETTING_KEY, ShopPaymentMethods::NAMES_SETTING_KEY] as $key) {
            $statement = $db->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :key');
            $statement->execute(['key' => $key]);
            $value = $statement->fetchColumn();
            $this->settingsBefore[$key] = $value === false ? null : (string) $value;
            $db->prepare('DELETE FROM site_settings WHERE setting_key = :key')->execute(['key' => $key]);
        }
        $this->clearStoredKeys();
        SiteSettings::clearCache();
    }

    protected function tearDown(): void
    {
        $this->accounts->forget();
        $this->clearStoredKeys();

        $db = Database::connection();
        foreach ($this->settingsBefore as $key => $value) {
            $db->prepare('DELETE FROM site_settings WHERE setting_key = :key')->execute(['key' => $key]);
            if ($value !== null) {
                (new SiteSettingRepository())->upsertMany([$key => $value]);
            }
        }
        SiteSettings::clearCache();
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

    private function configuration(): MollieConfiguration
    {
        return new MollieConfiguration(['MOLLIE_API_KEY' => '', 'APP_KEY' => '', 'SECRETS_STORAGE_PATH' => self::$directory]);
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    private function save(string $session, string $csrf, array $fields, bool $json = true, ?BuiltInServer $server = null): array
    {
        return ($server ?? self::$shop)->request('POST', '/api/admin/update-payment-settings.php', $session, ['csrf_token' => $csrf] + $fields, [], $json ? ['Accept: application/json'] : []);
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    private function test(string $session, string $csrf, array $fields, ?BuiltInServer $server = null): array
    {
        return ($server ?? self::$shop)->request('POST', '/api/admin/test-payment-connection.php', $session, ['csrf_token' => $csrf] + $fields, [], ['Accept: application/json']);
    }

    private function countStoredKeys(): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM secret_settings WHERE slot LIKE 'shop.mollie.%'")->fetchColumn();
    }

    public function testSignedOutWithoutPermissionAndWithTheShopOffNothingIsShownOrSaved(): void
    {
        $page = self::$shop->request('GET', '/admin/payments.php');
        $this->assertSame(302, $page['status']);
        $this->assertSame('/admin/login.php', $page['location']);
        $this->assertSame(401, self::$shop->request('POST', '/api/admin/update-payment-settings.php', null, ['test_api_key' => self::TEST_KEY])['status']);
        $this->assertSame(401, self::$shop->request('POST', '/api/admin/test-payment-connection.php', null, ['test_mode' => 'active'])['status']);

        [$pagesOnly, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $this->assertSame(403, self::$shop->request('GET', '/admin/payments.php', $pagesOnly)['status']);
        $this->assertSame(403, $this->save($pagesOnly, $csrf, ['test_api_key' => self::TEST_KEY])['status']);
        $this->assertSame(403, $this->test($pagesOnly, $csrf, ['test_mode' => 'active'])['status']);

        [$superAdmin, $superCsrf] = $this->accounts->signIn([], true);
        $this->assertSame(403, self::$noShop->request('GET', '/admin/payments.php', $superAdmin)['status']);
        $this->assertSame(404, $this->save($superAdmin, $superCsrf, ['test_api_key' => self::TEST_KEY], true, self::$noShop)['status']);
        $this->assertSame(404, $this->test($superAdmin, $superCsrf, ['test_mode' => 'active'], self::$noShop)['status']);

        $this->assertSame(0, $this->countStoredKeys());
        $this->assertSame([], FakeMollie::requests($this->scenario()), 'nobody reached Mollie');
    }

    public function testSettingsManageAloneReachesNoPaymentKeyButKeepsTheShopSettings(): void
    {
        [$settingsOnly, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);

        $shopSettings = self::$shop->request('GET', '/admin/shop-settings.php', $settingsOnly);
        $this->assertSame(200, $shopSettings['status'], 'Shop-instellingen keeps its own permission');
        $this->assertStringNotContainsString('href="/admin/payments.php"', $shopSettings['body'], 'no Betalingen in the menu');

        // The screen, and every forged post straight at the endpoints: a new
        // key, a switch to live, the methods, the connection test.
        $answers = [
            self::$shop->request('GET', '/admin/payments.php', $settingsOnly),
            $this->save($settingsOnly, $csrf, ['test_api_key' => self::TEST_KEY, 'payment_mode' => 'test']),
            $this->save($settingsOnly, $csrf, ['payment_mode' => 'live']),
            $this->save($settingsOnly, $csrf, ['payment_methods_submitted' => '1', 'payment_methods' => ['ideal']], false),
            $this->test($settingsOnly, $csrf, ['test_mode' => 'test', 'test_api_key' => self::TEST_KEY]),
        ];
        foreach ($answers as $index => $answer) {
            $this->assertSame(403, $answer['status'], (string) $index);
            $this->assertStringNotContainsString(self::TEST_KEY, $answer['body'] . $answer['headers'], (string) $index);
        }

        $this->assertSame(0, $this->countStoredKeys());
        $mode = Database::connection()->prepare('SELECT COUNT(*) FROM site_settings WHERE setting_key = :key');
        $mode->execute(['key' => MollieConfiguration::MODE_SETTING]);
        $this->assertSame(0, (int) $mode->fetchColumn(), 'the mode was not written');
        $this->assertSame([], FakeMollie::requests($this->scenario()), 'nobody reached Mollie');

        // A Super Admin holds payments.manage without a grant.
        [$superAdmin, $superCsrf] = $this->accounts->signIn([], true);
        $this->assertSame(200, self::$shop->request('GET', '/admin/payments.php', $superAdmin)['status']);
        $this->assertStringContainsString('href="/admin/payments.php"', self::$shop->request('GET', '/admin/shop-settings.php', $superAdmin)['body']);
        $this->assertSame(200, $this->save($superAdmin, $superCsrf, ['test_api_key' => self::TEST_KEY, 'payment_mode' => 'test'])['status']);
        $this->assertSame(self::TEST_KEY, $this->configuration()->keyFor('test'));
    }

    public function testTheTokenAndThePostAreRequired(): void
    {
        [$session] = $this->accounts->signIn([ShopModule::PAYMENTS_MANAGE]);

        $this->assertSame(403, self::$shop->request('POST', '/api/admin/update-payment-settings.php', $session, ['test_api_key' => self::TEST_KEY], [], ['Accept: application/json'])['status']);
        $this->assertSame(403, self::$shop->request('POST', '/api/admin/test-payment-connection.php', $session, ['test_mode' => 'test', 'test_api_key' => self::TEST_KEY], [], ['Accept: application/json'])['status']);
        $this->assertSame(405, self::$shop->request('GET', '/api/admin/update-payment-settings.php?test_api_key=' . self::TEST_KEY, $session)['status']);
        $this->assertSame(405, self::$shop->request('GET', '/api/admin/test-payment-connection.php', $session)['status']);
        $this->assertSame(0, $this->countStoredKeys());
    }

    public function testASavedKeyIsStoredAndNeverShownAgain(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PAYMENTS_MANAGE]);

        $saved = $this->save($session, $csrf, ['test_api_key' => self::TEST_KEY, 'payment_mode' => 'test']);
        $this->assertSame(200, $saved['status']);
        $this->assertSame(['ok' => true, 'message' => 'Opgeslagen', 'data' => [], 'errors' => []], json_decode($saved['body'], true));
        $this->assertStringNotContainsString(self::TEST_KEY, $saved['body']);
        $this->assertSame(self::TEST_KEY, $this->configuration()->keyFor('test'));

        $page = self::$shop->request('GET', '/admin/payments.php', $session);
        $this->assertSame(200, $page['status']);
        $this->assertStringNotContainsString(self::TEST_KEY, $page['body']);
        $this->assertStringNotContainsString(substr(self::TEST_KEY, 5, 20), $page['body']);
        $this->assertStringContainsString('test_••••••••1234', $page['body']);
        $this->assertStringContainsString('admin-payments-badge--test">Testmodus<', $page['body']);
        $this->assertMatchesRegularExpression('/<input type="password" id="payments-test-key" name="test_api_key" value=""/', $page['body']);
        $this->assertStringContainsString('https://winkel.example.nl/api/mollie-webhook.php', $page['body']);

        // An empty field keeps the key.
        $this->assertSame(200, $this->save($session, $csrf, ['test_api_key' => '', 'live_api_key' => ''])['status']);
        $this->assertSame(self::TEST_KEY, $this->configuration()->keyFor('test'));
    }

    public function testARefusedSaveNamesTheFieldAndNeverRepeatsTheKey(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PAYMENTS_MANAGE]);

        $refused = $this->save($session, $csrf, ['test_api_key' => 'live_' . substr(self::TEST_KEY, 5)]);
        $this->assertSame(422, $refused['status']);
        $this->assertStringContainsString('application/json', BuiltInServer::header($refused, 'Content-Type'));
        $body = json_decode($refused['body'], true);
        $this->assertFalse($body['ok']);
        $this->assertSame(['test_api_key'], array_keys($body['errors']));
        $this->assertStringNotContainsString(substr(self::TEST_KEY, 5), $refused['body']);

        // Without the script: the redirect, and a flash without the key.
        $plain = $this->save($session, $csrf, ['test_api_key' => 'live_' . substr(self::TEST_KEY, 5)], false);
        $this->assertSame(302, $plain['status']);
        $this->assertSame('/admin/payments.php', $plain['location']);
        $flash = $this->accounts->read($session, 'admin_payments_errors');
        $this->assertIsArray($flash);
        $this->assertStringNotContainsString(substr(self::TEST_KEY, 5), (string) json_encode($flash));
        $this->assertNull($this->accounts->read($session, 'admin_payments_old'), 'nothing typed is kept in the session');

        $this->assertSame(0, $this->countStoredKeys());
    }

    public function testTheConnectionTestIsReadOnlyAndNeverEchoesTheKey(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PAYMENTS_MANAGE]);
        $settingsBefore = Database::connection()->query('SELECT setting_key, setting_value, updated_at FROM site_settings ORDER BY setting_key')->fetchAll();

        $cases = [
            [['test_mode' => 'test', 'test_api_key' => self::TEST_KEY], true, 'ok'],
            [['test_mode' => 'test', 'test_api_key' => self::REFUSED_KEY], false, 'refused'],
            [['test_mode' => 'test', 'test_api_key' => self::DOWN_KEY], false, 'unreachable'],
            [['test_mode' => 'test', 'test_api_key' => 'test_short'], false, 'key_format'],
            [['test_mode' => 'active'], false, 'no_key'],
        ];

        foreach ($cases as [$fields, $ok, $result]) {
            $answer = $this->test($session, $csrf, $fields);
            $this->assertSame(200, $answer['status'], $result);
            $body = json_decode($answer['body'], true);
            $this->assertSame($ok, $body['ok'], $result);
            $this->assertSame($result, $body['data']['result'], $result);
            $this->assertNotSame('', $body['message']);
            if (isset($fields['test_api_key'])) {
                $this->assertStringNotContainsString(substr($fields['test_api_key'], 5), $answer['body'], $result . ': the key is not echoed');
            }
        }

        $this->assertSame(0, $this->countStoredKeys(), 'testing stores nothing');
        $this->assertSame($settingsBefore, Database::connection()->query('SELECT setting_key, setting_value, updated_at FROM site_settings ORDER BY setting_key')->fetchAll());
        $this->assertSame(['GetEnabledMethodsRequest'], array_values(array_unique(array_column(FakeMollie::requests($this->scenario()), 'request'))), 'only the read-only call reached Mollie');
    }

    public function testAKeyInTheEnvironmentIsShownAsSuchAndCannotBeReplaced(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PAYMENTS_MANAGE]);

        $page = self::$pinned->request('GET', '/admin/payments.php', $session);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Geconfigureerd via serveromgeving', $page['body']);
        $this->assertStringContainsString('test_••••••••1234', $page['body']);
        $this->assertStringNotContainsString(self::TEST_KEY, $page['body']);
        $this->assertStringNotContainsString('name="test_api_key"', $page['body']);
        $this->assertStringNotContainsString('name="payment_mode"', $page['body']);

        $refused = $this->save($session, $csrf, ['test_api_key' => self::REFUSED_KEY, 'payment_mode' => 'live'], true, self::$pinned);
        $this->assertSame(422, $refused['status']);
        $this->assertSame(['test_api_key', 'payment_mode'], array_keys(json_decode($refused['body'], true)['errors']));
        $this->assertSame(0, $this->countStoredKeys());

        $tested = json_decode($this->test($session, $csrf, ['test_mode' => 'active'], self::$pinned)['body'], true);
        $this->assertTrue($tested['ok'], 'the environment key is the one tested');
    }

    public function testMethodsAreSavedFromWhatMollieOffers(): void
    {
        [$session, $csrf] = $this->accounts->signIn([ShopModule::PAYMENTS_MANAGE]);
        $this->save($session, $csrf, ['test_api_key' => self::TEST_KEY]);

        $page = self::$shop->request('GET', '/admin/payments.php', $session)['body'];
        $this->assertStringContainsString('name="payment_methods[]" value="ideal" checked', $page);
        $this->assertStringContainsString('name="payment_methods[]" value="creditcard" checked', $page);

        $refused = $this->save($session, $csrf, ['payment_methods_submitted' => '1', 'payment_methods' => ['ideal', 'klarna']]);
        $this->assertSame(422, $refused['status']);
        $this->assertArrayHasKey('payment_methods', json_decode($refused['body'], true)['errors']);

        $this->assertSame(200, $this->save($session, $csrf, ['payment_methods_submitted' => '1', 'payment_methods' => ['creditcard']])['status']);
        SiteSettings::clearCache();
        $this->assertSame(['creditcard'], ShopPaymentMethods::enabled());
    }
}
