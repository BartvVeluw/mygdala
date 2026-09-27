<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * The one contract between the Shop and whoever takes the money.
 *
 * Mygdala has one provider, Mollie (MolliePaymentProvider). The checkout, the
 * webhook and the return page talk to this interface instead of to Mollie's
 * SDK, so a second provider later is a second implementation of these four
 * methods, not a second checkout, a second order model or a second webhook
 * flow. PaymentProviders::active() is the one place that says which
 * implementation runs.
 *
 * ONLY WHAT IS USED TODAY. Every method has a caller:
 *
 *   isConfigured()      api/checkout.php, before it creates an order
 *   createPayment()     api/checkout.php
 *   fetchPayment()      api/mollie-webhook.php and api/order-status.php,
 *                       whose snapshot App\Service\OrderPaymentSync applies
 *   availableMethods()  the Betalingen screen (admin/payments.php); never the
 *                       storefront, which reads the owner's stored choice
 *                       (ShopPaymentMethods) so a page view costs no API call
 *
 * Deliberately NOT here:
 *
 *   - refunds. Mygdala never creates one. A refund is made in the provider's
 *     own dashboard and arrives with fetchPayment()'s snapshot, which
 *     OrderPaymentSync mirrors into order_refunds;
 *   - keys, test and live mode, and the connection test. What a "key" is
 *     differs per provider, so that is MollieConfiguration's business and
 *     the Betalingen screen's, not the checkout's.
 *
 * Every method that talks to the provider throws PaymentProviderException
 * and nothing else, so a caller decides on its `kind` (temporary or final)
 * without knowing an SDK's exception tree.
 */
interface PaymentProvider
{
    /**
     * Whether a payment could be started right now: a key for the active
     * mode that can be read and has the right shape. No network call, so
     * the checkout can ask before it stores an order.
     */
    public function isConfigured(): bool;

    /**
     * Starts the payment for a stored order and returns where the customer
     * pays.
     *
     * @throws PaymentProviderException
     */
    public function createPayment(PaymentRequest $request): CreatedPayment;

    /**
     * The payment as the provider knows it now: always asked again, never
     * taken from a request.
     *
     * $mode is the mode the ORDER recorded when the payment was created
     * ('test' or 'live', `orders.payment_mode`): the payment is then asked for
     * with that mode's credentials only, whatever mode the shop is in now.
     * Null is an order from before the mode was recorded; only then may a
     * provider try the credentials it has one after the other.
     *
     * @throws PaymentProviderException NOT_FOUND when the credentials asked do not know it,
     *                                  NOT_CONFIGURED when there are none for $mode
     */
    public function fetchPayment(string $paymentId, ?string $mode = null): PaymentSnapshot;

    /**
     * The payment methods the provider offers for the active configuration,
     * named in $language (a website language code such as "nl").
     *
     * @return list<PaymentMethodOption>
     *
     * @throws PaymentProviderException
     */
    public function availableMethods(string $language): array;
}
