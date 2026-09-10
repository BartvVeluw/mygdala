<?php

/**
 * POST /api/admin/move-product-variant.php
 * Swaps display order with the previous/next variant of the same product.
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
$direction = $_POST['direction'] ?? null;

if ($variantId === false || $variantId === null || $variantId < 1 || !in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('Invalid request.');
}

$repository = new ProductVariantRepository();
$variant = $repository->findById($variantId);

if ($variant === null) {
    http_response_code(404);
    exit('Variant not found.');
}

$productId = (int) $variant['product_id'];
$repository->move($productId, $variantId, $direction);

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
