<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * A payment the provider just started: the id the order keeps
 * (`orders.mollie_payment_id`) and the address the customer is sent to.
 */
final class CreatedPayment
{
    public function __construct(
        public readonly string $id,
        public readonly string $checkoutUrl,
    ) {
    }
}
