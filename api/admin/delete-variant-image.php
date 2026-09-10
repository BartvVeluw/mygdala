<?php

/**
 * POST /api/admin/delete-variant-image.php
 *
 * Permanently deletes one variant photo (DB row + file on disk). No
 * primary-promotion step needed — with no is_primary flag, the remaining
 * image with the lowest sort_order automatically becomes the new default.
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

$imageId = filter_input(INPUT_POST, 'image_id', FILTER_VALIDATE_INT);

if ($imageId === false || $imageId === null || $imageId < 1) {
    http_response_code(400);
    exit('Invalid image id.');
}

$imageRepository = new VariantImageRepository();
$image = $imageRepository->findById($imageId);

if ($image === null) {
    http_response_code(404);
    exit('Image not found.');
}

$variant = (new ProductVariantRepository())->findById((int) $image['variant_id']);
$productId = $variant !== null ? (int) $variant['product_id'] : null;

try {
    $imageRepository->delete($imageId);
    (new ProductImageUploader())->delete($image['image_path']);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-variant-image.php] ' . $e->getMessage());
    $_SESSION['admin_variant_errors'] = ['Foto kon niet worden verwijderd. Probeer het opnieuw.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
