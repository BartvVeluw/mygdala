<?php

/**
 * POST /api/admin/delete-personalization-view.php
 *
 * Removes one personalization view and, with it, the zones positioned on it
 * (the foreign key cascades — see the Phase 2 migration).
 *
 * Historical orders are untouched: an order line's personalization is a
 * self-contained snapshot that records the view's key, its label and the
 * image it was drawn on, so deleting the view here can never make an existing
 * order unreadable. Its preview image file is removed too, because unlike a
 * product photo it is only ever referenced by this one view.
 *
 * POST-only and CSRF-protected: a destructive action must never be reachable
 * as a link that a crawler, a prefetch or a pasted URL could trigger.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_personalization_validation.php';

use App\Repository\ProductPersonalizationRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Personalization\ProductPersonalizationContent;
use App\Service\Personalization\PersonalizationPreviewImageUploader;

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
$imagePath = (string) ($view['preview_image_path'] ?? '');

try {
    $deleted = $repository->deleteView($viewId);
    ProductPersonalizationContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/delete-personalization-view.php] ' . $e->getMessage());
    personalizationFail($productId, ['De weergave kon niet worden verwijderd. Probeer het opnieuw.']);
}

// Only after the database says the row is gone: an unlink cannot be rolled
// back. delete() is a no-op outside assets/images/personalization/, so a
// Phase 1/2 view still pointing at a file under assets/images/products/
// leaves that file alone — it may be a real product photo, and it is
// certainly what a historical order's snapshot refers to.
if ($deleted && $imagePath !== '') {
    (new PersonalizationPreviewImageUploader())->delete($imagePath);
}

personalizationRedirect($productId, 'personalization_updated=1');
