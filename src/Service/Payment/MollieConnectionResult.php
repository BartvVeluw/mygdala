<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Service\Language\AdminTranslator;

/**
 * The outcome of one connection test on Shop → Betalingen, in words an owner
 * who never set up a payment provider can act on.
 *
 * Seven outcomes, three kinds of trouble kept apart on purpose, because each
 * asks for something else:
 *
 *   ok           Mollie accepted the key; how many payment methods it offers
 *   no_key       misconfiguration: there is nothing to test yet
 *   key_format   misconfiguration: what was typed is no Mollie key at all
 *   unreadable   misconfiguration: a stored key this site can no longer
 *                decrypt (SecretStore); enter it again
 *   refused      invalid credentials: Mollie said no to this key
 *   unreachable  a network or Mollie problem: try again later
 *   unexpected   Mollie said no to the request itself
 *
 * THE KEY IS NEVER IN IT: not in the message, not in the data, not in the
 * log line. A failure is logged with its kind and the provider's redacted
 * explanation (PaymentProviderException), for whoever maintains the site.
 */
final class MollieConnectionResult
{
    public const OK = 'ok';
    public const NO_KEY = 'no_key';
    public const KEY_FORMAT = 'key_format';
    public const UNREADABLE = 'unreadable';
    public const REFUSED = 'refused';
    public const UNREACHABLE = 'unreachable';
    public const UNEXPECTED = 'unexpected';

    /**
     * @param list<PaymentMethodOption> $methods what Mollie offers, on success
     */
    private function __construct(
        public readonly string $result,
        public readonly ?string $mode,
        public readonly array $methods = [],
    ) {
    }

    /**
     * Tests $key (or, when null, the stored key of $mode) with one read-only
     * call. Nothing is stored, created or paid.
     */
    public static function check(
        MolliePaymentProvider $provider,
        MollieConfiguration $configuration,
        #[\SensitiveParameter] ?string $key,
        string $mode,
        string $language,
    ): self {
        if ($key === null) {
            try {
                $key = $configuration->keyFor($mode);
            } catch (PaymentProviderException $e) {
                error_log('[payments] connection test: ' . $e->getMessage());

                return new self(self::UNREADABLE, $mode);
            }

            if ($key === null) {
                return new self(self::NO_KEY, $mode);
            }
        }

        if (!MollieConfiguration::isKeyFormat($key)) {
            return new self(self::KEY_FORMAT, $mode);
        }

        try {
            return new self(self::OK, MollieConfiguration::modeOfKey($key), $provider->checkKey($key, $language));
        } catch (PaymentProviderException $e) {
            error_log('[payments] connection test (' . $mode . '): ' . $e->getMessage());

            return self::fromException($e, $mode);
        }
    }

    /**
     * A failed call with a key that was in hand. NOT_CONFIGURED can then only
     * mean the key's shape (an unreadable stored key never gets this far).
     */
    public static function fromException(PaymentProviderException $e, ?string $mode): self
    {
        return new self(match ($e->kind) {
            PaymentProviderException::INVALID_CREDENTIALS => self::REFUSED,
            PaymentProviderException::TEMPORARY => self::UNREACHABLE,
            PaymentProviderException::NOT_CONFIGURED => self::KEY_FORMAT,
            default => self::UNEXPECTED,
        }, $mode);
    }

    /** A result that needs no call: nothing to test, or a stored key that cannot be opened. */
    public static function without(string $result, ?string $mode): self
    {
        return new self($result, $mode);
    }

    public function ok(): bool
    {
        return $this->result === self::OK;
    }

    /** What the screen says, in the CMS language of whoever is signed in. */
    public function message(): string
    {
        if ($this->ok()) {
            return AdminTranslator::trans(
                $this->mode === MollieConfiguration::MODE_LIVE ? 'payments.result.ok_live' : 'payments.result.ok_test',
                ['count' => (string) count($this->methods)]
            );
        }

        return AdminTranslator::trans('payments.result.' . $this->result);
    }
}
