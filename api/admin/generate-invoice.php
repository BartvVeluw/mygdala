<?php

/**
 * POST /api/admin/generate-invoice.php
 *
 * Manual recovery action for admin/order.php's "Factuur" section, shown only
 * when a paid order has no invoice yet (normally issued automatically by
 * App\Service\InvoiceService::issueForOrderIfNeeded() from
 * OrderPaymentSync::sync() — this covers the rare case that failed/hasn't
 * run yet, e.g. a transient error). Fully idempotent: calling this on an
 * order that already has an invoice just returns the existing one, never a
 * second invoice/number (see InvoiceService's own idempotency guard).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\InvoiceService;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('orders.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

if ($orderId === false || $orderId === null || $orderId < 1) {
    http_response_code(400);
    exit('Invalid order id.');
}

// A test order never gets a real invoice. InvoiceService refuses it
// anyway; this only keeps the screen from claiming one was generated.
$order = (new \App\Repository\OrderRepository())->findById($orderId);
if ($order !== null && \App\Repository\OrderRepository::isTestOrder($order)) {
    header('Location: /admin/order.php?id=' . $orderId);
    exit;
}

(new InvoiceService())->issueForOrderIfNeeded($orderId);

header('Location: /admin/order.php?id=' . $orderId . '&invoice_generated=1');
exit;
