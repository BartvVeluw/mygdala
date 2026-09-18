<?php

/**
 * POST /api/admin/update-product.php
 *
 * Updates an existing product's text fields, SEO fields and active flag from
 * the admin edit form. Product photos are managed separately, on the same
 * edit page, via add-product-images.php / delete-product-image.php /
 * set-primary-product-image.php / move-product-image.php — this endpoint
 * never touches them, so a text-only save can never remove/replace a photo.
 * Same PRG/session-flash pattern as create-product.php.
 *
 * The SEO social image is the one image this endpoint does own, because it
 * lives in the SEO card of this same form — there is deliberately no
 * separate product-SEO save endpoint. It is written only when a file was
 * actually picked or the "remove" box was ticked; an ordinary save leaves it
 * exactly as it was, like every other image on this page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_product_validation.php';

use App\Database;
use App\Service\AdminAuth;
use App\Service\CollectionContent;
use App\Service\CollectionService;
use App\Service\Csrf;
use App\Service\ProductImageUploader;
use App\Service\ProductSeo;
use App\Service\ShopLocalization;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;

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

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid product id.');
}

$db = Database::connection();
$productRepository = new ProductRepository($db);
$existing = $productRepository->findByIdForAdmin($id);

if ($existing === null) {
    http_response_code(404);
    exit('Product not found.');
}

// `false`: an existing product, so the words written are those of the one
// language the form's hidden field names (Multilingual 2.0 phase 5 wave C).
[$errors, $fields] = validateProductInput($_POST, false);

$uploader = new ProductImageUploader();
$newOgImagePath = null;
$hasOgUpload = isset($_FILES['og_image'])
    && ($_FILES['og_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($hasOgUpload) {
    try {
        $newOgImagePath = $uploader->store($_FILES['og_image']);
    } catch (\RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
}

if ($errors !== []) {
    // Don't leave an orphaned upload behind when the rest of the form was invalid.
    if ($newOgImagePath !== null) {
        $uploader->delete($newOgImagePath);
    }

    $_SESSION['admin_product_errors'] = $errors;
    $_SESSION['admin_product_old'] = $fields;
    header('Location: /admin/product-form.php?id=' . $id);
    exit;
}

try {
    // Row and words are ONE transaction, and the words are only this
    // language's: every other translation of this product stays exactly as it
    // is, so switching the editing language cannot overwrite a translation
    // with a stale copy (Multilingual 2.0 phase 5 wave C).
    $db->beginTransaction();

    $productRepository->update($id, [
        'price' => $fields['price'],
        'active' => $fields['active'],
        'in_shop' => $fields['in_shop'],
        'in_personalization_catalog' => $fields['in_personalization_catalog'],
        'shipping_profile' => $fields['shipping_profile'],
        'shipping_weight_grams' => $fields['shipping_weight_grams'],
        'requires_parcel' => $fields['requires_parcel'],
    ]);

    ShopLocalization::saveProduct($id, $fields['language_code'], [
        ShopLocalization::NAME => $fields['name'],
        ShopLocalization::DESCRIPTION => (string) $fields['description'],
        ShopLocalization::META_TITLE => $fields['meta_title'],
        ShopLocalization::META_DESCRIPTION => $fields['meta_description'],
    ]);

    $oldOgImagePath = (string) ($existing['og_image_path'] ?? '');

    // The file that is no longer referenced once this save goes through. It
    // is deleted AFTER the commit, never inside the transaction: an unlink
    // cannot be rolled back, so a later failure would leave the database
    // pointing at a file that no longer exists. delete() is a no-op for
    // anything outside assets/images/products/, so a shared site asset can
    // never be removed by replacing a social image.
    $unreferencedOgImagePath = null;

    if ($newOgImagePath !== null) {
        $productRepository->updateOgImagePath($id, $newOgImagePath);

        if ($oldOgImagePath !== '' && $oldOgImagePath !== $newOgImagePath) {
            $unreferencedOgImagePath = $oldOgImagePath;
        }
    } elseif ($fields['remove_og_image'] && $oldOgImagePath !== '') {
        // Back to NULL: App\Service\ProductSeo then falls back to the
        // product's own photo again, and to the site-wide image after that.
        $productRepository->updateOgImagePath($id, null);
        $unreferencedOgImagePath = $oldOgImagePath;
    }

    // Synchronise this product's collection memberships: ticked collections
    // are added, unticked ones removed, and an already-selected one is left
    // exactly where it is inside that collection's order. Ids are validated
    // against the collections table first, so a forged or stale id is
    // dropped rather than trusted. Only this product's rows are touched —
    // no other product's membership can change here.
    $collectionRepository = new CollectionRepository($db);
    $collectionIds = CollectionService::validateCollectionIds($fields['collection_ids'], $collectionRepository);
    $collectionRepository->setProductCollections($id, $collectionIds);

    $db->commit();
    $uploader->delete($unreferencedOgImagePath);

    CollectionContent::clearCache();
    ProductSeo::clearCache();
    ShopLocalization::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-product.php] ' . $e->getMessage());

    if ($newOgImagePath !== null) {
        $uploader->delete($newOgImagePath);
    }

    $_SESSION['admin_product_errors'] = ['Product kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_product_old'] = $fields;
    header('Location: /admin/product-form.php?id=' . $id);
    exit;
}

header('Location: /admin/product-form.php?id=' . $id . '&updated=1');
exit;
