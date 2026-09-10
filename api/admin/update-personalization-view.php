<?php

/**
 * POST /api/admin/update-personalization-view.php
 *
 * Renames one personalization view and, when a file was actually picked or
 * the "remove" box ticked, replaces or clears its preview image.
 *
 * The image is written only on those two explicit actions — an ordinary
 * rename leaves it exactly as it was. That matters more here than anywhere
 * else on the page: every zone on this view is positioned as a percentage OF
 * THIS IMAGE, so silently dropping or swapping it would move every engraving
 * area on the product.
 *
 * `view_key` is deliberately not updatable: order rows record which view a
 * personalization was engraved on, so renaming the key would rewrite what a
 * historical order says. The customer-facing label is what the administrator
 * changes instead.
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

// The product this view belongs to comes from the database, never from the
// request — so a forged product_id cannot redirect the result somewhere else.
$productId = (int) $view['product_id'];
$existingImagePath = (string) ($view['preview_image_path'] ?? '');

$errors = [];
$fields = normalizePersonalizationViewInput($_POST, $errors);
$removeImage = ($_POST['remove_preview_image'] ?? null) === '1';

$uploader = new PersonalizationPreviewImageUploader();
$newImagePath = null;
$hasUpload = isset($_FILES['preview_image'])
    && ($_FILES['preview_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($hasUpload) {
    try {
        $newImagePath = $uploader->store($_FILES['preview_image']);
    } catch (\RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
}

if ($errors !== []) {
    // Never leave an orphaned upload behind when the rest was invalid.
    if ($newImagePath !== null) {
        $uploader->delete($newImagePath);
    }

    personalizationFail($productId, $errors, ['form' => 'view', 'view_id' => $viewId], $fields);
}

try {
    $repository->updateView($viewId, $fields);

    if ($newImagePath !== null) {
        $repository->updateViewPreviewImagePath($viewId, $newImagePath);

        // Only after the new path is stored: the old file is unreferenced
        // from here on, and an unlink cannot be rolled back. delete() is a
        // no-op outside assets/images/personalization/, so replacing a
        // preview can never remove a product photo — including the one a
        // Phase 1/2 view may still point at, which a historical order's
        // snapshot still refers to.
        if ($existingImagePath !== '' && $existingImagePath !== $newImagePath) {
            $uploader->delete($existingImagePath);
        }
    } elseif ($removeImage && $existingImagePath !== '') {
        $repository->updateViewPreviewImagePath($viewId, null);
        $uploader->delete($existingImagePath);
    }

    ProductPersonalizationContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-personalization-view.php] ' . $e->getMessage());

    if ($newImagePath !== null) {
        $uploader->delete($newImagePath);
    }

    personalizationFail($productId, ['De weergave kon niet worden opgeslagen. Probeer het opnieuw.']);
}

personalizationRedirect($productId, 'personalization_updated=1');
