<?php

/**
 * POST /api/admin/create-product-option.php
 *
 * Adds a new option group (e.g. "Kleur") to a product. Same guard order /
 * PRG pattern as the other admin product endpoints.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\ProductRepository;
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

$productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);

if ($productId === false || $productId === null || $productId < 1) {
    http_response_code(400);
    exit('Invalid product id.');
}

if ((new ProductRepository())->findByIdForAdmin($productId) === null) {
    http_response_code(404);
    exit('Product not found.');
}

$name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
$displayType = is_string($_POST['display_type'] ?? null) ? $_POST['display_type'] : 'standard';

if ($name === '' || mb_strlen($name) > 100) {
    $_SESSION['admin_variant_errors'] = ['Optienaam is verplicht en mag maximaal 100 tekens zijn.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

try {
    (new ProductOptionRepository())->createOption($productId, $name, $displayType);
} catch (\Throwable $e) {
    error_log('[api/admin/create-product-option.php] ' . $e->getMessage());
    $_SESSION['admin_variant_errors'] = ['Optie kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
