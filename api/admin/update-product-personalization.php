<?php

/**
 * POST /api/admin/update-product-personalization.php
 *
 * Saves the PRODUCT-LEVEL half of a product's personalization configuration:
 * the on/off switch and the general instructions shown above the whole
 * personalization panel.
 *
 * Views, their preview images and their zones each have their own endpoints
 * (create/update/delete/move-personalization-view.php and
 * ...-personalization-zone.php). That is not tidiness: these are full-form
 * handlers that write every field they are given, so one big save-everything
 * endpoint would mean editing the product's on/off switch could silently
 * rewrite a zone's coordinates. Splitting them makes every action in the
 * builder independent and non-destructive — the same reason product photos,
 * options and variants each have their own endpoints here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_personalization_validation.php';

use App\Service\Language\AdminTranslator;
use App\Repository\ProductPersonalizationRepository;
use App\Repository\ProductRepository;
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

$productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);

if ($productId === false || $productId === null || $productId < 1) {
    http_response_code(400);
    exit('Invalid product id.');
}

if ((new ProductRepository())->findByIdForAdmin($productId) === null) {
    http_response_code(404);
    exit('Product not found.');
}

$errors = [];

$isEnabled = ($_POST['personalization_enabled'] ?? null) === '1';
// Anything but the literal 'required' is 'optional' — the safe direction, and
// the behaviour every product configured before this setting existed keeps.
$mode = PersonalizationRules::purchaseMode($_POST['personalization_mode'] ?? null);
$instructions = trim((string) ($_POST['instructions'] ?? ''));
$instructionsEn = trim((string) ($_POST['instructions_en'] ?? ''));

if (mb_strlen($instructions) > 500 || mb_strlen($instructionsEn) > 500) {
    $errors[] = AdminTranslator::trans('validation.uitlegtekst_mag_maximaal_500_tekens');
}

if ($errors !== []) {
    personalizationFail($productId, $errors, ['form' => 'settings'], [
        'personalization_enabled' => $isEnabled,
        'personalization_mode' => $mode,
        'instructions' => $instructions,
        'instructions_en' => $instructionsEn,
    ]);
}

try {
    (new ProductPersonalizationRepository())->saveSettings($productId, [
        'is_enabled' => $isEnabled,
        'personalization_mode' => $mode,
        'instructions' => $instructions === '' ? null : $instructions,
        'instructions_en' => $instructionsEn === '' ? null : $instructionsEn,
    ]);

    ProductPersonalizationContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-product-personalization.php] ' . $e->getMessage());
    personalizationFail($productId, ['Personalisatie kon niet worden opgeslagen. Probeer het opnieuw.']);
}

personalizationRedirect($productId, 'personalization_updated=1');
