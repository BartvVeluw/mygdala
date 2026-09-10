<?php

/**
 * POST /api/admin/update-personalization-zone.php
 *
 * Saves one engraving zone's properties. Each zone has its own form and its
 * own request, so editing the back's message zone can never disturb the
 * front's name zone — the failure mode a single save-everything form would
 * make easy.
 *
 * `zone_key` is not updatable here, on purpose: order rows point at it. Its
 * view is not changeable either, because the coordinates are percentages of
 * that view's image and would land somewhere arbitrary on another one.
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

$errors = [];
$fields = normalizePersonalizationZoneInput($_POST, $errors);

if ($errors !== []) {
    personalizationFail($productId, $errors, ['form' => 'zone', 'zone_id' => $zoneId], $fields);
}

try {
    $repository->updateZone($zoneId, $fields);
    ProductPersonalizationContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-personalization-zone.php] ' . $e->getMessage());
    personalizationFail($productId, ['De zone kon niet worden opgeslagen. Probeer het opnieuw.']);
}

personalizationRedirect($productId, 'personalization_updated=1');
