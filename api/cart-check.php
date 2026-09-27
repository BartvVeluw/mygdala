<?php

/**
 * POST /api/cart-check.php
 *
 * Whether the lines of a cart can be ordered right now (App\Service\
 * CartAvailability): the server's answer to a cart that lives in the
 * visitor's browser. assets/js/shop/cart.js and shop.js ask it when a line is
 * added, when a quantity changes and when the cart or the checkout opens, so
 * a line that is sold out, has too few left, or is not for sale any more is
 * said before the customer pays — never only after.
 *
 * Read-only: it takes nothing from stock and stores nothing. The checkout
 * decides again, inside its own transaction (api/checkout.php).
 *
 * Request body (JSON):
 *   { "items": [ { "id": 3, "variant_id": 7, "qty": 2 }, … ] }
 *
 * Response (200):
 *   { "lines": [ { "status": "ok" | "sold_out" | "insufficient" | "unavailable"
 *                  | "inquiry" | "order_fields", "available": 2 | null }, … ] }
 *   one entry per posted item, in the same order; an item that is not a valid
 *   line at all is "unavailable".
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('shop');

use App\Service\CartAvailability;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
$items = is_array($body) ? ($body['items'] ?? null) : null;

if (!is_array($items) || count($items) > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid cart contents.']);
    exit;
}

try {
    [$lines, $requestIndexes] = CartAvailability::linesFromRequest($items);
    $checked = (new CartAvailability())->check($lines);

    $answer = array_fill(0, count($items), ['status' => CartAvailability::UNAVAILABLE, 'available' => null]);
    foreach ($checked as $lineIndex => $result) {
        $answer[$requestIndexes[$lineIndex]] = $result;
    }

    echo json_encode(['lines' => array_values($answer)]);
} catch (\Throwable $e) {
    error_log('[api/cart-check.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not check your cart right now.']);
}
