<?php

/**
 * POST /api/admin/sync-postnl-rates.php
 *
 * The admin "PostNL-tarieven nu bijwerken" button — runs exactly the same
 * App\Service\Shipping\PostNl\PostNlRateSyncService as the scheduled cron
 * (scripts/sync-postnl-rates.php), just triggered manually and with
 * triggered_by='admin' on the logged run. The structured result is flashed
 * into the session (same PRG pattern as every other admin form here) so
 * /admin/carrier-rates.php can render the "Changed / Unchanged / Warnings"
 * breakdown after the redirect.
 *
 * This performs a real outbound HTTP request to postnl.nl and can take a few
 * seconds — deliberately synchronous (no queue/background job exists in this
 * project), acceptable for an explicit, infrequent admin action.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Shipping\PostNl\PostNlRateSyncService;

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

try {
    $result = (new PostNlRateSyncService())->sync(triggeredBy: 'admin');
    $_SESSION['admin_carrier_rates_sync_result'] = $result->toArray();
} catch (\Throwable $e) {
    error_log('[api/admin/sync-postnl-rates.php] ' . $e->getMessage());
    $_SESSION['admin_carrier_rates_errors'] = ['Synchronisatie kon niet worden uitgevoerd. Probeer het later opnieuw.'];
}

header('Location: /admin/carrier-rates.php');
exit;
