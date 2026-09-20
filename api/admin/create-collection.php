<?php

/**
 * POST /api/admin/create-collection.php
 *
 * Creates a shop collection from admin/collection.php's "Nieuwe collectie"
 * form (multipart/form-data — plain HTML form post, no JS required, same
 * pattern as create-product.php). On validation failure it redirects back to
 * the form with a session-flashed error list plus the submitted values (PRG),
 * so nothing is lost and nothing is ever double-submitted.
 *
 * The image is optional here (a collection can be published without one);
 * it is stored through the project's existing App\Service\SectionImageUploader
 * — the same generic CMS image uploader Text + image split and the Portfolio
 * use, writing to assets/images/sections/ with its magic-byte type check,
 * random filename and size cap. No second upload implementation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_collection_validation.php';

use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\AdminAuth;
use App\Service\CollectionContent;
use App\Service\CollectionService;
use App\Service\Csrf;
use App\Service\SectionImageUploader;
use App\Service\ShopLocalization;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('collections.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

// `true`: a new collection is written in the DEFAULT website language, so the
// slug it generates comes from a name the shop will really show (Multilingual
// 2.0 phase 5 wave C). Translating it happens on the collection afterwards.
[$errors, $fields] = validateCollectionInput($_POST, true);

$db = Database::connection();
$collectionRepository = new CollectionRepository($db);

// Never trust the ids or the ordering values the browser sent: every id is
// confirmed against the products table, and the position in this validated
// list — not any submitted sort_order — becomes the stored sort_order.
$productIds = CollectionService::validateProductIds($fields['product_ids'], new ProductRepository($db));

// A new collection is created in the DEFAULT language, so that is the
// language its address is checked against and stored for (Multilingual 2.0
// phase 6, docs/multilingual/ROUTING.md).
$language = (string) $fields['language_code'];

$slug = $fields['slug'];
if ($slug === '') {
    $slug = CollectionService::generateSlug($collectionRepository, $fields['name'], $language);
} else {
    $slugError = CollectionService::validateSlug($collectionRepository, $slug, null, $language);
    if ($slugError !== null) {
        $errors[] = $slugError;
    }
}

$uploader = new SectionImageUploader();
$imagePath = null;

// UPLOAD_ERR_NO_FILE is the normal "left the file field empty" case — the
// image is optional, so only a real upload attempt is validated/stored.
$hasUpload = isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($hasUpload) {
    try {
        $imagePath = $uploader->store($_FILES['image']);
    } catch (\RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
}

// The optional SEO/social image from the SEO card. Same optional-upload
// contract as the collection image above, stored on its own column so it
// never becomes the collection's normal image.
$ogImagePath = null;
$hasOgUpload = isset($_FILES['og_image'])
    && ($_FILES['og_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($hasOgUpload) {
    try {
        $ogImagePath = $uploader->store($_FILES['og_image']);
    } catch (\RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
}

if ($errors !== []) {
    // Don't leave an orphaned upload behind if the rest of the form was invalid.
    if ($imagePath !== null) {
        $uploader->delete($imagePath);
    }
    if ($ogImagePath !== null) {
        $uploader->delete($ogImagePath);
    }

    $_SESSION['admin_collection_errors'] = $errors;
    $_SESSION['admin_collection_old'] = $fields;
    header('Location: /admin/collection.php');
    exit;
}

try {
    // Row and words are ONE transaction: a collection is never in the shop
    // without the name that titles it there.
    $db->beginTransaction();

    $collectionId = $collectionRepository->create([
        'slug' => $slug,
        'image_path' => $imagePath,
        'is_active' => $fields['is_active'],
    ]);

    ShopLocalization::saveCollection($collectionId, $language, [
        // The default language's address. Every other language stays without
        // one until an editor writes it, and therefore has no public URL.
        ShopLocalization::SLUG => $slug,
        ShopLocalization::NAME => $fields['name'],
        ShopLocalization::DESCRIPTION => (string) $fields['description'],
        ShopLocalization::META_TITLE => $fields['meta_title'],
        ShopLocalization::META_DESCRIPTION => $fields['meta_description'],
    ]);

    if ($ogImagePath !== null) {
        $collectionRepository->updateOgImagePath($collectionId, $ogImagePath);
    }

    $collectionRepository->setCollectionProducts($collectionId, $productIds);

    $db->commit();
    CollectionContent::clearCache();
    ShopLocalization::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/create-collection.php] ' . $e->getMessage());

    if ($imagePath !== null) {
        $uploader->delete($imagePath);
    }
    if ($ogImagePath !== null) {
        $uploader->delete($ogImagePath);
    }

    $_SESSION['admin_collection_errors'] = ['Collectie kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_collection_old'] = $fields;
    header('Location: /admin/collection.php');
    exit;
}

header('Location: /admin/collection.php?id=' . $collectionId . '&created=1');
exit;
