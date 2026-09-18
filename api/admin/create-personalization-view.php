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

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Repository\ProductPersonalizationRepository;
use App\Repository\ProductRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Personalization\PersonalizationLocalization;
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

$db = Database::connection();
$repository = new ProductPersonalizationRepository($db);
$errors = [];

$fields = normalizePersonalizationViewInput($_POST, $errors);
$viewKey = normalizePersonalizationKey($_POST['view_key'] ?? null, 'de weergave', $errors);
// `true`: a new view is named in the DEFAULT language, whatever language the
// screen showed (Multilingual 2.0 phase 5 wave D).
$language = personalizationLanguage($_POST, true, $errors);

try {
    // Creating a view on a product that was never configured also creates its
    // settings row (switched off), so the administrator can build the
    // configuration first and enable it when it is ready.
    $settingsId = $repository->settingsIdForProduct($productId)
        ?? $repository->saveSettings($productId, ['is_enabled' => false]);

    if ($viewKey !== '' && $repository->viewKeyExists($settingsId, $viewKey)) {
        $errors[] = AdminTranslator::trans('validation.view_key_exists', ['v1' => $viewKey]);
    }

    if ($repository->countViews($settingsId) >= PersonalizationRules::MAX_VIEWS_PER_PRODUCT) {
        $errors[] = AdminTranslator::trans(
            'validation.max_views_reached',
            ['v1' => PersonalizationRules::MAX_VIEWS_PER_PRODUCT]
        );
    }

    if ($errors !== []) {
        personalizationFail($productId, $errors, ['form' => 'view-create'], $fields + ['view_key' => $viewKey]);
    }

    // Row and label are ONE transaction: a view is never in the editor
    // without the name that identifies it there.
    $db->beginTransaction();
    $viewId = $repository->createView($settingsId, ['view_key' => $viewKey]);
    PersonalizationLocalization::saveViewLabel($viewId, $language, $fields['label']);
    $db->commit();

    ProductPersonalizationContent::clearCache();
    PersonalizationLocalization::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/create-personalization-view.php] ' . $e->getMessage());
    personalizationFail($productId, ['De weergave kon niet worden aangemaakt. Probeer het opnieuw.']);
}

personalizationRedirect($productId, 'personalization_updated=1');
