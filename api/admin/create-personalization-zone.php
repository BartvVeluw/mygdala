<?php

/**
 * POST /api/admin/create-personalization-zone.php
 *
 * Adds one engraving zone to a personalization view: what the customer may
 * put there (text, an image, or either), how it is labelled, whether it is
 * required, which fonts it offers, what it costs extra, and where on the
 * view's preview image it sits.
 *
 * `zone_key` is set once, here, and never changed afterwards: an order line's
 * personalization is keyed by it, so it has to keep identifying the same zone
 * for the lifetime of every order that used it. It must therefore be unique
 * per PRODUCT — not merely per view — which is what makes a historical order
 * unambiguous even after the product grows a second side.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_personalization_validation.php';

use App\Repository\ProductPersonalizationRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Personalization\PersonalizationRules;
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

$viewId = filter_input(INPUT_POST, 'view_id', FILTER_VALIDATE_INT);

if ($viewId === false || $viewId === null || $viewId < 1) {
    http_response_code(400);
    exit('Invalid view id.');
}

$repository = new ProductPersonalizationRepository();
$view = $repository->findViewById($viewId);

if ($view === null) {
    http_response_code(404);
    exit('View not found.');
}

$productId = (int) $view['product_id'];
$settingsId = (int) $view['settings_id'];

$errors = [];
$fields = normalizePersonalizationZoneInput($_POST, $errors);
$zoneKey = normalizePersonalizationKey($_POST['zone_key'] ?? null, 'de zone', $errors);

if ($zoneKey !== '' && $repository->zoneKeyExists($settingsId, $zoneKey)) {
    $errors[] = 'Er bestaat al een zone met de sleutel "' . $zoneKey . '" voor dit product. '
        . 'Een sleutel moet uniek zijn binnen het hele product, ook over weergaven heen.';
}

if ($repository->countZonesInView($viewId) >= PersonalizationRules::MAX_ZONES_PER_VIEW) {
    $errors[] = 'Deze weergave heeft al het maximum van '
        . PersonalizationRules::MAX_ZONES_PER_VIEW . ' zones.';
}

if ($errors !== []) {
    personalizationFail($productId, $errors, ['form' => 'zone-create', 'view_id' => $viewId], $fields + ['zone_key' => $zoneKey]);
}

try {
    $repository->createZone($settingsId, $viewId, $fields + ['zone_key' => $zoneKey]);
    ProductPersonalizationContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-personalization-zone.php] ' . $e->getMessage());
    personalizationFail($productId, ['De zone kon niet worden aangemaakt. Probeer het opnieuw.']);
}

personalizationRedirect($productId, 'personalization_updated=1');
