<?php

declare(strict_types=1);

namespace Tests\Service\Payment;

use App\Database;
use App\Service\Payment\MollieConfiguration;
use App\Service\Payment\PaymentProviderException;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * Where a payment's Mollie key comes from (App\Service\Payment\MollieConfiguration):
 *
 *  - MOLLIE_API_KEY in the environment pins the shop to it — mode from its
 *    prefix, no other key, nothing stored consulted — so an installation
 *    from before Shop → Betalingen keeps paying without the CMS;
 *  - the .env.example placeholder is no key; a value of the wrong shape is
 *    configured but unusable;
 *  - without it: the keys stored in the CMS and the chosen mode, test until
 *    live is chosen, and a stored live key alone never switches;
 *  - a stored key that can no longer be decrypted fails closed;
 *  - the masked form shows prefix and last four characters only.
 */
final class MollieConfigurationTest extends TestCase
{
    private const TEST_KEY = 'test_aaaaaaaaaabbbbbbbbbbcccccccccc1234';
    private const LIVE_KEY = 'live_ddddddddddeeeeeeeeeeffffffffff5678';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mygdala-mollieconfig-' . bin2hex(random_bytes(6));
        $this->clearStoredKeys();
    }

    protected function tearDown(): void
    {
        $this->clearStoredKeys();
        SiteSettings::overrideForTests(null);
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

    private function configuration(string $environmentKey = ''): MollieConfiguration
    {
        return new MollieConfiguration(['MOLLIE_API_KEY' => $environmentKey, 'APP_KEY' => '', 'SECRETS_STORAGE_PATH' => $this->directory]);
    }

    public function testTheEnvironmentKeyPinsTheShopAndItsPrefixIsTheMode(): void
    {
        $this->configuration()->storeKey(MollieConfiguration::MODE_TEST, self::TEST_KEY);
        SiteSettings::overrideForTests([MollieConfiguration::MODE_SETTING => 'test']);

        $pinned = $this->configuration(self::LIVE_KEY);

        $this->assertTrue($pinned->isPinnedByEnvironment());
        $this->assertSame(MollieConfiguration::SOURCE_ENVIRONMENT, $pinned->source());
        $this->assertSame('live', $pinned->activeMode(), 'the key decides, not the stored mode');
        $this->assertSame(self::LIVE_KEY, $pinned->activeKey());
        $this->assertNull($pinned->keyFor('test'), 'a stored key is not consulted');
        $this->assertNull($pinned->otherModeKey());
    }

    public function testThePlaceholderIsNoKey(): void
    {
        $configuration = $this->configuration('test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

        $this->assertFalse($configuration->isPinnedByEnvironment());
        $this->assertNull($configuration->environmentKey());
        $this->assertNotConfigured(fn () => $configuration->activeKey());
    }

    public function testAnEnvironmentValueOfTheWrongShapeIsConfiguredButNotUsable(): void
    {
        $configuration = $this->configuration('my-mollie-key');

        $this->assertTrue($configuration->isPinnedByEnvironment());
        $this->assertNull($configuration->activeMode());
        $this->assertNotConfigured(fn () => $configuration->activeKey());
    }

    public function testNothingConfiguredMeansNoKey(): void
    {
        $configuration = $this->configuration();

        $this->assertSame(MollieConfiguration::SOURCE_CMS, $configuration->source());
        $this->assertSame('test', $configuration->activeMode());
        $this->assertNull($configuration->storedKey('test'));
        $this->assertNotConfigured(fn () => $configuration->activeKey());
    }

    public function testStoredKeysFollowTheChosenModeAndALiveKeyAloneNeverSwitches(): void
    {
        $configuration = $this->configuration();
        $configuration->storeKey('test', self::TEST_KEY);
        $configuration->storeKey('live', self::LIVE_KEY);

        $this->assertSame('test', $configuration->storedMode());
        $this->assertSame(self::TEST_KEY, $configuration->activeKey());
        $this->assertSame(self::LIVE_KEY, $configuration->otherModeKey());

        SiteSettings::overrideForTests([MollieConfiguration::MODE_SETTING => 'live']);
        $this->assertSame('live', $configuration->activeMode());
        $this->assertSame(self::LIVE_KEY, $configuration->activeKey());
        $this->assertSame(self::TEST_KEY, $configuration->otherModeKey());

        SiteSettings::overrideForTests([MollieConfiguration::MODE_SETTING => 'anything else']);
        $this->assertSame('test', $configuration->activeMode(), 'only "live" is live');
    }

    public function testAKeyIsOnlyStoredInTheSlotOfItsOwnMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->configuration()->storeKey('test', self::LIVE_KEY);
    }

    public function testAStoredKeyThatCannotBeDecryptedFailsClosed(): void
    {
        $this->configuration()->storeKey('test', self::TEST_KEY);
        unlink($this->directory . '/secrets/app.key');

        $configuration = $this->configuration();
        $this->assertFalse($configuration->storedKey('test')['readable'] ?? true);
        $this->assertNotConfigured(fn () => $configuration->activeKey());
        $this->assertNull($configuration->otherModeKey());
    }

    public function testTheMaskShowsThePrefixAndTheLastFourOnly(): void
    {
        $this->assertSame('test_••••••••1234', MollieConfiguration::mask(self::TEST_KEY));
        $this->assertSame('live_••••••••5678', MollieConfiguration::mask(self::LIVE_KEY));
        $this->assertSame('••••••••', MollieConfiguration::mask('not a key at all'));

        $this->configuration()->storeKey('live', self::LIVE_KEY);
        $this->assertSame('live_••••••••5678', $this->configuration()->storedKey('live')['hint'] ?? null);
    }

    public function testTheKeyShapeIsMolliesOwn(): void
    {
        $this->assertTrue(MollieConfiguration::isKeyFormat(self::TEST_KEY));
        $this->assertSame('live', MollieConfiguration::modeOfKey(self::LIVE_KEY));
        foreach (['', 'test_short', 'prod_' . str_repeat('a', 30), ' test_' . str_repeat('a', 30), 'test_' . str_repeat('a', 29) . ' '] as $notAKey) {
            $this->assertFalse(MollieConfiguration::isKeyFormat($notAKey), var_export($notAKey, true));
        }
    }

    private function assertNotConfigured(callable $call): void
    {
        try {
            $call();
            $this->fail('expected NOT_CONFIGURED');
        } catch (PaymentProviderException $e) {
            $this->assertSame(PaymentProviderException::NOT_CONFIGURED, $e->kind);
        }
    }
}
