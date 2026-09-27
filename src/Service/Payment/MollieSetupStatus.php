<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * The one status card at the top of Shop → Betalingen: where payments stand
 * right now, proven with one read-only call to Mollie rather than guessed
 * from what is stored.
 *
 *   not_configured  no key for the active mode yet
 *   test            a working test key: test payments, no real money
 *   live            a working live key: real payments
 *   problem         a key exists but does not work: Mollie refuses it,
 *                   cannot be reached, it has the wrong shape, or it can no
 *                   longer be decrypted
 *
 * Never a remembered "verified": every page view asks again, so a key that
 * was replaced or revoked is never shown as working.
 */
final class MollieSetupStatus
{
    public const NOT_CONFIGURED = 'not_configured';
    public const TEST = 'test';
    public const LIVE = 'live';
    public const PROBLEM = 'problem';

    private function __construct(
        public readonly string $state,
        public readonly ?string $mode,
        public readonly string $source,
        public readonly ?MollieConnectionResult $connection,
    ) {
    }

    public static function current(MollieConfiguration $configuration, MolliePaymentProvider $provider, string $language): self
    {
        $source = $configuration->source();
        $mode = $configuration->activeMode();

        if ($source === MollieConfiguration::SOURCE_ENVIRONMENT) {
            $result = MollieConnectionResult::check(
                $provider,
                $configuration,
                $configuration->environmentKey(),
                $mode ?? MollieConfiguration::MODE_TEST,
                $language
            );
        } else {
            $mode ??= MollieConfiguration::MODE_TEST;
            $stored = $configuration->storedKey($mode);

            if ($stored === null) {
                return new self(self::NOT_CONFIGURED, $mode, $source, null);
            }

            $result = $stored['readable']
                ? MollieConnectionResult::check($provider, $configuration, null, $mode, $language)
                : MollieConnectionResult::without(MollieConnectionResult::UNREADABLE, $mode);
        }

        if (!$result->ok()) {
            return new self(self::PROBLEM, $mode, $source, $result);
        }

        return new self($result->mode === MollieConfiguration::MODE_LIVE ? self::LIVE : self::TEST, $result->mode, $source, $result);
    }

    /**
     * What Mollie offers for the active key, when the status call succeeded;
     * empty otherwise. The Betalingen screen lists these without asking
     * Mollie a second time.
     *
     * @return list<PaymentMethodOption>
     */
    public function methods(): array
    {
        return $this->connection !== null && $this->connection->ok() ? $this->connection->methods : [];
    }
}
