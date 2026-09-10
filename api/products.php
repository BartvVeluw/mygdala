<?php

/**
 * GET /api/products.php
 * Read-only list of active products, as JSON. A product with variants shows
 * its default variant's (first active by sort_order) first image instead of
 * its own product-level photo — see MAIN.MD.
 *
 * Optional ?collection=<slug> narrows the list to one shop collection, in
 * that collection's own product order — what /collecties/<slug> loads (see
 * collectie.php and assets/js/shop/shop.js's initShopProducts()). The response
 * shape is identical either way, so the product card is rendered by exactly
 * one piece of code no matter which page asked.
 *
 * Optional ?ids=<comma-separated ids> narrows the list to those products, in
 * exactly that order — what a "Related Products" page section loads (see
 * partials/section-related-products.php, which prints the ids its CMS block
 * selected, already ordered and already filtered to visible products).
 *
 * ?ids= is NOT a trust boundary. The list is filtered to `active = 1` here,
 * server-side, exactly like the unfiltered shop grid, so a tampered or
 * hand-crafted id list can only ever return a subset of what /shop already
 * shows publicly — never an inactive or otherwise hidden product. Ids that
 * don't exist are simply absent from the response, non-numeric entries are
 * skipped, and an absurdly long list is truncated rather than turned into a
 * huge query.
 *
 * An unknown slug, and an existing but INACTIVE collection, both return an
 * empty list rather than an error: this endpoint must never become a way to
 * discover whether an unpublished collection exists. The page itself already
 * 404s for both cases (App\Service\CollectionContent::forPublicPage()).
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('shop');


use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Service\DescriptionSanitizer;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

/**
 * Parses ?ids=1,5,3 into a list of positive ints, preserving order and
 * dropping duplicates. Returns null when the parameter was not supplied at
 * all (the ordinary "whole shop" request) and [] when it was supplied but
 * held nothing usable — those are different requests: the second one asks
 * for no products and must get none, rather than silently falling back to
 * the entire catalogue.
 *
 * @return list<int>|null
 */
function relatedProductIdsFromQuery(mixed $raw): ?array
{
    if ($raw === null) {
        return null;
    }

    // ?ids[]=1 arrives as an array; that is not a form this endpoint
    // accepts, and it must not reach a string cast.
    if (!is_scalar($raw)) {
        return [];
    }

    $ids = [];
    // A block can hold a handful of products; anything past this is not a
    // real request, so the list is simply truncated instead of building an
    // unbounded IN (...) clause.
    foreach (array_slice(explode(',', (string) $raw), 0, 100) as $part) {
        $part = trim($part);
        if ($part === '' || !ctype_digit($part)) {
            continue;
        }
        $id = (int) $part;
        if ($id >= 1 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    return $ids;
}

try {
    $productIds = relatedProductIdsFromQuery($_GET['ids'] ?? null);

    $collectionSlug = trim((string) ($_GET['collection'] ?? ''));
    $collectionId = null;

    if ($productIds === null && $collectionSlug !== '') {
        $collection = (new CollectionRepository())->findBySlug($collectionSlug);

        if ($collection === null || !(bool) $collection['is_active']) {
            echo json_encode(['data' => []]);
            exit;
        }

        $collectionId = (int) $collection['id'];
    }

    $products = (new ProductRepository())->findAllActive($collectionId, $productIds);
    $variantRepository = new ProductVariantRepository();

    foreach ($products as &$product) {
        // Defense in depth: same re-sanitization as api/product.php.
        $product['description'] = DescriptionSanitizer::sanitize($product['description'] ?? null);
        $product['description_en'] = DescriptionSanitizer::sanitize($product['description_en'] ?? null);

        $defaultVariant = $variantRepository->findDefaultForProduct((int) $product['id']);
        if ($defaultVariant === null) {
            continue;
        }

        $product['has_variants'] = true;
        $product['image_path'] = $defaultVariant['images'][0]['image_path'] ?? null;
        $product['image_alt'] = $defaultVariant['images'][0]['alt_text'] ?? null;
    }
    unset($product);

    echo json_encode(['data' => $products]);
} catch (\Throwable $e) {
    error_log('[api/products.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not load products right now.']);
}
