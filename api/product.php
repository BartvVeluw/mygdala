<?php

/**
 * GET /api/product.php?id=3[&lang=en]
 * Read-only lookup of a single active product, as JSON: one `name` and one
 * `description`, in the language of the page asking
 * (App\Service\Routing\ApiLanguage).
 *
 * The payload itself is App\Service\ProductDetail's: visibility, the words,
 * the pictures, the variants, what the stock allows and — only for a product
 * sold directly — the prices. The Uitgelicht product block prints the very
 * same payload into its own section, so the two can never disagree.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('shop');


use App\Service\ProductDetail;
use App\Service\Routing\ApiLanguage;

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
    $product = ProductDetail::forPublic($id, $language);

    if ($product === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found']);
        exit;
    }

    echo json_encode(['data' => $product]);
} catch (\Throwable $e) {
    error_log('[api/product.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not load this product right now.']);
}
