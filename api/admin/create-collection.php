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
 * The image and the share image are optional here (a collection can be
 * published without either). Both are Media Library items chosen in the
 * shared picker; this endpoint receives their ids and stores no file of its
 * own (MEDIA.md, "De mediakiezer").
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_collection_validation.php';
require_once __DIR__ . '/_shop_share_image.php';

use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\AdminAuth;
use App\Service\CollectionContent;
use App\Service\CollectionService;
use App\Service\Csrf;
use App\Service\Media\MediaService;
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

// The collection's picture is a Media Library image, chosen (or uploaded into
// the library) with the shared picker. Only an id comes in, and it counts
// only when it names an image in the library; anything else is "no picture".
$image = MediaService::findImage(filter_var($_POST['media_id'] ?? null, FILTER_VALIDATE_INT) ?: null);
$fields['media_id'] = $image?->id;

// The optional SEO/social image from the SEO card: a library image chosen in
// the shared picker (shop_share_image_choice()), stored on its own column so
// it never becomes the collection's normal image.
$share = shop_share_image_choice($_POST, []);
$fields['og_media_id'] = $share['media']?->id;

if ($share['error'] !== null) {
    $errors[] = $share['error'];
}

if ($errors !== []) {
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
        'image_path' => null,
        'is_active' => $fields['is_active'],
    ]);

    if ($image !== null) {
        $collectionRepository->updateImage($collectionId, $image->path, $image->id);
    }

    ShopLocalization::saveCollection($collectionId, $language, [
        // The default language's address. Every other language stays without
        // one until an editor writes it, and therefore has no public URL.
        ShopLocalization::SLUG => $slug,
        ShopLocalization::NAME => $fields['name'],
        ShopLocalization::DESCRIPTION => (string) $fields['description'],
        ShopLocalization::META_TITLE => $fields['meta_title'],
        ShopLocalization::META_DESCRIPTION => $fields['meta_description'],
    ]);

    if ($share['media'] !== null) {
        $collectionRepository->updateOgImagePath($collectionId, $share['media']->path, $share['media']->id);
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

    $_SESSION['admin_collection_errors'] = ['Collectie kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_collection_old'] = $fields;
    header('Location: /admin/collection.php');
    exit;
}

header('Location: /admin/collection.php?id=' . $collectionId . '&created=1');
exit;
