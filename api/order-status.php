<?php

/**
 * GET /api/order-status.php?order=123
 *
 * Used by the return page (bestelling-status.php) the customer lands on
 * after Mollie's checkout. If the order is still "pending" we ask Mollie
 * for the live payment status first (the webhook may not have arrived
 * yet — or, in local dev without a public URL, never will) and sync the
 * order before responding, using the same logic the webhook uses.
 *
 * Only minimal, non-sensitive order info is returned (no email/phone/address) —
 * this endpoint is reachable with just a guessable numeric order id.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('shop');


use App\Repository\OrderRepository;
use App\Service\MollieClientFactory;
use App\Service\OrderPaymentSync;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$orderId = filter_input(INPUT_GET, 'order', FILTER_VALIDATE_INT);

if ($orderId === false || $orderId === null || $orderId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid order id']);
    exit;
}

try {
    $orderRepository = new OrderRepository();
    $order = $orderRepository->findById($orderId);

    if ($order === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit;
    }

    if ($order['status'] === 'pending' && $order['mollie_payment_id']) {
        try {
            $payment = MollieClientFactory::client()->payments->get($order['mollie_payment_id']);
            $synced = (new OrderPaymentSync($orderRepository))->sync($payment);
            if ($synced !== null) {
                $order = $synced;
            }
        } catch (\Throwable $e) {
            error_log('[api/order-status.php] Mollie sync failed: ' . $e->getMessage());
            // Keep serving the last known (pending) status instead of failing the request.
        }
    }

    $items = $orderRepository->findItems($orderId);

    echo json_encode(['data' => [
        'order_id' => $orderId,
        'order_number' => OrderRepository::formatOrderNumber($orderId, new \DateTimeImmutable((string) $order['created_at'])),
        'status' => $order['status'],
        'total' => $order['total'],
        'shipping_cost' => $order['shipping_cost'],
        'currency' => $order['currency'],
        'confirmation_sent' => $order['confirmation_sent_at'] !== null,
        'customer_first_name' => explode(' ', trim((string) $order['customer_name']))[0] ?? '',
        'items' => array_map(static function (array $item): array {
            return [
                'name' => $item['name'],
                'name_en' => $item['name_en'],
                'variant_label' => $item['variant_label'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => $item['unit_price'],
                'image_path' => $item['image_path'],
            ];
        }, $items),
    ]]);
} catch (\Throwable $e) {
    error_log('[api/order-status.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not load this order right now.']);
}
