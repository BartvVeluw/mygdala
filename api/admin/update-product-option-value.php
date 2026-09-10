<?php

/**
 * POST /api/admin/update-product-option-value.php
 * Renames an existing option value.
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

$newValue = is_string($_POST['value'] ?? null) ? trim($_POST['value']) : '';
$hexColorRaw = is_string($_POST['hex_color'] ?? null) ? trim($_POST['hex_color']) : '';

if ($newValue === '' || mb_strlen($newValue) > 100) {
    $_SESSION['admin_variant_errors'] = ['Waarde is verplicht en mag maximaal 100 tekens zijn.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

$hexColor = null;
if ($hexColorRaw !== '') {
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $hexColorRaw)) {
        $_SESSION['admin_variant_errors'] = ['Hex-kleurcode is ongeldig (verwacht formaat: #A77A49).'];
        header('Location: /admin/product-form.php?id=' . $productId);
        exit;
    }
    $hexColor = strtoupper($hexColorRaw);
}

$repository->updateValueText($valueId, $newValue, $hexColor);

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
