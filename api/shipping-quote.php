<?php

/**
 * POST /api/shipping-quote.php
 *
 * Live shipping price preview for checkout.php: given the current cart,
 * chosen delivery method and destination country, returns what shipping
 * would cost right now. Uses exactly the same
 * App\Service\Shipping\ShippingCalculationService as api/checkout.php, so
 * the preview shown to the customer always matches what checkout will
 * actually charge — but this endpoint's result is a preview only. It is
 * never trusted as the authoritative price: api/checkout.php always
 * recalculates from scratch at order creation (see MAIN.MD "Shipping
 * calculation system").
 *
 * Request body (JSON):
 *   {
 *     "verzendmethode": "afhalen" | "verzenden",
 *     "land": "NL" | "BE" | ... (required when verzendmethode is "verzenden"),
 *     "items": [ { "id": 3, "qty": 2 }, ... ]
 *   }
 *
 * Response (200): { "shipping_cost": 1.40, "shipping_method": "letter", "shipping_method_label": "Briefpost" }
 * Response (4xx/5xx): { "error": "..." }
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('shop');


use App\Service\Language\SiteText;
use App\Service\Routing\ApiLanguage;
use App\Service\Shipping\ShippingCalculationService;
use App\Service\Shipping\ShippingProfile;
use App\Service\Shipping\ShippingUnavailableException;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

function quoteFail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

// The method's name comes back in the language of the page asking (?lang=).
ApiLanguage::apply($_GET['lang'] ?? null);

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);

if (!is_array($body)) {
    quoteFail(400, 'Invalid request body.');
}

$verzendmethode = $body['verzendmethode'] ?? null;
$land = is_string($body['land'] ?? null) ? strtoupper(trim($body['land'])) : '';
$items = $body['items'] ?? null;

if (!is_string($verzendmethode) || !in_array($verzendmethode, ['afhalen', 'verzenden'], true)) {
    quoteFail(400, 'Invalid shipping method.');
}

if ($verzendmethode === 'afhalen') {
    echo json_encode([
        'shipping_cost' => 0.0,
        'shipping_method' => 'afhalen',
        'shipping_method_label' => SiteText::pick(['nl' => 'Afhalen', 'en' => 'Pickup']),
    ]);
    exit;
}

if (!preg_match('/^[A-Z]{2}$/', $land)) {
    quoteFail(400, 'Please select a valid destination country.');
}

if (!is_array($items) || $items === []) {
    quoteFail(400, 'Your cart is empty.');
}

$shippingLines = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        quoteFail(400, 'Invalid cart contents.');
    }
    $id = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
    $qty = filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1 || $qty === false || $qty < 1 || $qty > 50) {
        quoteFail(400, 'Invalid cart contents.');
    }
    $shippingLines[] = ['product_id' => $id, 'quantity' => $qty];
}

try {
    $result = (new ShippingCalculationService())->calculateForShipping($shippingLines, $land);
} catch (ShippingUnavailableException $e) {
    quoteFail(422, $e->getMessage());
} catch (\Throwable $e) {
    error_log('[api/shipping-quote.php] ' . $e->getMessage());
    quoteFail(500, 'Could not calculate shipping costs right now.');
}

echo json_encode([
    'shipping_cost' => $result->price,
    'shipping_method' => $result->method,
    'shipping_method_label' => ShippingProfile::label($result->method),
]);
