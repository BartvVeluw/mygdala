<?php

namespace App\Service;

use App\Repository\OrderRepository;
use App\Service\Payment\PaymentSnapshot;

/**
 * Applies a payment's current status to our order record. Shared by the
 * webhook (api/mollie-webhook.php) and the return page's status lookup
 * (api/order-status.php) so both use the exact same rules and, being
 * idempotent, can both safely update the same order without racing.
 *
 * It reads a provider-neutral App\Service\Payment\PaymentSnapshot: the
 * provider (App\Service\Payment\MolliePaymentProvider) has already asked
 * Mollie and mapped Mollie's status onto the Shop's own five words, so
 * nothing here depends on Mollie's SDK. The columns it writes keep their
 * historical names (`mollie_payment_id`, `mollie_status`); they hold the
 * provider's payment id and status word.
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
     * Syncs the order that belongs to $payment. Returns the (possibly
     * updated) order row, or null if no order matches this payment id.
     */
    public function sync(PaymentSnapshot $payment): ?array
    {
        $order = $this->orders->findByMolliePaymentId($payment->id);
        if ($order === null) {
            return null;
        }

        $localStatus = $payment->status;

        if ($order['status'] !== $localStatus || $order['mollie_status'] !== $payment->providerStatus) {
            if ($this->orders->updateStatusFromMollie((int) $order['id'], $localStatus, $payment->providerStatus)) {
                $order['status'] = $localStatus;
                $order['mollie_status'] = $payment->providerStatus;
            } else {
                // Refused: the order already has a final status (a sync that
                // finished first). What is stored stands, and decides below.
                $order = $this->orders->findByMolliePaymentId($payment->id) ?? $order;
                $localStatus = (string) $order['status'];
            }
        }

        // Attempted on every sync (not just on a fresh transition to "paid") so a failed
        // send/issue gets retried by the next webhook call or status-page check — both
        // services' own idempotency guards make this safe to call repeatedly. The invoice
        // is issued first so the confirmation email can attach its PDF (see
        // OrderConfirmationService, which looks the invoice up by order id).
        if ($localStatus === PaymentSnapshot::PAID) {
            $this->invoices->issueForOrderIfNeeded((int) $order['id']);
            $this->confirmations->sendForOrderIfNeeded((int) $order['id']);
        }

        // A refund doesn't change the payment's own status (it stays "paid" —
        // Mollie models refunds as a separate sub-resource), so this is checked
        // independently of $localStatus above. Mollie calls the same payment
        // webhook again for every refund status change, so this runs on every
        // such delivery; upsertRefund()/setRefundedAmount() are both safe to
        // call repeatedly with the same data.
        if ($payment->hasRefunds) {
            $this->syncRefunds($payment, (int) $order['id']);
        }

        return $order;
    }

    /**
     * Mirrors the provider's refunds for this payment into order_refunds, and
     * sets orders.refunded_amount to the provider's own total — never a sum we
     * compute ourselves, so it can't drift from what Mollie considers
     * refunded. Failures are logged and swallowed: a refund-sync hiccup must
     * never break the payment status sync above (which already succeeded),
     * and Mollie will call the webhook again on the refund's next status
     * change.
     */
    private function syncRefunds(PaymentSnapshot $payment, int $orderId): void
    {
        try {
            $this->orders->setRefundedAmount($orderId, $payment->amountRefunded);

            foreach ($payment->refunds() as $refund) {
                $this->orders->upsertRefund(
                    $orderId,
                    $refund->id,
                    $refund->amount,
                    $refund->status,
                    $refund->description,
                    $refund->createdAt
                );
            }
        } catch (\Throwable $e) {
            error_log('[OrderPaymentSync] Failed to sync refunds for order ' . $orderId . ': ' . $e->getMessage());
        }
    }
}
