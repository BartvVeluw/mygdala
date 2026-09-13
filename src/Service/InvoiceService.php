<?php

namespace App\Service;

use App\Database;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use PDO;

/**
 * Issues (at most) one immutable invoice per paid order. Called from
 * OrderPaymentSync::sync(), right before OrderConfirmationService, so the
 * confirmation email can attach the resulting PDF.
 *
 * Idempotency/concurrency mirrors OrderConfirmationService exactly (see that
 * class's docblock): the whole check-and-issue runs inside one transaction
 * that locks the order row (SELECT ... FOR UPDATE) — two overlapping calls
 * for the same order (webhook + return-page racing) can't both pass the
 * "no invoice yet" check, and the `invoices.order_id` UNIQUE constraint is a
 * second, DB-level backstop. Invoice-number allocation
 * (InvoiceRepository::allocateNextNumber()) runs inside the same
 * transaction, so it's also safe across *different* orders being invoiced
 * at the same moment.
 *
 * The PDF is rendered fully in memory *before* any DB write, so a rendering
 * failure never consumes an invoice number or leaves a DB row with no file.
 * The file itself is written to disk only after the transaction commits
 * (a filesystem write can't be part of a DB transaction); if that disk
 * write fails, the invoice row/number already exist and the file can be
 * reproduced later via regeneratePdfIfMissing() from the same frozen
 * snapshot — deterministic, so nothing is re-invented.
 */
class InvoiceService
{
    private PDO $db;
    private OrderRepository $orders;
    private InvoiceRepository $invoices;
    private PdfInvoiceRenderer $renderer;
    private InvoiceStorage $storage;

    public function __construct(
        ?PDO $db = null,
        ?OrderRepository $orders = null,
        ?InvoiceRepository $invoices = null,
        ?PdfInvoiceRenderer $renderer = null,
        ?InvoiceStorage $storage = null
    ) {
        $this->db = $db ?? Database::connection();
        $this->orders = $orders ?? new OrderRepository($this->db);
        $this->invoices = $invoices ?? new InvoiceRepository($this->db);
        $this->renderer = $renderer ?? new PdfInvoiceRenderer();
        $this->storage = $storage ?? new InvoiceStorage();
    }

    /**
     * Returns the (possibly newly created) invoice row for $orderId, or null
     * if the order isn't paid or issuance failed (logged; safe to retry on
     * the next webhook/return-page sync — same tradeoff as
     * OrderConfirmationService::sendForOrderIfNeeded()).
     *
     * @return array<string, mixed>|null
     */
    public function issueForOrderIfNeeded(int $orderId): ?array
    {
        $alreadyInTransaction = $this->db->inTransaction();
        if (!$alreadyInTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $orderId]);
            $order = $stmt->fetch();

            if ($order === false || $order['status'] !== 'paid') {
                if (!$alreadyInTransaction) {
                    $this->db->commit();
                }
                return null;
            }

            $existing = $this->invoices->findByOrderId($orderId);
            if ($existing !== null) {
                if (!$alreadyInTransaction) {
                    $this->db->commit();
                }
                return $existing;
            }

            $customerStmt = $this->db->prepare('SELECT * FROM customers WHERE id = :id');
            $customerStmt->execute(['id' => $order['customer_id']]);
            $customer = $customerStmt->fetch();

            $items = $this->orders->findItems($orderId);
            $sellerSnapshot = self::buildSellerSnapshot();
            $orderNumber = OrderRepository::orderNumber($order);

            $invoiceDate = new \DateTimeImmutable('today');
            $year = (int) $invoiceDate->format('Y');
            $sequence = $this->invoices->allocateNextNumber($this->db, $year);
            $invoiceNumber = self::formatInvoiceNumber($sellerSnapshot['invoice_number_prefix'], $year, $sequence);

            // Rendered before the DB insert: if this throws, the transaction
            // below never runs (nothing committed, no number "spent").
            $pdfBytes = $this->renderer->render($order, $customer, $items, $sellerSnapshot, $invoiceNumber, $invoiceDate, $orderNumber);

            $relativePath = $year . '/' . $invoiceNumber . '.pdf';
            $invoiceId = $this->invoices->create(
                $orderId,
                $invoiceNumber,
                $invoiceDate,
                (string) $order['currency'],
                $sellerSnapshot,
                $relativePath
            );

            if (!$alreadyInTransaction) {
                $this->db->commit();
            }

            // Outside the transaction: a filesystem write can't be rolled
            // back by MySQL anyway, and the invoice row now exists so a
            // failure here is recoverable via regeneratePdfIfMissing().
            $this->storage->write($relativePath, $pdfBytes);

            return $this->invoices->findById($invoiceId);
        } catch (\Throwable $e) {
            if (!$alreadyInTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[InvoiceService] Failed to issue invoice for order ' . $orderId . ': ' . Mailer::redactCredentials($e->getMessage()));
            return null;
        }
    }

    /**
     * Rebuilds the PDF file from the invoice's own frozen seller_snapshot
     * (never from current Site Settings) if it's missing from disk — e.g.
     * after moving hosting, or a disk write that failed right after the
     * invoice row was committed. Deterministic: same invoice number/date,
     * same bytes as if generated at issuance time. Never allocates a new
     * number or touches the DB row.
     */
    public function regeneratePdfIfMissing(array $invoice, array $order, array $customer, array $items): void
    {
        if ($this->storage->exists($invoice['pdf_path'])) {
            return;
        }

        $sellerSnapshot = json_decode((string) $invoice['seller_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $orderNumber = OrderRepository::orderNumber($order);
        $invoiceDate = new \DateTimeImmutable((string) $invoice['invoice_date']);

        $pdfBytes = $this->renderer->render($order, $customer, $items, $sellerSnapshot, (string) $invoice['invoice_number'], $invoiceDate, $orderNumber);

        $this->storage->write((string) $invoice['pdf_path'], $pdfBytes);
    }

    /**
     * Freezes the company/invoice settings currently in SiteSettings into a
     * plain array — this becomes invoices.seller_snapshot and must never be
     * re-read from SiteSettings again once an invoice exists (see MAIN.MD
     * "Invoice snapshot architecture": a later Site Settings edit must never
     * alter an already-issued invoice).
     *
     * @return array<string, string>
     */
    public static function buildSellerSnapshot(): array
    {
        $settings = SiteSettings::all();

        return [
            'company_name' => $settings['site_name'],
            'logo_path' => $settings['logo_path'],
            'street' => $settings['company_street'],
            'house_number' => $settings['company_house_number'],
            'postal_code' => $settings['company_postal_code'],
            'city' => $settings['company_city'],
            'country' => $settings['company_country'],
            'kvk_number' => $settings['kvk_number'],
            'vat_id' => $settings['company_vat_id'],
            'email' => $settings['email'],
            'phone' => $settings['company_phone'],
            'website' => $settings['company_website'],
            'tax_note' => $settings['invoice_tax_note'],
            'footer_text' => $settings['invoice_footer_text'],
            'payment_note' => $settings['invoice_payment_note'],
            'invoice_number_prefix' => $settings['invoice_number_prefix'],
        ];
    }

    /**
     * E.g. formatInvoiceNumber('INV', 2026, 1) === 'INV2026-000001' — same
     * shape as OrderRepository::formatOrderNumber(), but backed by its own
     * gapless per-year sequence (see InvoiceRepository::allocateNextNumber())
     * rather than an order's own id, since not every order gets an invoice.
     */
    public static function formatInvoiceNumber(string $prefix, int $year, int $sequence): string
    {
        return $prefix . $year . '-' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
