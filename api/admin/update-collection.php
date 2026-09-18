<?php

/**
 * POST /api/admin/update-collection.php
 *
 * Saves an existing collection: its text fields, slug, published state, its
 * image (only when a new file was actually picked), and its product
 * membership + order.
 *
 * The membership write is a full synchronisation from the collection side:
 * CollectionRepository::setCollectionProducts() stores exactly the validated
 * list, so newly ticked products are added, unticked ones removed, and the
 * pivot's composite primary key makes a duplicate row impossible. Products
 * themselves are never created, modified or deleted here.
 *
 * Same PRG/session-flash pattern as update-product.php.
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

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid collection id.');
}

$db = Database::connection();
$collectionRepository = new CollectionRepository($db);
$existing = $collectionRepository->findById($id);

if ($existing === null) {
    http_response_code(404);
    exit('Collection not found.');
}

// `false`: an existing collection, so the words written are those of the one
// language the form's hidden field names (Multilingual 2.0 phase 5 wave C).
[$errors, $fields] = validateCollectionInput($_POST, false);

$productIds = CollectionService::validateProductIds($fields['product_ids'], new ProductRepository($db));

$slug = $fields['slug'];
if ($slug === '') {
    // Blank slug field: regenerate from the (possibly renamed) collection
    // name rather than refusing to save. Excluding this collection's own id
    // means an unchanged name keeps producing its current slug.
    //
    // A SLUG IS LANGUAGE-NEUTRAL, so it is always generated from the DEFAULT
    // language's name (Multilingual 2.0 phase 5 wave C): the submitted name
    // when this request is in that language, else the name already stored.
    // Saving a translation therefore cannot move a collection's address.
    $defaultLanguage = ShopLocalization::defaultLanguage();
    $slug = CollectionService::generateSlug(
        $collectionRepository,
        $fields['language_code'] === $defaultLanguage
            ? $fields['name']
            : ShopLocalization::collection($id, ShopLocalization::NAME, $defaultLanguage),
        $id
    );
} else {
    $slugError = CollectionService::validateSlug($collectionRepository, $slug, $id);
    if ($slugError !== null) {
        $errors[] = $slugError;
    }
}

$uploader = new SectionImageUploader();
$newImagePath = null;
$hasUpload = isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($hasUpload) {
    try {
        $newImagePath = $uploader->store($_FILES['image']);
    } catch (\RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
}

// The optional SEO/social image from the SEO card — same upload/replace/
// remove contract as the collection image above, and the same uploader, so
// it lands in the one folder SectionImageUploader is allowed to delete from.
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
    if ($newImagePath !== null) {
        $uploader->delete($newImagePath);
    }
    if ($newOgImagePath !== null) {
        $uploader->delete($newOgImagePath);
    }

    $_SESSION['admin_collection_errors'] = $errors;
    $_SESSION['admin_collection_old'] = $fields;
    header('Location: /admin/collection.php?id=' . $id);
    exit;
}

try {
    // Row and words are ONE transaction, and the words are only this
    // language's: every other translation of this collection stays exactly as
    // it is (Multilingual 2.0 phase 5 wave C).
    $db->beginTransaction();

    $collectionRepository->update($id, [
        'slug' => $slug,
        'is_active' => $fields['is_active'],
    ]);

    ShopLocalization::saveCollection($id, $fields['language_code'], [
        ShopLocalization::NAME => $fields['name'],
        ShopLocalization::DESCRIPTION => (string) $fields['description'],
        ShopLocalization::META_TITLE => $fields['meta_title'],
        ShopLocalization::META_DESCRIPTION => $fields['meta_description'],
    ]);

    // The files this save leaves unreferenced. They are deleted AFTER the
    // commit, never inside the transaction: an unlink cannot be rolled back,
    // so a later failure would leave the database pointing at a file that no
    // longer exists. delete() is a no-op for anything outside
    // assets/images/sections/, so a shared site asset can never be removed by
    // replacing a collection image.
    $unreferenced = [];

    if ($newImagePath !== null) {
        $collectionRepository->updateImagePath($id, $newImagePath);

        $oldImagePath = $existing['image_path'] ?? null;
        if ($oldImagePath !== null && $oldImagePath !== '' && $oldImagePath !== $newImagePath) {
            $unreferenced[] = (string) $oldImagePath;
        }
    }

    $oldOgImagePath = (string) ($existing['og_image_path'] ?? '');

    if ($newOgImagePath !== null) {
        $collectionRepository->updateOgImagePath($id, $newOgImagePath);

        if ($oldOgImagePath !== '' && $oldOgImagePath !== $newOgImagePath) {
            $unreferenced[] = $oldOgImagePath;
        }
    } elseif ($fields['remove_og_image'] && $oldOgImagePath !== '') {
        // Back to NULL: CollectionContent::socialImagePath() then falls back
        // to the collection image, a product photo, and the site image.
        $collectionRepository->updateOgImagePath($id, null);
        $unreferenced[] = $oldOgImagePath;
    }

    // Only synchronise membership when the form actually carried the product
    // picker (see admin/collection.php's products_submitted marker). A save
    // posted without it — the catalogue was empty, or the product query
    // failed while the editor was being rendered — leaves the existing
    // membership alone instead of silently emptying the collection.
    if (isset($_POST['products_submitted'])) {
        $collectionRepository->setCollectionProducts($id, $productIds);
    }

    $db->commit();

    foreach ($unreferenced as $path) {
        $uploader->delete($path);
    }

    CollectionContent::clearCache();
    ShopLocalization::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-collection.php] ' . $e->getMessage());

    if ($newImagePath !== null) {
        $uploader->delete($newImagePath);
    }
    if ($newOgImagePath !== null) {
        $uploader->delete($newOgImagePath);
    }

    $_SESSION['admin_collection_errors'] = ['Collectie kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_collection_old'] = $fields;
    header('Location: /admin/collection.php?id=' . $id);
    exit;
}

header('Location: /admin/collection.php?id=' . $id . '&updated=1');
exit;
