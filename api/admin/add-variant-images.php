<?php

/**
 * POST /api/admin/add-variant-images.php
 *
 * Adds one or more photos to an existing variant (multipart/form-data,
 * `images[]`, multiple). New images are appended after any existing ones —
 * the first image in sort_order is always the variant's default/primary
 * photo, see VariantImageRepository. Same guard order and PRG/session-flash
 * pattern as add-product-images.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_product_image_helpers.php';

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

$variant = (new ProductVariantRepository())->findById($variantId);

if ($variant === null) {
    http_response_code(404);
    exit('Variant not found.');
}

$productId = (int) $variant['product_id'];

$fileEntries = normalizeMultiFileInput($_FILES['images'] ?? null);

if ($fileEntries === []) {
    $_SESSION['admin_variant_errors'] = ['Geen foto(\'s) geselecteerd.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

$uploader = new ProductImageUploader();
$uploadedPaths = [];
$errors = [];

foreach ($fileEntries as $fileEntry) {
    try {
        $uploadedPaths[] = $uploader->store($fileEntry);
    } catch (\RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
}

if ($errors !== []) {
    foreach ($uploadedPaths as $path) {
        $uploader->delete($path);
    }

    $_SESSION['admin_variant_errors'] = $errors;
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

try {
    $imageRepository = new VariantImageRepository();
    foreach ($uploadedPaths as $path) {
        $imageRepository->create($variantId, $path, null, null);
    }
} catch (\Throwable $e) {
    error_log('[api/admin/add-variant-images.php] ' . $e->getMessage());
    foreach ($uploadedPaths as $path) {
        $uploader->delete($path);
    }

    $_SESSION['admin_variant_errors'] = ['Foto(\'s) konden niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
