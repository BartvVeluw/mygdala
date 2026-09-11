<?php

/**
 * POST /api/admin/update-product-variant.php
 *
 * Updates a variant's price override and active flag. The option-value
 * combination itself is never editable here — remove the variant and create
 * a new one for a different combination. Photos are managed separately via
 * the variant's own photo section (add/delete/update/reorder-variant-image*.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
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

if ($variantId === false || $variantId === null || $variantId < 1) {
    http_response_code(400);
    exit('Invalid variant id.');
}

$repository = new ProductVariantRepository();
$variant = $repository->findById($variantId);

if ($variant === null) {
    http_response_code(404);
    exit('Variant not found.');
}

$productId = (int) $variant['product_id'];

$priceRaw = is_string($_POST['price'] ?? null) ? trim(str_replace(',', '.', $_POST['price'])) : '';
$price = null;
$errors = [];

if ($priceRaw !== '') {
    if (!is_numeric($priceRaw)) {
        $errors[] = AdminTranslator::trans('validation.prijs_override_geldig_bedrag');
    } else {
        $price = (float) $priceRaw;
        if ($price <= 0 || $price > 99999.99) {
            $errors[] = AdminTranslator::trans('validation.prijs_override_groter_0_maximaal');
        }
    }
}

$active = ($_POST['active'] ?? null) === '1';

if ($errors !== []) {
    $_SESSION['admin_variant_errors'] = $errors;
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

try {
    $repository->updatePriceAndActive($variantId, $price, $active);
} catch (\Throwable $e) {
    error_log('[api/admin/update-product-variant.php] ' . $e->getMessage());
    $_SESSION['admin_variant_errors'] = ['Variant kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
