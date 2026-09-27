<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OrderRepository;

/**
 * The payment api/checkout.php asks Mollie to create for an order, built from
 * the stored order row.
 *
 * Pure: no database, no HTTP and no settings read — its one caller,
 * App\Service\Payment\MolliePaymentProvider, hands in the site name, the
 * configured base URL (App\Service\AppUrl, never the request's Host header)
 * and the payment method — so what ends up on a customer's bank statement is
 * unit-tested (Tests\Service\MolliePaymentDataTest).
 * The same split as OrderCsvExport, which formats the rows
 * admin/orders-export.php fetches.
 *
 * The order number in the description and in the reconciliation metadata is
 * the one stored on the order (OrderRepository::orderNumber()), never one
 * rebuilt from today's prefix setting, so the payment carries the number the
 * e-mails, the admin and the invoice carry, for good.
 *
 * Only internal references go to Mollie: no customer name, e-mail or address,
 * which it does not need for reconciliation.
 */
final class MolliePaymentData
{
    /** Where Mollie reports a payment's changes, under the site's base URL. */
    public const WEBHOOK_PATH = '/api/mollie-webhook.php';

    /** The webhook address for $baseUrl: what each payment carries and the Betalingen screen shows. */
    public static function webhookUrl(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . self::WEBHOOK_PATH;
    }

    /**
     * @param array<string, mixed> $order an `orders` row as OrderRepository::findById() returns it
     * @param string $method a Mollie payment method id, already mapped from the checkout's own choice
     * @param bool $withWebhook false where Mollie cannot reach this host (localhost)
     * @return array{amount: array{currency: string, value: string}, description: string, redirectUrl: string, method: string, metadata: array{order_id: int, order_number: string}, webhookUrl?: string}
     */
    public static function forOrder(
        array $order,
        string $siteName,
        string $baseUrl,
        string $method,
        bool $withWebhook,
        ?string $language = null
    ): array
    {
        $orderId = (int) $order['id'];
        $orderNumber = OrderRepository::orderNumber($order);

        $payment = [
            'amount' => [
                'currency' => (string) $order['currency'],
                // The order's own stored total: DECIMAL(10,2), so already the
                // exact two-decimal string Mollie requires.
                'value' => (string) $order['total'],
            ],
            // What the customer sees on their bank statement: the site's own
            // name, not one written into the code.
            'description' => $siteName . ' — bestelling ' . $orderNumber,
            // Where the customer comes back to, IN THE LANGUAGE THEY WERE
            // CHECKING OUT IN (docs/multilingual/ROUTING.md). Somebody who
            // paid on /en/checkout.php must not be returned to a Dutch order
            // page; the prefix is the only thing language adds to this URL,
            // because the order is identified by its id.
            'redirectUrl' => $baseUrl . \App\Service\Routing\LocalizedUrl::path(
                '/bestelling-status.php?order=' . $orderId,
                $language
            ),
            'method' => $method,
            'metadata' => ['order_id' => $orderId, 'order_number' => $orderNumber],
        ];

        if ($withWebhook) {
            $payment['webhookUrl'] = self::webhookUrl($baseUrl);
        }

        return $payment;
    }
}
