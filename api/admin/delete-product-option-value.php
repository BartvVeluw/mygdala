<?php

/**
 * POST /api/admin/delete-product-option-value.php
 * Blocked with a friendly message if a variant uses this value — the
 * database-level RESTRICT on product_variant_values is the real guard.
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

if ($valueId === false || $valueId === null || $valueId < 1) {
    http_response_code(400);
    exit('Invalid value id.');
}

$repository = new ProductOptionRepository();
$value = $repository->findValueById($valueId);

if ($value === null) {
    http_response_code(404);
    exit('Value not found.');
}

$option = $repository->findOptionById((int) $value['product_option_id']);
$productId = (int) $option['product_id'];

if ($repository->isValueInUse($valueId)) {
    $_SESSION['admin_variant_errors'] = ['Deze waarde wordt gebruikt door een bestaande variant en kan niet worden verwijderd. Verwijder eerst de variant.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

$repository->deleteValue($valueId);

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
