<?php

/**
 * POST /api/admin/delete-personalization-zone.php
 *
 * Removes one engraving zone from a view.
 *
 * Historical orders are untouched: an order line's personalization records
 * the zone's key, its label and its full definition in its own snapshot, so a
 * deleted zone still reads correctly on every order that used it. A customer
 * with that zone in their cart is caught at checkout instead, where the
 * validator refuses a zone that no longer exists rather than quietly dropping
 * what they asked to have engraved.
 *
 * POST-only and CSRF-protected: a destructive action must never be reachable
 * as a link.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_personalization_validation.php';

use App\Repository\ProductPersonalizationRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Personalization\ProductPersonalizationContent;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('personalization.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$zoneId = filter_input(INPUT_POST, 'zone_id', FILTER_VALIDATE_INT);

if ($zoneId === false || $zoneId === null || $zoneId < 1) {
    http_response_code(400);
    exit('Invalid zone id.');
}

$repository = new ProductPersonalizationRepository();
$zone = $repository->findZoneById($zoneId);

if ($zone === null) {
    http_response_code(404);
    exit('Zone not found.');
}

$productId = (int) $zone['product_id'];

try {
    $repository->deleteZone($zoneId);
    ProductPersonalizationContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/delete-personalization-zone.php] ' . $e->getMessage());
    personalizationFail($productId, ['De zone kon niet worden verwijderd. Probeer het opnieuw.']);
}

personalizationRedirect($productId, 'personalization_updated=1');
