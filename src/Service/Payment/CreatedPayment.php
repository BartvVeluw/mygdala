<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * A payment the provider just started: the id the order keeps
 * (`orders.mollie_payment_id`), the mode it was started in ('test' or
 * 'live', `orders.payment_mode`) and the address the customer is sent to.
 */
final class CreatedPayment
{
    public function __construct(
        public readonly string $id,
        public readonly string $checkoutUrl,
        public readonly string $mode,
    ) {
    }
}
