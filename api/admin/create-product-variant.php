<?php

/**
 * POST /api/admin/create-product-variant.php
 *
 * Creates a variant: a purchasable combination of exactly one value per
 * existing option group of the product, with an optional price override.
 * `value_ids[<option_id>]` must be present and valid for every option the
 * product currently has — a variant always covers all of a product's option
 * groups, never a subset. Photos are added afterwards via the variant's own
 * photo section (add-variant-images.php), same as a product's own photos.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\ProductRepository;
use App\Repository\ProductOptionRepository;
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

$productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);

if ($productId === false || $productId === null || $productId < 1) {
    http_response_code(400);
    exit('Invalid product id.');
}

if ((new ProductRepository())->findByIdForAdmin($productId) === null) {
    http_response_code(404);
    exit('Product not found.');
}

$optionRepository = new ProductOptionRepository();
$options = $optionRepository->findByProductId($productId);

if ($options === []) {
    $_SESSION['admin_variant_errors'] = ['Voeg eerst een optie met waardes toe.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

$submittedValueIds = is_array($_POST['value_ids'] ?? null) ? $_POST['value_ids'] : [];
$errors = [];
$valueIds = [];

foreach ($options as $option) {
    $optionId = (int) $option['id'];
    $submitted = filter_var($submittedValueIds[$optionId] ?? null, FILTER_VALIDATE_INT);

    if ($submitted === false || $submitted < 1) {
        $errors[] = 'Kies een waarde voor "' . $option['name'] . '".';
        continue;
    }

    $matchesOption = false;
    foreach ($option['values'] as $value) {
        if ((int) $value['id'] === $submitted) {
            $matchesOption = true;
            break;
        }
    }

    if (!$matchesOption) {
        $errors[] = 'Ongeldige waarde voor "' . $option['name'] . '".';
        continue;
    }

    $valueIds[] = $submitted;
}

$priceRaw = is_string($_POST['price'] ?? null) ? trim(str_replace(',', '.', $_POST['price'])) : '';
$price = null;
if ($priceRaw !== '') {
    if (!is_numeric($priceRaw)) {
        $errors[] = 'Prijs override moet een geldig bedrag zijn.';
    } else {
        $price = (float) $priceRaw;
        if ($price <= 0 || $price > 99999.99) {
            $errors[] = 'Prijs override moet groter dan 0 en maximaal € 99.999,99 zijn.';
        }
    }
}

$active = ($_POST['active'] ?? null) === '1';

$variantRepository = new ProductVariantRepository();

if ($errors === [] && $variantRepository->comboExists($productId, $valueIds)) {
    $errors[] = 'Er bestaat al een variant met precies deze combinatie.';
}

if ($errors !== []) {
    $_SESSION['admin_variant_errors'] = $errors;
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

try {
    $variantRepository->create($productId, $valueIds, $price, $active);
} catch (\Throwable $e) {
    error_log('[api/admin/create-product-variant.php] ' . $e->getMessage());
    $_SESSION['admin_variant_errors'] = ['Variant kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/product-form.php?id=' . $productId);
    exit;
}

header('Location: /admin/product-form.php?id=' . $productId . '&updated=1');
exit;
