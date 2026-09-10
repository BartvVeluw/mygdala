<?php

/**
 * POST /api/admin/delete-product-personalization.php
 *
 * Removes a product's whole personalization configuration: the settings row,
 * its views and their zones (the last two by cascade), plus the dedicated
 * preview image files that are now unreferenced.
 *
 * It deletes NOTHING from `products`. The shop product keeps its name,
 * description, price, variants, photos, collections and orders, and simply
 * becomes an ordinary product again — the product page renders exactly what
 * it rendered before personalization was ever configured.
 *
 * Historical orders are untouched as well: an order line's personalization is
 * a self-contained snapshot, not a reference to this configuration, so every
 * placed order stays readable and reproducible in the CMS.
 *
 * POST-only and CSRF-protected: a destructive action must never be reachable
 * as a link a crawler, a prefetch or a pasted URL could trigger.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\ProductPersonalizationRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Personalization\PersonalizationPreviewImageUploader;
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

try {
    $orphanedImages = (new ProductPersonalizationRepository())->deleteForProduct($productId);
    ProductPersonalizationContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/delete-product-personalization.php] ' . $e->getMessage());
    $_SESSION['admin_personalization_list_errors'] = ['De personalisatie kon niet worden verwijderd. Probeer het opnieuw.'];
    header('Location: /admin/personalization.php');
    exit;
}

// Only after the database says the rows are gone: an unlink cannot be rolled
// back. delete() is a no-op outside assets/images/personalization/, so a
// Phase 1/2 view still pointing at a file under assets/images/products/
// leaves that file alone — it may be a real product photo, and it is
// certainly what a historical order's snapshot refers to.
$uploader = new PersonalizationPreviewImageUploader();
foreach ($orphanedImages as $imagePath) {
    $uploader->delete($imagePath);
}

header('Location: /admin/personalization.php?removed=1');
exit;
