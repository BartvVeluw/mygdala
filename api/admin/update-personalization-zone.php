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

use App\Database;
use App\Repository\ProductPersonalizationRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Personalization\PersonalizationLocalization;
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

$db = Database::connection();
$repository = new ProductPersonalizationRepository($db);
$zone = $repository->findZoneById($zoneId);

if ($zone === null) {
    http_response_code(404);
    exit('Zone not found.');
}

$productId = (int) $zone['product_id'];

$errors = [];
$fields = normalizePersonalizationZoneInput($_POST, $errors);
// `false`: an existing zone, so the words written are those of the language
// the form names; every other translation of it stays as it is.
$language = personalizationLanguage($_POST, false, $errors);

if ($errors !== []) {
    personalizationFail($productId, $errors, ['form' => 'zone', 'zone_id' => $zoneId], $fields + ['language_code' => $language]);
}

try {
    // Row and words are ONE transaction, and the words are only this
    // language's.
    $db->beginTransaction();
    $repository->updateZone($zoneId, $fields);
    PersonalizationLocalization::saveZone($zoneId, $language, [
        PersonalizationLocalization::LABEL => $fields[PersonalizationLocalization::LABEL],
        PersonalizationLocalization::INSTRUCTIONS => $fields[PersonalizationLocalization::INSTRUCTIONS],
        PersonalizationLocalization::PLACEHOLDER => $fields[PersonalizationLocalization::PLACEHOLDER],
    ]);
    $db->commit();

    ProductPersonalizationContent::clearCache();
    PersonalizationLocalization::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-personalization-zone.php] ' . $e->getMessage());
    personalizationFail($productId, ['De zone kon niet worden opgeslagen. Probeer het opnieuw.']);
}

personalizationRedirect($productId, 'personalization_updated=1');
