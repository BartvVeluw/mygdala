<?php

/**
 * POST /api/admin/set-primary-product-image.php
 *
 * Marks one product photo as the primary image (the one shop cards use).
 * products.image_path is kept in sync — see _product_image_helpers.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_product_image_helpers.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
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

$imageRepository->setPrimary($productId, $imageId);
syncPrimaryImagePath(new ProductRepository(), $imageRepository, $productId);

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
