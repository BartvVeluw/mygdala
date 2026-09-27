<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * Which PaymentProvider the Shop pays with. There is one, Mollie, and this
 * is the one line that says so: the checkout, the webhook and the return
 * page ask here instead of naming Mollie themselves.
 *
 * Not a registry and not a setting. A second provider would be chosen here,
 * by code, when it exists; nothing a request or a database row says can
 * name a class.
 */
final class PaymentProviders
{
    public static function active(): PaymentProvider
    {
        return new MolliePaymentProvider();
    }
}
