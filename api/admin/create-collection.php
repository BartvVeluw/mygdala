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

[$errors, $fields] = validateCollectionInput($_POST);

$collectionRepository = new CollectionRepository();

// Never trust the ids or the ordering values the browser sent: every id is
// confirmed against the products table, and the position in this validated
// list — not any submitted sort_order — becomes the stored sort_order.
$productIds = CollectionService::validateProductIds($fields['product_ids'], new ProductRepository());

$slug = $fields['slug'];
if ($slug === '') {
    $slug = CollectionService::generateSlug($collectionRepository, $fields['name']);
} else {
    $slugError = CollectionService::validateSlug($collectionRepository, $slug, null);
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
    $collectionId = $collectionRepository->create([
        'name' => $fields['name'],
        'name_en' => $fields['name_en'],
        'slug' => $slug,
        'description' => $fields['description'],
        'description_en' => $fields['description_en'],
        'image_path' => $imagePath,
        'is_active' => $fields['is_active'],
        'meta_title' => $fields['meta_title'],
        'meta_title_en' => $fields['meta_title_en'],
        'meta_description' => $fields['meta_description'],
        'meta_description_en' => $fields['meta_description_en'],
    ]);

    if ($ogImagePath !== null) {
        $collectionRepository->updateOgImagePath($collectionId, $ogImagePath);
    }

    $collectionRepository->setCollectionProducts($collectionId, $productIds);
    CollectionContent::clearCache();
} catch (\Throwable $e) {
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
