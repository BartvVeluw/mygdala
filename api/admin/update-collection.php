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

use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\AdminAuth;
use App\Service\CollectionContent;
use App\Service\CollectionService;
use App\Service\Csrf;
use App\Service\SectionImageUploader;

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

$collectionRepository = new CollectionRepository();
$existing = $collectionRepository->findById($id);

if ($existing === null) {
    http_response_code(404);
    exit('Collection not found.');
}

[$errors, $fields] = validateCollectionInput($_POST);

$productIds = CollectionService::validateProductIds($fields['product_ids'], new ProductRepository());

$slug = $fields['slug'];
if ($slug === '') {
    // Blank slug field: regenerate from the (possibly renamed) collection
    // name rather than refusing to save. Excluding this collection's own id
    // means an unchanged name keeps producing its current slug.
    $slug = CollectionService::generateSlug($collectionRepository, $fields['name'], $id);
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
    $collectionRepository->update($id, [
        'name' => $fields['name'],
        'name_en' => $fields['name_en'],
        'slug' => $slug,
        'description' => $fields['description'],
        'description_en' => $fields['description_en'],
        'is_active' => $fields['is_active'],
        'meta_title' => $fields['meta_title'],
        'meta_title_en' => $fields['meta_title_en'],
        'meta_description' => $fields['meta_description'],
        'meta_description_en' => $fields['meta_description_en'],
    ]);

    if ($newImagePath !== null) {
        $collectionRepository->updateImagePath($id, $newImagePath);

        // Only after the new path is stored: the old file is unreferenced
        // from here on, and an unlink cannot be rolled back. delete() is a
        // no-op for anything outside assets/images/sections/, so a shared
        // site asset can never be removed by replacing a collection image.
        $oldImagePath = $existing['image_path'] ?? null;
        if ($oldImagePath !== null && $oldImagePath !== '' && $oldImagePath !== $newImagePath) {
            $uploader->delete((string) $oldImagePath);
        }
    }

    $oldOgImagePath = (string) ($existing['og_image_path'] ?? '');

    if ($newOgImagePath !== null) {
        $collectionRepository->updateOgImagePath($id, $newOgImagePath);

        if ($oldOgImagePath !== '' && $oldOgImagePath !== $newOgImagePath) {
            $uploader->delete($oldOgImagePath);
        }
    } elseif ($fields['remove_og_image'] && $oldOgImagePath !== '') {
        // Back to NULL: CollectionContent::socialImagePath() then falls back
        // to the collection image, a product photo, and the site image.
        $collectionRepository->updateOgImagePath($id, null);
        $uploader->delete($oldOgImagePath);
    }

    // Only synchronise membership when the form actually carried the product
    // picker (see admin/collection.php's products_submitted marker). A save
    // posted without it — the catalogue was empty, or the product query
    // failed while the editor was being rendered — leaves the existing
    // membership alone instead of silently emptying the collection.
    if (isset($_POST['products_submitted'])) {
        $collectionRepository->setCollectionProducts($id, $productIds);
    }

    CollectionContent::clearCache();
} catch (\Throwable $e) {
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
