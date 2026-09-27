<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * A payment as the provider reported it just now, in the Shop's own words.
 *
 * `status` is one of the five words `orders.status` holds. The provider maps
 * its own statuses onto them (MolliePaymentProvider::mapStatus()), so
 * App\Service\OrderPaymentSync never learns a provider's vocabulary.
 * `providerStatus` is the provider's own word, kept in `orders.mollie_status`
 * so the order screen can always show exactly what the provider last said.
 *
 * The refunds are fetched only when asked for: they are a second request at
 * the provider, and OrderPaymentSync asks for them only when there are any
 * and inside its own try, so a refund that cannot be read never stops the
 * payment status from being stored.
 */
final class PaymentSnapshot
{
    public const PAID = 'paid';
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const CANCELED = 'canceled';
    public const EXPIRED = 'expired';

    /** @var \Closure(): list<PaymentRefund> */
    private \Closure $refunds;

    /**
     * @param \Closure(): list<PaymentRefund> $refunds throws PaymentProviderException
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $providerStatus,
        public readonly bool $hasRefunds,
        public readonly float $amountRefunded,
        \Closure $refunds,
    ) {
        $this->refunds = $refunds;
    }

    /**
     * @return list<PaymentRefund>
     *
     * @throws PaymentProviderException
     */
    public function refunds(): array
    {
        return ($this->refunds)();
    }
}
