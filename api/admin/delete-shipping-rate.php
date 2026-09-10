<?php

/**
 * POST /api/admin/delete-shipping-rate.php
 *
 * Permanently removes a shipping rate. Rates aren't referenced by orders
 * (the calculated price/method is snapshotted onto the order itself, see
 * OrderRepository::create()), so unlike products there's no "in use" guard
 * needed here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

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

try {
    (new ShippingRateRepository())->delete($rateId);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-shipping-rate.php] ' . $e->getMessage());
    $_SESSION['admin_shipping_errors'] = ['Tarief kon niet worden verwijderd. Probeer het opnieuw.'];
    header('Location: /admin/shipping.php');
    exit;
}

header('Location: /admin/shipping.php?updated=1');
exit;
