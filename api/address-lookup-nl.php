<?php

/**
 * POST /api/address-lookup-nl.php
 *
 * Live Dutch (BAG/PDOK) address lookup for checkout.php's instant "type your
 * postcode + house number, see the street/city appear" flow — see
 * App\Service\Address\DutchAddressLookupService. This is a UX convenience
 * only: the frontend uses it to show/hide the street+city fields and give
 * instant feedback, but api/checkout.php independently re-runs the exact
 * same lookup server-side before an order/payment can ever be created, so
 * disabling or spoofing this endpoint's response can never get an
 * unverified address into an order.
 *
 * Request body (JSON): { "postcode": "6511AA", "huisnummer": "12", "huisnummer_toevoeging": "A" }
 * Response (200): { "data": { "street": "...", "city": "...", "postal_code": "...", "house_number": "...", "house_number_addition": "A"|null } }
 * Response (422): { "error": "...", "reason": "not_found" }      — genuinely no matching address
 * Response (503): { "error": "...", "reason": "unavailable" }    — PDOK could not be reached right now
 * Response (400): { "error": "..." }                             — malformed request
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('shop');


use App\Service\Address\AddressValidationException;
use App\Service\Address\DutchAddressLookupService;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body.']);
    exit;
}

$postcode = is_string($body['postcode'] ?? null) ? trim($body['postcode']) : '';
$huisnummer = is_string($body['huisnummer'] ?? null) ? trim($body['huisnummer']) : '';
$toevoeging = is_string($body['huisnummer_toevoeging'] ?? null) ? trim($body['huisnummer_toevoeging']) : '';

if ($postcode === '' || $huisnummer === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Postcode and house number are required.']);
    exit;
}

try {
    $result = (new DutchAddressLookupService())->lookup($postcode, $huisnummer, $toevoeging !== '' ? $toevoeging : null);
} catch (AddressValidationException $e) {
    http_response_code($e->reason === 'unavailable' ? 503 : 422);
    echo json_encode(['error' => $e->getMessage(), 'reason' => $e->reason]);
    exit;
}

if (!$result->found) {
    http_response_code(422);
    echo json_encode([
        'error' => "We couldn't verify this address. Please check your postal code and house number.",
        'reason' => 'not_found',
    ]);
    exit;
}

echo json_encode(['data' => [
    'street' => $result->street,
    'city' => $result->city,
    'postal_code' => $result->postalCode,
    'house_number' => $result->houseNumber,
    'house_number_addition' => $result->houseNumberAddition,
]]);
