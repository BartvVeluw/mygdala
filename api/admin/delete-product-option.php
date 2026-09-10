<?php

/**
 * POST /api/admin/delete-product-option.php
 *
 * Deletes an option group and its values (cascade). Blocked with a friendly
 * message if any of its values is used by an existing variant — the
 * database-level RESTRICT on product_variant_values is the real guard, this
 * is just the pre-check so the admin gets a clear reason instead of a 500.
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

if ($repository->isOptionInUse($optionId)) {
    $_SESSION['admin_variant_errors'] = ['Deze optie wordt gebruikt door een bestaande variant en kan niet worden verwijderd. Verwijder eerst de variant(en).'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

try {
    $repository->deleteOption($optionId);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-product-option.php] ' . $e->getMessage());
    $_SESSION['admin_variant_errors'] = ['Optie kon niet worden verwijderd.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
