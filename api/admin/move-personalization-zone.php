<?php

/**
 * POST /api/admin/move-personalization-zone.php
 *
 * Moves one zone up or down within its view. The order is what the customer
 * fills in top to bottom on the product page, so "name before date" is real
 * configuration rather than decoration.
 *
 * Same up/down model, and the same two-value direction allow-list, as the
 * product photo and variant editors.
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
$direction = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';

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

try {
    $repository->moveZone($zoneId, $direction);
    ProductPersonalizationContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/move-personalization-zone.php] ' . $e->getMessage());
}

personalizationRedirect((int) $zone['product_id']);
