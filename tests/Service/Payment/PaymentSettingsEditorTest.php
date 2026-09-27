<?php

declare(strict_types=1);

namespace Tests\Service\Payment;

use App\Database;
use App\Repository\SiteSettingRepository;
use App\Service\Payment\MollieConfiguration;
use App\Service\Payment\MolliePaymentProvider;
use App\Service\Payment\PaymentSettingsEditor;
use App\Service\Payment\ShopPaymentMethods;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMollie;

/**
 * One save of Shop → Betalingen (App\Service\Payment\PaymentSettingsEditor),
 * on the test database, a key directory of its own and a fake Mollie:
 *
 *  - keys: stored sealed and masked; the wrong shape or the wrong field is
 *    refused; an empty field keeps what is stored;
 *  - live: never without a live key, never without Mollie accepting it at the
 *    moment of the save, and a refused check writes nothing at all;
 *  - a live shop that took payments needs the confirmation to replace its
 *    live key;
 *  - the environment pins the key: no key or mode is saved, methods still are;
 *  - methods: one or several, never none, only switched on when Mollie offers
 *    them now, an old one that Mollie dropped may stay, and while Mollie
 *    cannot be asked nothing new goes on; the names are stored per language;
 *  - nothing that is logged names a key.
 */
final class PaymentSettingsEditorTest extends TestCase
{
    private const TEST_KEY = 'test_editoreditoreditoreditor1234567';
    private const OTHER_TEST_KEY = 'test_othertestkeyothertestkey9876543';
    private const LIVE_KEY = 'live_editoreditoreditoreditor7654321';
    private const REFUSED_LIVE_KEY = 'live_refusedrefusedrefusedrefused000';

    private string $directory;
    private string $scenario;

    /** @var array<string, string|null> */
    private array $settingsBefore = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mygdala-editor-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->scenario = $this->directory . '/scenario.json';
        FakeMollie::write($this->scenario, [
            'keys' => [self::TEST_KEY => 'ok', self::OTHER_TEST_KEY => 'ok', self::LIVE_KEY => 'ok', self::REFUSED_LIVE_KEY => 'unauthorized'],
            'methods' => [
                'test' => [
                    ['id' => 'ideal', 'description' => 'iDEAL'],
                    ['id' => 'creditcard', 'description' => ['nl_NL' => 'Creditcard', 'en_US' => 'Credit card']],
                    ['id' => 'bancontact', 'description' => 'Bancontact'],
                ],
                'live' => [['id' => 'ideal', 'description' => 'iDEAL']],
            ],
        ]);
        FakeMollie::install($this->scenario);

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
        FakeMollie::uninstall();
        $this->clearStoredKeys();

        $db = Database::connection();
        foreach ($this->settingsBefore as $key => $value) {
            $db->prepare('DELETE FROM site_settings WHERE setting_key = :key')->execute(['key' => $key]);
            if ($value !== null) {
                (new SiteSettingRepository())->upsertMany([$key => $value]);
            }
        }
        SiteSettings::clearCache();

        foreach (array_merge(glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [], glob($this->directory . '/secrets/{,.}*', GLOB_BRACE) ?: []) as $file) {
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

    private function configuration(string $environmentKey = ''): MollieConfiguration
    {
        return new MollieConfiguration(['MOLLIE_API_KEY' => $environmentKey, 'APP_KEY' => '', 'SECRETS_STORAGE_PATH' => $this->directory]);
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0: array<string, string>, 1: list<string>} errors, and the events when it was saved
     */
    private function save(array $post, string $environmentKey = '', bool $shopHasPayments = false): array
    {
        $configuration = $this->configuration($environmentKey);
        $editor = new PaymentSettingsEditor($post, $configuration, new MolliePaymentProvider($configuration, 'https://winkel.example.nl', 'Winkel'), 'nl', static fn (): bool => $shopHasPayments);

        $errors = $editor->validate();
        if ($errors !== []) {
            return [$errors, []];
        }

        $db = Database::connection();
        $db->beginTransaction();
        $events = $editor->save(new SiteSettingRepository($db));
        $db->commit();
        SiteSettings::clearCache();

        return [[], $events];
    }

    public function testATestKeyIsStoredSealedAndMaskedAndAnEmptyFieldKeepsIt(): void
    {
        [$errors, $events] = $this->save(['test_api_key' => '  ' . self::TEST_KEY . "\n", 'payment_mode' => 'test']);

        $this->assertSame([], $errors);
        $this->assertSame(['test API key stored'], $events);
        $this->assertSame(self::TEST_KEY, $this->configuration()->keyFor('test'), 'pasted with spaces and a newline, stored trimmed');
        $this->assertSame('test_••••••••4567', $this->configuration()->storedKey('test')['hint'] ?? null);

        [$errors, $events] = $this->save(['test_api_key' => '', 'live_api_key' => '', 'payment_mode' => 'test']);
        $this->assertSame([], $errors);
        $this->assertSame([], $events, 'nothing changed');
        $this->assertSame(self::TEST_KEY, $this->configuration()->keyFor('test'), 'an empty field never erases a key');

        [, $events] = $this->save(['test_api_key' => self::OTHER_TEST_KEY]);
        $this->assertSame(['test API key replaced'], $events);
        $this->assertSame(self::OTHER_TEST_KEY, $this->configuration()->keyFor('test'));
    }

    public function testAKeyOfTheWrongShapeOrInTheWrongFieldIsRefused(): void
    {
        [$errors] = $this->save(['test_api_key' => 'test_123', 'live_api_key' => self::TEST_KEY]);

        $this->assertSame(['test_api_key', 'live_api_key'], array_keys($errors));
        $this->assertStringContainsString('geen Mollie API-sleutel', $errors['test_api_key']);
        $this->assertStringContainsString('testsleutel', $errors['live_api_key']);
        $this->assertNull($this->configuration()->storedKey('test'));
        $this->assertNull($this->configuration()->storedKey('live'));

        [$errors] = $this->save(['test_api_key' => self::LIVE_KEY]);
        $this->assertStringContainsString('live-sleutel', $errors['test_api_key']);

        [$errors] = $this->save(['payment_mode' => 'production']);
        $this->assertArrayHasKey('payment_mode', $errors);
    }

    public function testLiveNeedsALiveKeyThatMollieAcceptsAtTheMomentOfSaving(): void
    {
        $this->save(['test_api_key' => self::TEST_KEY]);

        [$errors] = $this->save(['payment_mode' => 'live']);
        $this->assertStringContainsString('live-sleutel', $errors['payment_mode'] ?? '');
        $this->assertSame('test', $this->configuration()->storedMode());

        [$errors] = $this->save(['live_api_key' => self::REFUSED_LIVE_KEY, 'payment_mode' => 'live']);
        $this->assertStringContainsString('Mollie weigert', $errors['live_api_key'] ?? '');
        $this->assertNull($this->configuration()->storedKey('live'), 'a refused check writes nothing, not even the key');
        $this->assertSame('test', $this->configuration()->storedMode());

        $before = count(FakeMollie::requests($this->scenario));
        [$errors, $events] = $this->save(['live_api_key' => self::LIVE_KEY, 'payment_mode' => 'live']);
        $this->assertSame([], $errors);
        $this->assertSame(['live API key stored', 'payment mode changed from test to live'], $events);
        $this->assertSame('live', $this->configuration()->activeMode());
        $this->assertSame('live', FakeMollie::requests($this->scenario)[$before]['mode'] ?? null, 'the live key itself was tested');

        [$errors, $events] = $this->save(['payment_mode' => 'live']);
        $this->assertSame([], $errors);
        $this->assertSame([], $events, 'staying live with the same key needs no new check');
    }

    public function testAStoredLiveKeyAloneNeverSwitchesToLive(): void
    {
        [$errors] = $this->save(['live_api_key' => self::LIVE_KEY]);

        $this->assertSame([], $errors);
        $this->assertSame('test', $this->configuration()->activeMode());
    }

    public function testALiveShopThatTookPaymentsConfirmsReplacingItsLiveKey(): void
    {
        $this->save(['live_api_key' => self::LIVE_KEY, 'payment_mode' => 'live']);
        $replacement = 'live_replacementreplacementreplace1';
        $scenario = json_decode((string) file_get_contents($this->scenario), true);
        $scenario['keys'][$replacement] = 'ok';
        FakeMollie::write($this->scenario, $scenario);

        [$errors] = $this->save(['live_api_key' => $replacement], '', true);
        $this->assertArrayHasKey('confirm_live_key_replace', $errors);
        $this->assertSame(self::LIVE_KEY, $this->configuration()->keyFor('live'));

        [$errors, $events] = $this->save(['live_api_key' => $replacement, 'confirm_live_key_replace' => '1'], '', true);
        $this->assertSame([], $errors);
        $this->assertSame(['live API key replaced'], $events);
        $this->assertSame($replacement, $this->configuration()->keyFor('live'));

        [$errors] = $this->save(['live_api_key' => self::LIVE_KEY]);
        $this->assertSame([], $errors, 'without payments no confirmation is asked');
    }

    public function testTheEnvironmentKeyCannotBeReplacedOrErasedButMethodsCanBeChosen(): void
    {
        [$errors] = $this->save(['test_api_key' => self::OTHER_TEST_KEY, 'live_api_key' => self::LIVE_KEY, 'payment_mode' => 'live'], self::TEST_KEY);

        $this->assertSame(['test_api_key', 'live_api_key', 'payment_mode'], array_keys($errors));
        $this->assertNull($this->configuration()->storedKey('test'));
        $this->assertNull($this->configuration()->storedKey('live'));

        [$errors, $events] = $this->save(['payment_methods_submitted' => '1', 'payment_methods' => ['bancontact', 'ideal']], self::TEST_KEY);
        $this->assertSame([], $errors);
        $this->assertSame(['payment methods changed from ideal,creditcard to bancontact,ideal'], $events);
        $this->assertSame(['bancontact', 'ideal'], ShopPaymentMethods::enabled());
    }

    public function testMethodsOneSeveralAndNeverNone(): void
    {
        $this->save(['test_api_key' => self::TEST_KEY]);

        [$errors] = $this->save(['payment_methods_submitted' => '1']);
        $this->assertArrayHasKey('payment_methods', $errors);
        $this->assertSame(ShopPaymentMethods::DEFAULT, ShopPaymentMethods::enabled());

        [$errors] = $this->save(['payment_methods_submitted' => '1', 'payment_methods' => ['ideal']]);
        $this->assertSame([], $errors);
        $this->assertSame(['ideal'], ShopPaymentMethods::enabled());

        [$errors] = $this->save(['payment_methods_submitted' => '1', 'payment_methods' => ['creditcard', 'bancontact', 'ideal']]);
        $this->assertSame([], $errors);
        $this->assertSame(['creditcard', 'bancontact', 'ideal'], ShopPaymentMethods::enabled());
        $this->assertSame(['nl' => 'Creditcard', 'en' => 'Credit card'], ShopPaymentMethods::storedNames()['creditcard'] ?? null, 'named in every website language');

        [$errors] = $this->save(['test_api_key' => '']);
        $this->assertSame([], $errors);
        $this->assertSame(['creditcard', 'bancontact', 'ideal'], ShopPaymentMethods::enabled(), 'a save without the list leaves it alone');
    }

    public function testOnlyWhatMollieOffersNowCanBeSwitchedOn(): void
    {
        $this->save(['test_api_key' => self::TEST_KEY]);

        [$errors] = $this->save(['payment_methods_submitted' => '1', 'payment_methods' => ['ideal', 'klarna']]);
        $this->assertStringContainsString('klarna', $errors['payment_methods'] ?? '');

        [$errors] = $this->save(['payment_methods_submitted' => '1', 'payment_methods' => ['ideal', 'Klarna Pay']]);
        $this->assertArrayHasKey('payment_methods', $errors);
        $this->assertSame(ShopPaymentMethods::DEFAULT, ShopPaymentMethods::enabled());
    }

    public function testAMethodMollieDroppedMayStayAndNothingNewGoesOnWhileMollieCannotBeAsked(): void
    {
        $this->save(['test_api_key' => self::TEST_KEY]);
        $this->save(['payment_methods_submitted' => '1', 'payment_methods' => ['ideal', 'bancontact']]);

        $scenario = json_decode((string) file_get_contents($this->scenario), true);
        $scenario['methods']['test'] = [['id' => 'ideal', 'description' => 'iDEAL']];
        FakeMollie::write($this->scenario, $scenario);

        [$errors] = $this->save(['payment_methods_submitted' => '1', 'payment_methods' => ['bancontact', 'ideal']]);
        $this->assertSame([], $errors, 'bancontact was on already: it may stay');
        $this->assertSame(['bancontact', 'ideal'], ShopPaymentMethods::enabled());

        $scenario['keys'][self::TEST_KEY] = 'down';
        FakeMollie::write($this->scenario, $scenario);

        [$errors] = $this->save(['payment_methods_submitted' => '1', 'payment_methods' => ['ideal', 'creditcard']]);
        $this->assertStringContainsString('niet te bereiken', $errors['payment_methods'] ?? '');

        [$errors] = $this->save(['payment_methods_submitted' => '1', 'payment_methods' => ['ideal']]);
        $this->assertSame([], $errors, 'switching off needs no Mollie');
        $this->assertSame(['ideal'], ShopPaymentMethods::enabled());
        $this->assertSame(['nl' => 'iDEAL', 'en' => 'iDEAL'], ShopPaymentMethods::storedNames()['ideal'] ?? null, 'the names stored before are kept');
    }

    public function testNoEventNamesAKey(): void
    {
        [, $events] = $this->save(['test_api_key' => self::TEST_KEY, 'live_api_key' => self::LIVE_KEY, 'payment_mode' => 'live', 'payment_methods_submitted' => '1', 'payment_methods' => ['ideal']]);

        $this->assertNotSame([], $events);
        foreach ($events as $event) {
            $this->assertStringNotContainsString('editoreditor', $event);
            $this->assertDoesNotMatchRegularExpression('/(test|live)_\w{6,}/', $event);
        }
    }
}
