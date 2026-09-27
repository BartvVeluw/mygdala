<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * Everything that can go wrong at a payment provider, in five kinds a caller
 * can act on without knowing the provider's SDK:
 *
 *   NOT_CONFIGURED       no usable key for the active mode: none at all, one
 *                        with the wrong shape, or one the application key can
 *                        no longer decrypt (App\Service\Secrets\SecretStore)
 *   INVALID_CREDENTIALS  the provider refused the key (401/403)
 *   NOT_FOUND            the provider does not know what was asked for
 *   TEMPORARY            the provider could not be reached or had a problem
 *                        of its own: a network error, a timeout, 429, 5xx
 *   REJECTED             the provider understood and said no, for good
 *                        (a 4xx that is none of the above)
 *
 * THE MESSAGE NEVER CARRIES A KEY. It is written here, from the kind, the
 * SDK exception's class, the HTTP status and the provider's own short
 * explanation, and anything shaped like an API key is replaced before it is
 * stored. The SDK exception is deliberately not kept as `previous`: nothing
 * that prints this exception, a log line included, can reach what the SDK
 * put in its own.
 */
final class PaymentProviderException extends \RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const INVALID_CREDENTIALS = 'invalid_credentials';
    public const NOT_FOUND = 'not_found';
    public const TEMPORARY = 'temporary';
    public const REJECTED = 'rejected';

    public function __construct(
        public readonly string $kind,
        string $detail = '',
    ) {
        parent::__construct('[' . $kind . ']' . ($detail !== '' ? ' ' . self::redact($detail) : ''));
    }

    /**
     * Whether trying again later can succeed without anybody changing
     * anything: the provider was down or overloaded.
     */
    public function isTemporary(): bool
    {
        return $this->kind === self::TEMPORARY;
    }

    /**
     * $text with every API key or access token in it replaced: "test_…",
     * "live_…" and "access_…" followed by ten or more letters, digits or
     * underscores, wherever it stands (also glued to another word). Short
     * names such as the slot "shop.mollie.test_api_key" stay readable.
     * Public so every log line about a provider can use the same rule.
     */
    public static function redact(string $text): string
    {
        return (string) preg_replace('/(test|live|access)_[A-Za-z0-9_]{10,}/', '$1_[redacted]', $text);
    }
}
