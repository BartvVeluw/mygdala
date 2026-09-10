<?php

namespace App\Service;

use App\Database;
use App\Mail\OrderConfirmationBuilder;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use PDO;

/**
 * Sends the two paid-order emails (customer confirmation + shop notification)
 * exactly once per order. Called from OrderPaymentSync::sync() — the logic
 * shared by the Mollie webhook and the order-status return page — so both
 * paths trigger it the same way.
 *
 * Idempotency: the whole check-and-send runs inside a single transaction that
 * locks the order row (SELECT ... FOR UPDATE), so two overlapping calls for
 * the same order (e.g. webhook + status page firing close together) can't
 * both pass the "not sent yet" check. confirmation_sent_at is only written
 * after both emails were sent successfully; if sending fails, it's left null
 * so the next sync() call (webhook retry, or the customer reloading the
 * status page) retries — the tradeoff being a failed send is retried rather
 * than silently lost, at the (accepted, low-volume-shop) risk of a duplicate
 * only if the process dies between a successful send and the commit.
 *
 * The customer email attaches the order's PDF invoice (App\Service\
 * InvoiceService, called first by OrderPaymentSync so it exists by the time
 * this runs). If no invoice exists yet for a paid order — invoice issuance
 * failed/hasn't run yet — sending is deliberately deferred (same "leave it
 * null, retry next sync" tradeoff as above) rather than emailing the
 * customer without their invoice. The shop/internal notification email is
 * unaffected — it never gets the invoice attached (see MAIN.MD "Shop/
 * internal notification email").
 */
class OrderConfirmationService
{
    private PDO $db;
    private OrderRepository $orders;
    private InvoiceRepository $invoices;
    private InvoiceService $invoiceService;
    private InvoiceStorage $invoiceStorage;
    private Mailer $mailer;

    public function __construct(
        ?PDO $db = null,
        ?OrderRepository $orders = null,
        ?Mailer $mailer = null,
        ?InvoiceRepository $invoices = null,
        ?InvoiceService $invoiceService = null,
        ?InvoiceStorage $invoiceStorage = null
    ) {
        $this->db = $db ?? Database::connection();
        $this->orders = $orders ?? new OrderRepository($this->db);
        $this->mailer = $mailer ?? new Mailer();
        $this->invoices = $invoices ?? new InvoiceRepository($this->db);
        $this->invoiceService = $invoiceService ?? new InvoiceService($this->db);
        $this->invoiceStorage = $invoiceStorage ?? new InvoiceStorage();
    }

    public function sendForOrderIfNeeded(int $orderId): void
    {
        $this->doSend($orderId, false);
    }

    /**
     * Explicit admin resend (admin/order.php "Verstuur bevestigingsmail
     * opnieuw"): sends again regardless of confirmation_sent_at, but never
     * creates a new invoice/invoice number — it only reuses whatever
     * invoice already exists (see App\Service\InvoiceService, never called
     * here). Returns false (nothing sent) if the order isn't paid or has no
     * invoice yet, so the admin gets clear success/failure feedback instead
     * of a silent no-op.
     */
    public function resend(int $orderId): bool
    {
        return $this->doSend($orderId, true);
    }

    private function doSend(int $orderId, bool $force): bool
    {
        $alreadyInTransaction = $this->db->inTransaction();
        if (!$alreadyInTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $orderId]);
            $order = $stmt->fetch();

            if ($order === false || $order['status'] !== 'paid' || (!$force && $order['confirmation_sent_at'] !== null)) {
                if (!$alreadyInTransaction) {
                    $this->db->commit();
                }
                return false;
            }

            $invoice = $this->invoices->findByOrderId($orderId);
            if ($invoice === null) {
                // Not ready yet: the invoice is issued first by OrderPaymentSync, so this
                // means issuance hasn't succeeded (yet). Leave confirmation_sent_at null so
                // the next sync retries both together, rather than emailing without a PDF.
                if (!$alreadyInTransaction) {
                    $this->db->commit();
                }
                if ($force) {
                    error_log('[OrderConfirmationService] Resend requested for order ' . $orderId . ' but no invoice exists yet.');
                }
                return false;
            }

            $customerStmt = $this->db->prepare('SELECT * FROM customers WHERE id = :id');
            $customerStmt->execute(['id' => $order['customer_id']]);
            $customer = $customerStmt->fetch();

            $items = $this->orders->findItems($orderId);

            $shopEmail = trim((string) ($_ENV['SHOP_NOTIFICATION_EMAIL'] ?? ''));
            if ($shopEmail === '') {
                // Both emails are required for this order to count as "confirmed" — without a
                // configured shop address we can't send the shop notification, so bail out
                // (and leave confirmation_sent_at null) rather than send only the customer half.
                if (!$alreadyInTransaction) {
                    $this->db->commit();
                }
                error_log('[OrderConfirmationService] SHOP_NOTIFICATION_EMAIL is not configured; order ' . $orderId . ' confirmation emails were not sent.');
                return false;
            }

            if (!$this->invoiceStorage->exists($invoice['pdf_path'])) {
                $this->invoiceService->regeneratePdfIfMissing($invoice, $order, $customer, $items);
            }
            $invoicePdfPath = $this->invoiceStorage->path($invoice['pdf_path']);

            $emailSettings = SiteSettings::all();
            $emails = OrderConfirmationBuilder::build($order, $customer, $items, $emailSettings);
            $shopFromName = \App\Mail\EmailIdentity::name();

            $this->mailer->send(
                $customer['email'],
                $customer['name'],
                $emails['customer']['subject'],
                $emails['customer']['html'],
                $emails['customer']['text'],
                $shopEmail,
                $shopFromName,
                [[
                    'path' => $invoicePdfPath,
                    'name' => 'factuur-' . $invoice['invoice_number'] . '.pdf',
                    'mime' => 'application/pdf',
                ]]
            );

            $this->mailer->send(
                $shopEmail,
                $shopFromName,
                $emails['shop']['subject'],
                $emails['shop']['html'],
                $emails['shop']['text'],
                $customer['email'],
                $customer['name']
            );

            $this->db->prepare('UPDATE orders SET confirmation_sent_at = NOW() WHERE id = :id')
                ->execute(['id' => $orderId]);

            if (!$alreadyInTransaction) {
                $this->db->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if (!$alreadyInTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[OrderConfirmationService] Failed to send confirmation for order ' . $orderId . ': ' . Mailer::redactCredentials($e->getMessage()));
            return false;
        }
    }
}
