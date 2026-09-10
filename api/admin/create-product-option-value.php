<?php

/**
 * POST /api/admin/create-product-option-value.php
 * Adds a value (e.g. "Berken") to an existing option group.
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
$value = is_string($_POST['value'] ?? null) ? trim($_POST['value']) : '';
$hexColorRaw = is_string($_POST['hex_color'] ?? null) ? trim($_POST['hex_color']) : '';

if ($value === '' || mb_strlen($value) > 100) {
    $_SESSION['admin_variant_errors'] = ['Waarde is verplicht en mag maximaal 100 tekens zijn.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

// Hex colour is only meaningful for a "Color" display-type option, but is
// validated whenever a non-empty value is submitted, regardless of the
// option's current display type — a value can carry a colour that only
// becomes visible once the option is switched to "Color".
$hexColor = null;
if ($hexColorRaw !== '') {
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $hexColorRaw)) {
        $_SESSION['admin_variant_errors'] = ['Hex-kleurcode is ongeldig (verwacht formaat: #A77A49).'];
        header('Location: /admin/product-form.php?id=' . $productId);
        exit;
    }
    $hexColor = strtoupper($hexColorRaw);
}

$repository->createValue($optionId, $value, $hexColor);

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
