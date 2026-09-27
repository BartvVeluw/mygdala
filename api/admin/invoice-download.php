<?php

/**
 * GET /api/admin/invoice-download.php?order_id=<order_id>[&mode=download]
 *
 * "Factuur bekijken" on admin/order.php: shows an authenticated admin the
 * invoice PDF exactly as the customer received it, inline in the browser's
 * own PDF viewer (a new tab). `mode=download` sends the same bytes as an
 * attachment. Never exposes the storage/ filesystem path itself (see
 * App\Service\InvoiceService::issuedPdfForOrder()).
 *
 * Read-only: a GET, so no CSRF token (same reasoning as
 * admin/orders-export.php), and orders.view is enough, like the order screen
 * itself. It streams the stored file, the one the confirmation mail
 * attached; when that file has gone missing it streams the same invoice
 * rendered again in memory from its frozen data, and leaves putting the file
 * back to the mail path. It never issues an invoice, allocates a number,
 * writes a file or changes a row: an order without an invoice answers 404.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\InvoiceService;

AdminAuth::requireLogin();
AdminAuth::requirePermission('orders.view');

$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);

if ($orderId === false || $orderId === null || $orderId < 1) {
    http_response_code(400);
    exit('Invalid order id.');
}

try {
    $issued = (new InvoiceService())->issuedPdfForOrder($orderId);
} catch (\Throwable $e) {
    error_log('[api/admin/invoice-download.php] Could not show the invoice of order ' . $orderId . ': ' . $e->getMessage());
    http_response_code(500);
    exit('Invoice could not be shown.');
}

if ($issued === null) {
    http_response_code(404);
    exit('Invoice not found.');
}

$disposition = (($_GET['mode'] ?? '') === 'download') ? 'attachment' : 'inline';
$filename = InvoiceService::pdfFilename((string) $issued['invoice']['invoice_number']);

header('Content-Type: application/pdf');
header('Content-Length: ' . (string) strlen($issued['pdf']));
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $issued['pdf'];
exit;
