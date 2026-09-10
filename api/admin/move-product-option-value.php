<?php

/**
 * POST /api/admin/move-product-option-value.php
 * Swaps display order with the previous/next value within the same option.
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

$valueId = filter_input(INPUT_POST, 'value_id', FILTER_VALIDATE_INT);
$direction = $_POST['direction'] ?? null;

if ($valueId === false || $valueId === null || $valueId < 1 || !in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('Invalid request.');
}

$repository = new ProductOptionRepository();
$value = $repository->findValueById($valueId);

if ($value === null) {
    http_response_code(404);
    exit('Value not found.');
}

$optionId = (int) $value['product_option_id'];
$option = $repository->findOptionById($optionId);
$productId = (int) $option['product_id'];

$repository->moveValue($optionId, $valueId, $direction);

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
