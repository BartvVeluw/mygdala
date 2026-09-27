<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * What api/checkout.php asks a PaymentProvider to start: the order as it was
 * just stored, the payment method the customer chose, and the language they
 * were checking out in (so they come back to the order page in it).
 *
 * The amount is not a field: the provider reads it from the stored order,
 * which the checkout priced from the database, so a request can never name
 * one (MODULES.md, "Shop").
 */
final class PaymentRequest
{
    /**
     * @param array<string, mixed> $order  an `orders` row as OrderRepository::findById() returns it
     * @param string               $method a provider method id the owner switched on (ShopPaymentMethods)
     */
    public function __construct(
        public readonly array $order,
        public readonly string $method,
        public readonly string $language,
    ) {
    }
}
