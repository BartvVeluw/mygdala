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
use App\Service\ProductImageUploader;
use App\Repository\ProductVariantRepository;
use App\Repository\VariantImageRepository;

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

$uploader = new ProductImageUploader();
foreach ($variant['images'] as $image) {
    $uploader->delete($image['image_path']);
}

// variant_images rows cascade-delete at the DB level (FK ON DELETE CASCADE)
// once the variant itself is gone — only the files on disk needed cleanup.
$repository->delete($variantId);

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
