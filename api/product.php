<?php

/**
 * GET /api/product.php?id=3
 * Read-only lookup of a single active product, as JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('shop');


use App\Repository\ProductImageRepository;
use App\Repository\ProductOptionRepository;
use App\Repository\ProductVariantRepository;
use App\Repository\ProductRepository;
use App\Service\DescriptionSanitizer;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid product id']);
    exit;
}

try {
    $product = (new ProductRepository())->findActiveById($id);

    if ($product === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found']);
        exit;
    }

    // Defense in depth: descriptions are already sanitized on write (see
    // api/admin/_product_validation.php), re-sanitizing here protects
    // against anything ever written directly to the database.
    $product['description'] = DescriptionSanitizer::sanitize($product['description'] ?? null);
    $product['description_en'] = DescriptionSanitizer::sanitize($product['description_en'] ?? null);

    $product['images'] = (new ProductImageRepository())->findByProductId($id);
    $product['options'] = (new ProductOptionRepository())->findByProductId($id);
    $product['variants'] = (new ProductVariantRepository())->findActiveByProductId($id);
    // A product with variants no longer shows its own product-level photos —
    // the frontend selects the default variant (first active by sort_order)
    // and renders that variant's gallery instead. See MAIN.MD.
    $product['has_variants'] = $product['variants'] !== [];

    echo json_encode(['data' => $product]);
} catch (\Throwable $e) {
    error_log('[api/product.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not load this product right now.']);
}
