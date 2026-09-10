<?php

/**
 * POST /api/admin/apply-carrier-rate-pending.php
 *
 * Promotes a carrier_rates row's `pending_price` (a price the PostNL sync
 * saw but didn't apply — either because the rate is in manual mode, or
 * because the change exceeded PostNlRateSyncService's review threshold) to
 * its live `price`. This is the explicit human "yes, use this price" action
 * MAIN.MD requires for a large/unusual change — nothing is ever applied
 * automatically once it's been held back.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\CarrierRateRepository;

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

$id = filter_input(INPUT_POST, 'carrier_rate_id', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid carrier rate id.');
}

try {
    $applied = (new CarrierRateRepository())->applyPendingPrice($id);
    if (!$applied) {
        $_SESSION['admin_carrier_rates_errors'] = ['Er is geen openstaande prijs om toe te passen.'];
    }
} catch (\Throwable $e) {
    error_log('[api/admin/apply-carrier-rate-pending.php] ' . $e->getMessage());
    $_SESSION['admin_carrier_rates_errors'] = ['Prijs kon niet worden toegepast. Probeer het opnieuw.'];
}

header('Location: /admin/carrier-rates.php?updated=1');
exit;
