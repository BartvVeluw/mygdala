<?php

/**
 * POST /api/admin/delete-product-image.php
 *
 * Permanently deletes one product photo (DB row + file on disk). If the
 * deleted image was the primary one and other images remain, the next one
 * (in display order) is promoted to primary automatically, and
 * products.image_path is kept in sync either way — see
 * _product_image_helpers.php.
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

$imageId = filter_input(INPUT_POST, 'image_id', FILTER_VALIDATE_INT);

if ($imageId === false || $imageId === null || $imageId < 1) {
    http_response_code(400);
    exit('Invalid image id.');
}

$imageRepository = new ProductImageRepository();
$image = $imageRepository->findById($imageId);

if ($image === null) {
    http_response_code(404);
    exit('Image not found.');
}

$productId = (int) $image['product_id'];

try {
    $imageRepository->delete($imageId);
    (new ProductImageUploader())->delete($image['image_path']);
    $imageRepository->promoteFallbackPrimaryIfNeeded($productId);

    syncPrimaryImagePath(new ProductRepository(), $imageRepository, $productId);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-product-image.php] ' . $e->getMessage());
    $_SESSION['admin_product_errors'] = ['Foto kon niet worden verwijderd. Probeer het opnieuw.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
