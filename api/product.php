<?php

/**
 * GET /api/product.php?id=3[&lang=en]
 * Read-only lookup of a single active product, as JSON: one `name` and one
 * `description`, in the language of the page asking
 * (App\Service\Routing\ApiLanguage).
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
use App\Service\Routing\ApiLanguage;
use App\Service\ShopLocalization;

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
    $language = ApiLanguage::apply($_GET['lang'] ?? null);
    $product = (new ProductRepository())->findActiveById($id);

    if ($product === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found']);
        exit;
    }

    // The words of the page's language, the fallback applied. Descriptions
    // are sanitized on write (see api/admin/_product_validation.php) AND
    // again on the way out of App\Service\ShopLocalization, which protects
    // against anything ever written directly to the database.
    $product['name'] = ShopLocalization::product($id, ShopLocalization::NAME, $language);
    $product['description'] = ShopLocalization::productDescription($id, $language);

    // The product's ONE pool of pictures. A variant shows the subset it
    // links to, in its own order, or - when it links to none - this whole
    // pool (assets/js/shop/shop.js). Adding a variant never hides a picture.
    $product['images'] = array_map('shopPicture', (new ProductImageRepository())->findByProductId($id));
    $product['options'] = (new ProductOptionRepository())->findByProductId($id);
    $product['variants'] = (new ProductVariantRepository())->findActiveByProductId($id);
    $product['has_variants'] = $product['variants'] !== [];

    // A variant's description is its own text in this language, or else the
    // product's (App\Service\ShopLocalization::variantDescription()), so the
    // page can swap it with the selection and never shows an empty one.
    ShopLocalization::preloadVariants(array_map(static fn (array $v): int => (int) $v['id'], $product['variants']));
    foreach ($product['variants'] as &$variant) {
        $variant['images'] = array_map('shopPicture', $variant['images']);
        $variant['description'] = ShopLocalization::variantDescription((int) $variant['id'], $id, $language);
    }
    unset($variant);

    echo json_encode(['data' => $product]);
} catch (\Throwable $e) {
    error_log('[api/product.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not load this product right now.']);
}

/**
 * One picture as the product page needs it: its id in the product's pool,
 * the path, and the library's alt text and dimensions when it has them.
 *
 * @param array<string, mixed> $row a ProductImageRepository / ProductVariantImageRepository row
 * @return array<string, mixed>
 */
function shopPicture(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'image_path' => (string) $row['image_path'],
        'alt_text' => $row['alt_text'] ?? null,
        'width' => isset($row['width']) ? (int) $row['width'] : null,
        'height' => isset($row['height']) ? (int) $row['height'] : null,
        'is_primary' => (int) ($row['is_primary'] ?? 0) === 1,
    ];
}
