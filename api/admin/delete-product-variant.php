<?php

/**
 * POST /api/admin/delete-product-variant.php
 * Blocked if the variant appears on an existing order (RESTRICT at the
 * database level too — see db/migrations/20260904161000_...).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\ProductVariantRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('products.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$variantId = filter_input(INPUT_POST, 'variant_id', FILTER_VALIDATE_INT);

if ($variantId === false || $variantId === null || $variantId < 1) {
    http_response_code(400);
    exit('Invalid variant id.');
}

$repository = new ProductVariantRepository();
$variant = $repository->findById($variantId);

if ($variant === null) {
    http_response_code(404);
    exit('Variant not found.');
}

$productId = (int) $variant['product_id'];

if ($repository->isReferencedByOrders($variantId)) {
    $_SESSION['admin_variant_errors'] = ['Deze variant staat in bestaande bestellingen en kan niet worden verwijderd.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

// Only the variant goes. Its pictures are the PRODUCT's (product_images),
// so they stay in the product's pool and in the Media Library; the variant's
// links to them (product_variant_images) and its own descriptions
// (product_variant_translations) cascade away with the row.
$repository->delete($variantId);

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
