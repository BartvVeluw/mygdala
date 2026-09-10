<?php

/**
 * POST /api/admin/create-personalization-view.php
 *
 * Adds one personalization VIEW (a side of the product: front, back, lid, …)
 * to a product. A view owns the preview image its zones are positioned
 * against, which is why every zone coordinate stays meaningful when a product
 * gains a second side.
 *
 * The image itself is uploaded afterwards, through
 * update-personalization-view.php, exactly like a product photo: an image is
 * never created and validated in the same request as the row that will point
 * at it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_personalization_validation.php';

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

$repository = new ProductPersonalizationRepository();
$errors = [];

$fields = normalizePersonalizationViewInput($_POST, $errors);
$viewKey = normalizePersonalizationKey($_POST['view_key'] ?? null, 'de weergave', $errors);

try {
    // Creating a view on a product that was never configured also creates its
    // settings row (switched off), so the administrator can build the
    // configuration first and enable it when it is ready.
    $settingsId = $repository->settingsIdForProduct($productId)
        ?? $repository->saveSettings($productId, [
            'is_enabled' => false,
            'instructions' => null,
            'instructions_en' => null,
        ]);

    if ($viewKey !== '' && $repository->viewKeyExists($settingsId, $viewKey)) {
        $errors[] = 'Er bestaat al een weergave met de sleutel "' . $viewKey . '" voor dit product.';
    }

    if ($repository->countViews($settingsId) >= PersonalizationRules::MAX_VIEWS_PER_PRODUCT) {
        $errors[] = 'Dit product heeft al het maximum van '
            . PersonalizationRules::MAX_VIEWS_PER_PRODUCT . ' weergaven.';
    }

    if ($errors !== []) {
        personalizationFail($productId, $errors, ['form' => 'view-create'], $fields + ['view_key' => $viewKey]);
    }

    $repository->createView($settingsId, $fields + ['view_key' => $viewKey]);
    ProductPersonalizationContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-personalization-view.php] ' . $e->getMessage());
    personalizationFail($productId, ['De weergave kon niet worden aangemaakt. Probeer het opnieuw.']);
}

personalizationRedirect($productId, 'personalization_updated=1');
