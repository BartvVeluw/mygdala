<?php

declare(strict_types=1);

namespace App\Service\Payment;

use Dotenv\Dotenv;

/**
 * Which Mollie API key a payment is made with, and whether that is a test
 * key or a live one.
 *
 * THE KEY COMES FROM THE SERVER ENVIRONMENT: `MOLLIE_API_KEY` in .env. The
 * placeholder .env.example carries (`test_xxxx…`) is no key, so an
 * installation that copied it counts as not configured instead of sending a
 * made-up key to Mollie.
 *
 * TEST OR LIVE is the key's own prefix, exactly as Mollie defines it: a
 * `test_` key only ever makes test payments, a `live_` key real ones.
 *
 * Nothing here talks to Mollie. Whether Mollie accepts the key is what
 * MolliePaymentProvider finds out.
 */
final class MollieConfiguration
{
    public const ENVIRONMENT_VARIABLE = 'MOLLIE_API_KEY';

    public const MODE_TEST = 'test';
    public const MODE_LIVE = 'live';

    private static bool $envLoaded = false;

    /**
     * @param array<string, string>|null $environment a test's own variables;
     *                                              null reads .env and $_ENV
     */
    public function __construct(private readonly ?array $environment = null)
    {
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

    /**
     * The mode payments are made in, or null while there is no usable key to
     * tell.
     */
    public function activeMode(): ?string
    {
        $key = $this->environmentKey();

        return $key === null ? null : self::modeOfKey($key);
    }

    /**
     * The key a payment is made with now.
     *
     * @throws PaymentProviderException NOT_CONFIGURED when there is none, or it has the wrong shape
     */
    public function activeKey(): string
    {
        $key = $this->environmentKey();

        if ($key === null) {
            throw new PaymentProviderException(PaymentProviderException::NOT_CONFIGURED, 'no Mollie API key is configured');
        }

        if (!self::isKeyFormat($key)) {
            throw new PaymentProviderException(PaymentProviderException::NOT_CONFIGURED, 'the configured Mollie API key does not have the shape of one');
        }

        return $key;
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
