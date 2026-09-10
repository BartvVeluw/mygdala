<?php

/**
 * POST /api/admin/create-shipping-rate.php
 *
 * Adds a new shipping rate (weight bracket) to a zone from the admin
 * "Verzendinstellingen" page. Same PRG/session-flash pattern as the other
 * admin form endpoints.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_shipping_rate_validation.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\ShippingZoneRepository;
use App\Repository\ShippingRateRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('shipping.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$zoneId = filter_input(INPUT_POST, 'shipping_zone_id', FILTER_VALIDATE_INT);

if ($zoneId === false || $zoneId === null || $zoneId < 1) {
    http_response_code(400);
    exit('Invalid shipping zone id.');
}

if ((new ShippingZoneRepository())->findById($zoneId) === null) {
    http_response_code(404);
    exit('Shipping zone not found.');
}

[$errors, $fields] = validateShippingRateInput($_POST);

if ($errors !== []) {
    $_SESSION['admin_shipping_errors'] = $errors;
    header('Location: /admin/shipping.php');
    exit;
}

try {
    (new ShippingRateRepository())->create([
        'shipping_zone_id' => $zoneId,
        'shipping_profile' => $fields['shipping_profile'],
        'min_weight_grams' => $fields['min_weight_grams'],
        'max_weight_grams' => $fields['max_weight_grams'],
        'price' => $fields['price'],
        'enabled' => $fields['enabled'],
        'sort_order' => $fields['sort_order'],
        'carrier_rate_id' => $fields['carrier_rate_id'],
    ]);
} catch (\Throwable $e) {
    error_log('[api/admin/create-shipping-rate.php] ' . $e->getMessage());
    $_SESSION['admin_shipping_errors'] = ['Tarief kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/shipping.php');
    exit;
}

header('Location: /admin/shipping.php?updated=1');
exit;
