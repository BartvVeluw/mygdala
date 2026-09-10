<?php

/**
 * POST /api/admin/update-fulfilment-status.php
 *
 * Sets an order's admin-side handling status to "Open" or "Afgehandeld" and
 * maintains its `handled_at` stamp. Never touches the Mollie-driven payment
 * status (`status`/`mollie_status`), the invoice, or the refund records — see
 * App\Repository\OrderRepository::markHandled()/markOpen(), which each write
 * exactly those two admin-owned columns.
 *
 * Requires an authenticated admin session and a valid CSRF token, and is POST
 * only, so a handling status can never change through a GET link.
 *
 * Request body (application/x-www-form-urlencoded, from the plain HTML forms
 * on admin/order.php and the quick action on admin/orders.php):
 *   order_id=123&fulfilment_status=Afgehandeld&csrf_token=...
 *   [&return_to=orders&handling=Open]
 *
 * `return_to` chooses which admin page the browser goes back to and accepts
 * only the two literals handled below; every redirect URL here is built from
 * constants plus an already-validated integer id, never from a caller-supplied
 * URL, so this endpoint cannot be turned into an open redirect. `handling`
 * carries the overview's active filter tab back so the quick action returns
 * the owner to the same working list, and is re-validated against
 * FULFILMENT_STATUSES before it is put in the URL.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\OrderRepository;

function jsonFail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message]);
    exit;
}

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('orders.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    jsonFail(405, 'Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    jsonFail(403, 'Invalid or missing CSRF token.');
}

$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$fulfilmentStatus = $_POST['fulfilment_status'] ?? null;

if ($orderId === false || $orderId === null || $orderId < 1) {
    jsonFail(400, 'Invalid order id.');
}

if (!is_string($fulfilmentStatus) || !in_array($fulfilmentStatus, OrderRepository::FULFILMENT_STATUSES, true)) {
    jsonFail(400, 'Invalid fulfilment status.');
}

try {
    $orderRepository = new OrderRepository();
    $order = $orderRepository->findById($orderId);

    if ($order === null) {
        jsonFail(404, 'Order not found.');
    }

    if ($fulfilmentStatus === OrderRepository::FULFILMENT_HANDLED) {
        // Only a successfully paid order may be presented as dealt with.
        // Reopening (below) is deliberately not gated this way: it only ever
        // puts an order back on the working list.
        if ($order['status'] !== 'paid') {
            jsonFail(409, 'Only paid orders can be marked as handled.');
        }

        $orderRepository->markHandled($orderId);
    } else {
        $orderRepository->markOpen($orderId);
    }
} catch (\Throwable $e) {
    error_log('[api/admin/update-fulfilment-status.php] ' . $e->getMessage());
    jsonFail(500, 'Could not update fulfilment status right now.');
}

// Plain HTML form submit (no JS) — send the admin back where they came from.
if (($_POST['return_to'] ?? null) === 'orders') {
    $handlingFilter = $_POST['handling'] ?? null;
    $query = '';
    if (is_string($handlingFilter) && in_array($handlingFilter, OrderRepository::FULFILMENT_STATUSES, true)) {
        $query = 'handling=' . urlencode($handlingFilter) . '&';
    }

    header('Location: /admin/orders.php?' . $query . 'updated=1');
    exit;
}

header('Location: /admin/order.php?id=' . $orderId . '&updated=1');
exit;
