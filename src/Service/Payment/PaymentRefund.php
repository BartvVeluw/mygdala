<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * One refund of a payment, as the provider reports it. Mirrored into
 * `order_refunds` by App\Service\OrderPaymentSync, keyed by its id.
 */
final class PaymentRefund
{
    public function __construct(
        public readonly string $id,
        public readonly float $amount,
        public readonly string $status,
        public readonly ?string $description,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }
}
