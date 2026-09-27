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
 * snapshot — same content, so nothing is re-invented.
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
     * if the order isn't paid, is a test order (`payment_mode = 'test'`: no
     * real invoice, ever), or issuance failed (logged; safe to retry on
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

            // A TEST payment is no sale: no number from the real, gapless
            // sequence, no invoice row and no PDF. The counter is not even
            // read. An order from before the mode was recorded (NULL) is
            // invoiced as it always was.
            if (OrderRepository::isTestOrder($order)) {
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

            $relativePath = self::storagePath($year, $invoiceNumber);
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
     * Rebuilds the PDF file from the invoice's own frozen data (see
     * renderIssued()) if it's missing from disk — e.g. after moving hosting,
     * or a disk write that failed right after the invoice row was committed.
     * Never allocates a new number or touches the DB row.
     */
    public function regeneratePdfIfMissing(array $invoice, array $order, array $customer, array $items): void
    {
        if ($this->storage->exists($invoice['pdf_path'])) {
            return;
        }

        $this->storage->write((string) $invoice['pdf_path'], $this->renderIssued($invoice, $order, $customer, $items));
    }

    /**
     * What the customer received for $orderId, for reading only — the CMS's
     * "Factuur bekijken" (api/admin/invoice-download.php). That is the stored
     * PDF, the very file the confirmation mail attached; or, when that file
     * has gone missing, the same invoice rendered again in memory from its
     * frozen data. Null when the order has no invoice (yet).
     *
     * Looking never issues an invoice, allocates a number, writes a file or
     * touches a row. A missing file stays missing here: putting it back is the
     * mail path's job (regeneratePdfIfMissing()).
     *
     * @return array{invoice: array<string, mixed>, pdf: string}|null
     */
    public function issuedPdfForOrder(int $orderId): ?array
    {
        $invoice = $this->invoices->findByOrderId($orderId);
        if ($invoice === null) {
            return null;
        }

        $path = (string) $invoice['pdf_path'];
        if ($this->storage->exists($path)) {
            return ['invoice' => $invoice, 'pdf' => $this->storage->read($path)];
        }

        $order = $this->orders->findById($orderId);
        if ($order === null) {
            return null;
        }

        $customerStmt = $this->db->prepare('SELECT * FROM customers WHERE id = :id');
        $customerStmt->execute(['id' => $order['customer_id']]);
        $customer = $customerStmt->fetch();

        return [
            'invoice' => $invoice,
            'pdf' => $this->renderIssued($invoice, $order, $customer === false ? [] : $customer, $this->orders->findItems($orderId)),
        ];
    }

    /**
     * An issued invoice rendered again from what was frozen when it was
     * issued: its own number, date and seller_snapshot (never current Site
     * Settings) and the order's own snapshot rows. Writes nothing.
     *
     * Same content as the first render, not the same bytes: dompdf stamps
     * every file with the moment it was rendered and a random document id.
     */
    private function renderIssued(array $invoice, array $order, array $customer, array $items): string
    {
        $sellerSnapshot = json_decode((string) $invoice['seller_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $orderNumber = OrderRepository::orderNumber($order);
        $invoiceDate = new \DateTimeImmutable((string) $invoice['invoice_date']);

        return $this->renderer->render($order, $customer, $items, $sellerSnapshot, (string) $invoice['invoice_number'], $invoiceDate, $orderNumber);
    }

    /**
     * The file name an invoice travels under: the confirmation mail's
     * attachment and the CMS preview and download. The invoice number carries
     * the Shop's own prefix, which is free text in Shop-instellingen, so
     * anything outside [A-Za-z0-9._-] becomes "-" before it reaches a header.
     * A prefix of letters and digits gives exactly the name it always had.
     */
    public static function pdfFilename(string $invoiceNumber): string
    {
        return 'factuur-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $invoiceNumber) . '.pdf';
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
            // The legal name when the owner gave one (Shop-instellingen), the
            // site name otherwise, which is what every invoice carried before
            // company_name existed.
            'company_name' => $settings['company_name'] !== '' ? $settings['company_name'] : $settings['site_name'],
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
            // What this invoice is numbered with: the setting, or — for a
            // prefix stored before App\Service\DocumentNumberPrefix existed
            // — only its allowed characters, so no new number (and no file
            // name) carries a "/" or ":". An issued invoice keeps its own.
            'invoice_number_prefix' => DocumentNumberPrefix::invoicePrefixForNewInvoice($settings['invoice_number_prefix']),
        ];
    }

    /**
     * The file a NEW invoice's PDF is stored under, relative to
     * InvoiceStorage: "<year>/<number>.pdf". Anything outside
     * [A-Za-z0-9_-] in the number becomes "-", a second line behind
     * DocumentNumberPrefix: a number is a file name here, never a path. An
     * issued invoice's stored `pdf_path` stays what it is.
     */
    public static function storagePath(int $year, string $invoiceNumber): string
    {
        return $year . '/' . preg_replace('/[^A-Za-z0-9_-]/', '-', $invoiceNumber) . '.pdf';
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
