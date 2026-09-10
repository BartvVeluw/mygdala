<?php

/**
 * POST /api/admin/update-shipping-rate.php
 *
 * Updates an existing shipping rate's method/weight bracket/price/enabled/
 * sort order from the admin "Verzendinstellingen" page. Note: `enabled` is a
 * normal checkbox here (not a separate toggle endpoint) since it's edited
 * together with the other fields in one row-form — same pattern as
 * update-product-variant.php's "Actief" checkbox.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_shipping_rate_validation.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
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

$rateId = filter_input(INPUT_POST, 'rate_id', FILTER_VALIDATE_INT);

if ($rateId === false || $rateId === null || $rateId < 1) {
    http_response_code(400);
    exit('Invalid shipping rate id.');
}

$rateRepository = new ShippingRateRepository();

if ($rateRepository->findById($rateId) === null) {
    http_response_code(404);
    exit('Shipping rate not found.');
}

[$errors, $fields] = validateShippingRateInput($_POST);

if ($errors !== []) {
    $_SESSION['admin_shipping_errors'] = $errors;
    header('Location: /admin/shipping.php');
    exit;
}

try {
    $rateRepository->update($rateId, $fields);
} catch (\Throwable $e) {
    error_log('[api/admin/update-shipping-rate.php] ' . $e->getMessage());
    $_SESSION['admin_shipping_errors'] = ['Tarief kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/shipping.php');
    exit;
}

header('Location: /admin/shipping.php?updated=1');
exit;
