<?php

/**
 * POST /api/admin/create-product.php
 *
 * Creates a new product from the admin "nieuw product" form
 * (multipart/form-data — plain HTML form post, no JS, same pattern as
 * api/admin/update-fulfilment-status.php). On validation failure, redirects
 * back to the form with a session-flashed error list + the submitted values
 * (PRG pattern) so nothing is lost and nothing is ever double-submitted.
 *
 * Photos: `images[]` (multiple) are stored as product_images rows, first
 * one becomes primary. See src/Repository/ProductImageRepository.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_product_validation.php';
require_once __DIR__ . '/_product_image_helpers.php';

use App\Database;
use App\Service\AdminAuth;
use App\Service\CollectionContent;
use App\Service\CollectionService;
use App\Service\Csrf;
use App\Service\ProductImageUploader;
use App\Service\ShopLocalization;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductImageRepository;

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

// `true`: a new product is written in the DEFAULT website language, so its
// slug comes from a name the shop will really show (Multilingual 2.0 phase 5
// wave C). Translating it happens on the product itself afterwards.
[$errors, $fields] = validateProductInput($_POST, true);

$uploader = new ProductImageUploader();
$uploadedPaths = [];

foreach (normalizeMultiFileInput($_FILES['images'] ?? null) as $fileEntry) {
    try {
        $uploadedPaths[] = $uploader->store($fileEntry);
    } catch (\RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
}

/**
 * The optional SEO/social image from the SEO card. Deliberately NOT added to
 * $uploadedPaths: that list becomes the product's photo gallery below, and a
 * social image is a separate, single column — it must not turn into an extra
 * product photo. It is cleaned up alongside them on failure.
 */
$ogImagePath = null;
if (isset($_FILES['og_image']) && ($_FILES['og_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    try {
        $ogImagePath = $uploader->store($_FILES['og_image']);
    } catch (\RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
}

if ($errors !== []) {
    // Don't leave orphaned uploads behind if the rest of the form was invalid.
    foreach ($uploadedPaths as $path) {
        $uploader->delete($path);
    }
    $uploader->delete($ogImagePath);

    $_SESSION['admin_product_errors'] = $errors;
    $_SESSION['admin_product_old'] = $fields;
    header('Location: /admin/product-form.php');
    exit;
}

$db = Database::connection();
$productRepository = new ProductRepository($db);
$imageRepository = new ProductImageRepository($db);

try {
    // Row and words are ONE transaction: a product is never in the catalogue
    // without the name that identifies it there. Every repository below gets
    // the same connection, so a failure anywhere leaves nothing behind.
    $db->beginTransaction();

    $slug = generateUniqueSlug($productRepository, $fields['name']);

    $productId = $productRepository->create([
        'slug' => $slug,
        'price' => $fields['price'],
        'image_path' => null,
        'active' => $fields['active'],
        'in_shop' => $fields['in_shop'],
        'in_personalization_catalog' => $fields['in_personalization_catalog'],
        'shipping_profile' => $fields['shipping_profile'],
        'shipping_weight_grams' => $fields['shipping_weight_grams'],
        'requires_parcel' => $fields['requires_parcel'],
    ]);

    ShopLocalization::saveProduct($productId, $fields['language_code'], [
        ShopLocalization::NAME => $fields['name'],
        ShopLocalization::DESCRIPTION => (string) $fields['description'],
        ShopLocalization::META_TITLE => $fields['meta_title'],
        ShopLocalization::META_DESCRIPTION => $fields['meta_description'],
    ]);

    foreach ($uploadedPaths as $path) {
        $imageRepository->create($productId, $path);
    }

    syncPrimaryImagePath($productRepository, $imageRepository, $productId);

    if ($ogImagePath !== null) {
        $productRepository->updateOgImagePath($productId, $ogImagePath);
    }

    // File the new product into the collections that were ticked on the
    // form. Same validated, never-trusted-ids path as update-product.php.
    $collectionRepository = new CollectionRepository($db);
    $collectionIds = CollectionService::validateCollectionIds($fields['collection_ids'], $collectionRepository);
    $collectionRepository->setProductCollections($productId, $collectionIds);

    $db->commit();
    CollectionContent::clearCache();
    ShopLocalization::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/create-product.php] ' . $e->getMessage());
    foreach ($uploadedPaths as $path) {
        $uploader->delete($path);
    }
    $uploader->delete($ogImagePath);

    $_SESSION['admin_product_errors'] = ['Product kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_product_old'] = $fields;
    header('Location: /admin/product-form.php');
    exit;
}

header('Location: /admin/products.php?created=1');
exit;
