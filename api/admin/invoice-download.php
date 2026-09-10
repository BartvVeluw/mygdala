<?php

/**
 * GET /api/admin/invoice-download.php?order_id=<order_id>[&mode=download]
 *
 * Streams an order's invoice PDF to an authenticated admin — never exposes
 * the storage/ filesystem path itself (see App\Service\InvoiceStorage).
 * Read-only GET, no CSRF token needed (nothing is mutated), same reasoning
 * as admin/orders-export.php. `mode=download` forces a Content-Disposition
 * attachment; the default is inline so "Bekijk factuur" opens in the
 * browser's own PDF viewer.
 *
 * Self-heals a missing PDF file (invoice row exists, e.g. storage disk
 * write failed after the invoice was issued) by regenerating it from the
 * invoice's own frozen seller_snapshot — same regeneratePdfIfMissing() used
 * by OrderConfirmationService before attaching the PDF to an email.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use App\Service\AdminAuth;
use App\Service\InvoiceService;
use App\Service\InvoiceStorage;

AdminAuth::requireLogin();
AdminAuth::requirePermission('orders.view');

$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);

if ($orderId === false || $orderId === null || $orderId < 1) {
    http_response_code(400);
    exit('Invalid order id.');
}

$invoice = (new InvoiceRepository())->findByOrderId($orderId);

if ($invoice === null) {
    http_response_code(404);
    exit('Invoice not found.');
}

$storage = new InvoiceStorage();

if (!$storage->exists((string) $invoice['pdf_path'])) {
    try {
        $orderRepository = new OrderRepository();
        $order = $orderRepository->findById($orderId);
        $customerStmt = Database::connection()->prepare('SELECT * FROM customers WHERE id = :id');
        $customerStmt->execute(['id' => $order['customer_id']]);
        $customer = $customerStmt->fetch();
        $items = $orderRepository->findItems($orderId);

        (new InvoiceService())->regeneratePdfIfMissing($invoice, $order, $customer, $items);
    } catch (\Throwable $e) {
        error_log('[api/admin/invoice-download.php] Could not regenerate missing PDF for order ' . $orderId . ': ' . $e->getMessage());
    }
}

if (!$storage->exists((string) $invoice['pdf_path'])) {
    http_response_code(404);
    exit('Invoice PDF file not found.');
}

$path = $storage->path((string) $invoice['pdf_path']);
$disposition = (($_GET['mode'] ?? '') === 'download') ? 'attachment' : 'inline';
$filename = 'factuur-' . $invoice['invoice_number'] . '.pdf';

header('Content-Type: application/pdf');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
