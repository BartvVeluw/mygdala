<?php

namespace App\Service;

use App\Repository\OrderRepository;
use Mollie\Api\Resources\Payment;

/**
 * Applies a Mollie payment's current status to our order record. Shared by
 * the webhook (api/mollie-webhook.php) and the return page's status lookup
 * (api/order-status.php) so both use the exact same status mapping and,
 * being idempotent, can both safely update the same order without racing.
 */
class OrderPaymentSync
{
    private OrderRepository $orders;
    private OrderConfirmationService $confirmations;
    private InvoiceService $invoices;

    public function __construct(
        ?OrderRepository $orders = null,
        ?OrderConfirmationService $confirmations = null,
        ?InvoiceService $invoices = null
    ) {
        $this->orders = $orders ?? new OrderRepository();
        $this->confirmations = $confirmations ?? new OrderConfirmationService();
        $this->invoices = $invoices ?? new InvoiceService();
    }

    /**
     * Maps Mollie's payment status to our own simplified order status.
     */
    public static function mapStatus(Payment $payment): string
    {
        if ($payment->isPaid()) {
            return 'paid';
        }
        if ($payment->isCanceled()) {
            return 'canceled';
        }
        if ($payment->isExpired()) {
            return 'expired';
        }
        if ($payment->isFailed()) {
            return 'failed';
        }

        // open, pending, authorized, ...
        return 'pending';
    }

    /**
     * Syncs the order that belongs to $payment. Returns the (possibly
     * updated) order row, or null if no order matches this payment id.
     */
    public function sync(Payment $payment): ?array
    {
        $order = $this->orders->findByMolliePaymentId($payment->id);
        if ($order === null) {
            return null;
        }

        $localStatus = self::mapStatus($payment);

        if ($order['status'] !== $localStatus || $order['mollie_status'] !== $payment->status) {
            $this->orders->updateStatusFromMollie((int) $order['id'], $localStatus, $payment->status);
            $order['status'] = $localStatus;
            $order['mollie_status'] = $payment->status;
        }

        // Attempted on every sync (not just on a fresh transition to "paid") so a failed
        // send/issue gets retried by the next webhook call or status-page check — both
        // services' own idempotency guards make this safe to call repeatedly. The invoice
        // is issued first so the confirmation email can attach its PDF (see
        // OrderConfirmationService, which looks the invoice up by order id).
        if ($localStatus === 'paid') {
            $this->invoices->issueForOrderIfNeeded((int) $order['id']);
            $this->confirmations->sendForOrderIfNeeded((int) $order['id']);
        }

        // A refund doesn't change the payment's own status (it stays "paid" —
        // Mollie models refunds as a separate sub-resource), so this is checked
        // independently of $localStatus above. Mollie calls the same payment
        // webhook again for every refund status change, so this runs on every
        // such delivery; upsertRefund()/setRefundedAmount() are both safe to
        // call repeatedly with the same data.
        if ($payment->hasRefunds()) {
            $this->syncRefunds($payment, (int) $order['id']);
        }

        return $order;
    }

    /**
     * Mirrors Mollie's refunds for this payment into order_refunds, and sets
     * orders.refunded_amount to Mollie's own total — never a sum we compute
     * ourselves, so it can't drift from what Mollie considers refunded.
     * Failures are logged and swallowed: a refund-sync hiccup must never
     * break the payment status sync above (which already succeeded), and
     * Mollie will call the webhook again on the refund's next status change.
     */
    private function syncRefunds(Payment $payment, int $orderId): void
    {
        try {
            $this->orders->setRefundedAmount($orderId, $payment->getAmountRefunded());

            foreach ($payment->refunds() as $refund) {
                $this->orders->upsertRefund(
                    $orderId,
                    $refund->id,
                    (float) $refund->amount->value,
                    $refund->status,
                    $refund->description,
                    new \DateTimeImmutable($refund->createdAt)
                );
            }
        } catch (\Throwable $e) {
            error_log('[OrderPaymentSync] Failed to sync refunds for order ' . $orderId . ': ' . $e->getMessage());
        }
    }
}
