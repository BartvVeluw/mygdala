<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Service\Secrets\SecretStore;
use App\Service\Secrets\SecretStoreException;
use App\Service\SiteSettings;
use Dotenv\Dotenv;

/**
 * Which Mollie API key a payment is made with, and whether that is a test
 * key or a live one.
 *
 * THE PRECEDENCE CHAIN, and there is exactly one (the shape of
 * App\Service\AppUrl's):
 *
 *   1. MOLLIE_API_KEY in the server environment (.env). A deployment that
 *      sets it decides: its prefix is the mode, and nothing saved in the CMS
 *      can replace or erase it; the Betalingen screen says "Geconfigureerd
 *      via serveromgeving" and offers no key fields. The placeholder
 *      .env.example carries (`test_xxxx…`) is no key and does not count.
 *   2. The keys stored on Shop → Betalingen: a Test API key and a Live API
 *      key, each sealed in App\Service\Secrets\SecretStore, and the mode the
 *      owner chose (`site_settings.shop_payment_mode`, test until somebody
 *      consciously picks live). Having a live key never switches to live.
 *   3. Nothing: payments cannot be started, and the checkout says so before
 *      it stores an order.
 *
 * TEST OR LIVE follows Mollie's own definition: a `test_` key only ever
 * makes test payments, a `live_` key real ones. A key in the wrong field
 * (a live key typed as the test key) is refused by the screen.
 *
 * FAIL CLOSED. A stored key this installation can no longer decrypt is not
 * a key: activeKey() throws NOT_CONFIGURED, so no payment is ever attempted
 * with something else in its place, and the screen asks for it again.
 *
 * Nothing here talks to Mollie. Whether Mollie accepts a key is what
 * MolliePaymentProvider finds out.
 */
final class MollieConfiguration
{
    public const ENVIRONMENT_VARIABLE = 'MOLLIE_API_KEY';

    public const MODE_TEST = 'test';
    public const MODE_LIVE = 'live';
    public const MODES = [self::MODE_TEST, self::MODE_LIVE];

    /** The mode the owner chose on Shop → Betalingen (step 2 of the chain). */
    public const MODE_SETTING = 'shop_payment_mode';

    /** Where each key of step 2 is sealed (App\Service\Secrets\SecretStore). */
    public const SLOTS = [
        self::MODE_TEST => 'shop.mollie.test_api_key',
        self::MODE_LIVE => 'shop.mollie.live_api_key',
    ];

    public const SOURCE_ENVIRONMENT = 'environment';
    public const SOURCE_CMS = 'cms';

    private static bool $envLoaded = false;

    private ?SecretStore $secrets;

    /**
     * @param array<string, string>|null $environment a test's own variables;
     *                                              null reads .env and $_ENV
     */
    public function __construct(
        private readonly ?array $environment = null,
        ?SecretStore $secrets = null,
    ) {
        $this->secrets = $secrets;
    }

    /**
     * Whether $key has the shape of a Mollie API key: `test_` or `live_` and
     * at least thirty word characters, the rule Mollie's own SDK applies
     * before it sends anything. A first check only; Mollie decides.
     */
    public static function isKeyFormat(string $key): bool
    {
        return preg_match('/^(test|live)_\w{30,}$/', $key) === 1;
    }

    /** 'test' or 'live' for a key of that shape, null for anything else. */
    public static function modeOfKey(string $key): ?string
    {
        if (!self::isKeyFormat($key)) {
            return null;
        }

        return str_starts_with($key, 'live_') ? self::MODE_LIVE : self::MODE_TEST;
    }

    /** The `test_xxxx…` of .env.example: somebody still has to fill it in. */
    public static function isPlaceholder(string $key): bool
    {
        return preg_match('/^(test|live)_x+$/i', $key) === 1;
    }

    /**
     * What a screen shows of a key: its prefix, a row of dots and its last
     * four characters ("test_••••••••abcd"). Enough to tell two keys apart,
     * never enough to use one.
     */
    public static function mask(#[\SensitiveParameter] string $key): string
    {
        $mode = self::modeOfKey($key);
        if ($mode === null) {
            return '••••••••';
        }

        return $mode . '_' . str_repeat('•', 8) . substr($key, -4);
    }

    /**
     * The key the server environment sets, or null when it sets none (unset,
     * empty or the placeholder). A value of the wrong shape IS returned: it
     * is configured, just not usable, and the Betalingen screen says so.
     */
    public function environmentKey(): ?string
    {
        $value = trim($this->variable(self::ENVIRONMENT_VARIABLE));

        if ($value === '' || self::isPlaceholder($value)) {
            return null;
        }

        return $value;
    }

    /** Whether step 1 of the chain answers, leaving the CMS no say over keys or mode. */
    public function isPinnedByEnvironment(): bool
    {
        return $this->environmentKey() !== null;
    }

    public function source(): string
    {
        return $this->isPinnedByEnvironment() ? self::SOURCE_ENVIRONMENT : self::SOURCE_CMS;
    }

    /** The mode the owner chose in the CMS: live only when that was chosen, test otherwise. */
    public function storedMode(): string
    {
        return SiteSettings::get(self::MODE_SETTING) === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_TEST;
    }

    /**
     * The mode payments are made in: the environment key's prefix, or the
     * owner's choice. Null only for an environment key of the wrong shape,
     * which tells no mode.
     */
    public function activeMode(): ?string
    {
        $environmentKey = $this->environmentKey();
        if ($environmentKey !== null) {
            return self::modeOfKey($environmentKey);
        }

        return $this->storedMode();
    }

    /**
     * The key for $mode, or null when there is none. With the environment
     * pinned that is the environment key when it is of that mode.
     *
     * @throws PaymentProviderException NOT_CONFIGURED when a stored key cannot be decrypted
     */
    public function keyFor(string $mode): ?string
    {
        $environmentKey = $this->environmentKey();
        if ($environmentKey !== null) {
            return self::modeOfKey($environmentKey) === $mode ? $environmentKey : null;
        }

        if (!isset(self::SLOTS[$mode])) {
            return null;
        }

        try {
            return $this->secrets()->get(self::SLOTS[$mode]);
        } catch (SecretStoreException $e) {
            throw new PaymentProviderException(PaymentProviderException::NOT_CONFIGURED, 'the stored ' . $mode . ' key cannot be read (' . $e->reason . ')');
        }
    }

    /**
     * The key a payment is made with now.
     *
     * @throws PaymentProviderException NOT_CONFIGURED when there is none, it has the wrong shape, or it cannot be decrypted
     */
    public function activeKey(): string
    {
        $mode = $this->activeMode();
        $key = $mode === null ? $this->environmentKey() : $this->keyFor($mode);

        if ($key === null) {
            throw new PaymentProviderException(PaymentProviderException::NOT_CONFIGURED, 'no Mollie API key is configured for ' . ($mode ?? 'this') . ' mode');
        }

        if (!self::isKeyFormat($key)) {
            throw new PaymentProviderException(PaymentProviderException::NOT_CONFIGURED, 'the configured Mollie API key does not have the shape of one');
        }

        return $key;
    }

    /**
     * The stored key of the mode that is NOT active, when there is a usable
     * one: a payment made before the mode was switched is looked up with it
     * (MolliePaymentProvider::fetchPayment()). Never throws; null with the
     * environment pinned, which has only one key.
     */
    public function otherModeKey(): ?string
    {
        if ($this->isPinnedByEnvironment()) {
            return null;
        }

        $other = $this->storedMode() === self::MODE_LIVE ? self::MODE_TEST : self::MODE_LIVE;

        try {
            $key = $this->keyFor($other);
        } catch (PaymentProviderException) {
            return null;
        }

        return $key !== null && self::modeOfKey($key) === $other ? $key : null;
    }

    /**
     * What the Betalingen screen may show about the stored key of $mode:
     * its masked form, when it was stored, and whether it can still be
     * opened. Null when none is stored. Never the key.
     *
     * @return array{hint: string, updated_at: string, readable: bool, reason: string}|null
     */
    public function storedKey(string $mode): ?array
    {
        return isset(self::SLOTS[$mode]) ? $this->secrets()->describe(self::SLOTS[$mode]) : null;
    }

    /**
     * Seals $key as the stored key of its own mode, replacing the previous
     * one. The caller has checked that it has the shape of a key of $mode.
     *
     * @throws SecretStoreException when no application key exists or can be made
     */
    public function storeKey(string $mode, #[\SensitiveParameter] string $key): void
    {
        if (!isset(self::SLOTS[$mode]) || self::modeOfKey($key) !== $mode) {
            throw new \InvalidArgumentException('A Mollie key is stored in the slot of its own mode only.');
        }

        $this->secrets()->put(self::SLOTS[$mode], $key, self::mask($key));
    }

    private function secrets(): SecretStore
    {
        return $this->secrets ??= new SecretStore(null, $this->environment);
    }

    private function variable(string $name): string
    {
        if ($this->environment !== null) {
            return (string) ($this->environment[$name] ?? '');
        }

        if (!self::$envLoaded) {
            self::$envLoaded = true;
            $root = dirname(__DIR__, 3);
            if (file_exists($root . '/.env')) {
                Dotenv::createImmutable($root)->load();
            }
        }

        return (string) ($_ENV[$name] ?? '');
    }
}
