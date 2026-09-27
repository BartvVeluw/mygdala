<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * One payment method a provider offers: its id (what a payment is started
 * with, e.g. "ideal") and its name in the language it was asked for.
 */
final class PaymentMethodOption
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
    }
}
