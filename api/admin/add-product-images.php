<?php

/**
 * POST /api/admin/add-product-images.php
 *
 * Adds one or more photos to an existing product (multipart/form-data,
 * `images[]`, multiple). The first image a product ever gets becomes
 * primary automatically (see ProductImageRepository::create()); after that,
 * primary selection is a separate action (set-primary-product-image.php).
 * Same guard order and PRG/session-flash pattern as create-product.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_product_image_helpers.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\ProductImageUploader;
use App\Repository\ProductRepository;
use App\Repository\ProductImageRepository;

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

$productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);

if ($productId === false || $productId === null || $productId < 1) {
    http_response_code(400);
    exit('Invalid product id.');
}

$productRepository = new ProductRepository();
$product = $productRepository->findByIdForAdmin($productId);

if ($product === null) {
    http_response_code(404);
    exit('Product not found.');
}

$fileEntries = normalizeMultiFileInput($_FILES['images'] ?? null);

if ($fileEntries === []) {
    $_SESSION['admin_product_errors'] = ['Geen foto(\'s) geselecteerd.'];
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

    $_SESSION['admin_product_errors'] = $errors;
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

$imageRepository = new ProductImageRepository();

try {
    foreach ($uploadedPaths as $path) {
        $imageRepository->create($productId, $path);
    }

    syncPrimaryImagePath($productRepository, $imageRepository, $productId);
} catch (\Throwable $e) {
    error_log('[api/admin/add-product-images.php] ' . $e->getMessage());
    foreach ($uploadedPaths as $path) {
        $uploader->delete($path);
    }

    $_SESSION['admin_product_errors'] = ['Foto(\'s) konden niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
