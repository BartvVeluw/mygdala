<?php

/**
 * POST /api/admin/create-product.php
 *
 * Creates a new product from the admin "nieuw product" form: the first step
 * of a product, and the only one that is not the editor's own save. A
 * product needs an id before it can have options, variants or translations
 * of its own, so this makes the row and hands the editor over to it: the
 * answer is the product's own editor (admin/product-form.php?id=…&created=1),
 * where every later change is stored by api/admin/update-product.php without
 * a page load. No invented ids on the new-product screen to get there.
 *
 * The same two answers as update-product.php: JSON for the editor script
 * (App\Service\AdminEditorResponse, with `data.redirect` to that editor, or
 * 422 with the messages by field), and for a form posted without it the PRG
 * redirect with a session-flashed error list + the submitted values, so
 * nothing is lost and nothing is ever double-submitted.
 *
 * Pictures: `gallery[]`, Media Library pictures chosen on the form
 * (`media:<id>` tokens), stored as the product's pool in that order, the
 * first one primary — App\Service\ProductGallery, the same code the edit
 * form saves through. There is no file upload of its own any more: a new
 * picture is uploaded into the library from the picker.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_product_validation.php';
require_once __DIR__ . '/_shop_share_image.php';

use App\Database;
use App\Service\AdminAuth;
use App\Service\AdminEditorResponse;
use App\Service\CollectionContent;
use App\Service\CollectionService;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\ProductGallery;
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

$json = AdminEditorResponse::wantsJson();

// `true`: a new product is written in the DEFAULT website language, so its
// slug comes from a name the shop will really show (Multilingual 2.0 phase 5
// wave C). Translating it happens on the product itself afterwards.
[$errors, $fields] = validateProductInput($_POST, true);

$galleryTokens = ProductGallery::tokens($_POST['gallery'] ?? []);
// A refused save shows the same pictures again, in the same order.
$fields['gallery'] = $galleryTokens;

/**
 * The optional SEO/social image from the SEO card: a separate, single
 * column, never one of the product's pictures — a library image chosen in the
 * shared picker (shop_share_image_choice()). Nothing is uploaded here.
 */
$share = shop_share_image_choice($_POST, []);
$fields['og_media_id'] = $share['media']?->id;

if ($share['error'] !== null) {
    $errors['og_media_id'] = $share['error'];
}

if ($errors !== []) {
    if ($json) {
        AdminEditorResponse::invalid($errors, AdminTranslator::trans('editor.invalid'));
    }

    $_SESSION['admin_product_errors'] = AdminEditorResponse::messages($errors);
    $_SESSION['admin_product_old'] = $fields;
    header('Location: /admin/product-form.php');
    exit;
}

$db = Database::connection();
$productRepository = new ProductRepository($db);

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

    (new ProductGallery($db))->save($productId, $galleryTokens);

    if ($share['media'] !== null) {
        $productRepository->updateOgImagePath($productId, $share['media']->path, $share['media']->id);
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

    if ($json) {
        AdminEditorResponse::failed(AdminTranslator::trans('editor.product_save_failed'));
    }

    $_SESSION['admin_product_errors'] = [AdminTranslator::trans('editor.product_save_failed')];
    $_SESSION['admin_product_old'] = $fields;
    header('Location: /admin/product-form.php');
    exit;
}

// Straight into the new product's own editor, where options, variants and
// translations can be added now that it exists.
$editor = '/admin/product-form.php?id=' . $productId . '&created=1';

if ($json) {
    AdminEditorResponse::saved(AdminTranslator::trans('shop.editor.created_short'), ['redirect' => $editor]);
}

header('Location: ' . $editor);
exit;
