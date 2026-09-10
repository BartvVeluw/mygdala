<?php

/**
 * POST /api/admin/move-product-option.php
 * Swaps display order with the previous/next option group.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\ProductOptionRepository;

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

$optionId = filter_input(INPUT_POST, 'option_id', FILTER_VALIDATE_INT);
$direction = $_POST['direction'] ?? null;

if ($optionId === false || $optionId === null || $optionId < 1 || !in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('Invalid request.');
}

$repository = new ProductOptionRepository();
$option = $repository->findOptionById($optionId);

if ($option === null) {
    http_response_code(404);
    exit('Option not found.');
}

$productId = (int) $option['product_id'];
$repository->moveOption($productId, $optionId, $direction);

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
