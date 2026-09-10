<?php

/**
 * POST /api/admin/update-product-option.php
 * Renames an existing option group.
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

if ($optionId === false || $optionId === null || $optionId < 1) {
    http_response_code(400);
    exit('Invalid option id.');
}

$repository = new ProductOptionRepository();
$option = $repository->findOptionById($optionId);

if ($option === null) {
    http_response_code(404);
    exit('Option not found.');
}

$productId = (int) $option['product_id'];
$name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
$displayType = is_string($_POST['display_type'] ?? null) ? $_POST['display_type'] : 'standard';

if ($name === '' || mb_strlen($name) > 100) {
    $_SESSION['admin_variant_errors'] = ['Optienaam is verplicht en mag maximaal 100 tekens zijn.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

$repository->updateOption($optionId, $name, $displayType);

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
