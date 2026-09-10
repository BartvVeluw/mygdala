<?php

/**
 * POST /api/admin/move-product-image.php
 *
 * Simple image ordering: swaps one photo with its previous/next neighbour
 * in display order (direction=up|down). Doesn't touch is_primary.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
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
$direction = $_POST['direction'] ?? '';

if ($imageId === false || $imageId === null || $imageId < 1) {
    http_response_code(400);
    exit('Invalid image id.');
}

if (!in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('Invalid direction.');
}

$imageRepository = new ProductImageRepository();
$image = $imageRepository->findById($imageId);

if ($image === null) {
    http_response_code(404);
    exit('Image not found.');
}

$productId = (int) $image['product_id'];

$imageRepository->moveImage($productId, $imageId, $direction);

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
