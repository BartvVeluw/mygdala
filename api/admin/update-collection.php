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
use App\Service\Language\SiteLanguages;
use App\Service\Routing\LocalizedSlugInput;
use App\Service\Media\MediaService;
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

/**
 * THE ADDRESS BELONGS TO THE LANGUAGE BEING EDITED (Multilingual 2.0 phase 6,
 * docs/multilingual/ROUTING.md). Saving the English version writes
 * /en/collections/<slug> and leaves /collecties/<slug> exactly where it is;
 * a collision is a collision inside one language only.
 *
 * `collections.slug` stays in step with the DEFAULT language's address: it is
 * the neutral key every existing link and every stored redirect names. The
 * rule that decides which of the two a save writes is
 * App\Service\Routing\LocalizedSlugInput's, which the two blog taxonomy
 * editors ask as well. The page and the post editor state the same rule
 * inline (api/admin/update-page.php, api/admin/update-blog-post.php).
 *
 * Everything else about this collection is language-neutral and untouched
 * here: its id, its product membership and order, its related-products
 * heading configuration, its images and its published state.
 */
$language = (string) $fields['language_code'];
$isWritableLanguage = $language !== '' && SiteLanguages::isActive($language);
$currentSlug = ShopLocalization::collectionSlug($existing, $language);
$slug = $fields['slug'];

/**
 * A BLANK FIELD MEANS "MAKE ONE FROM THE NAME", in two cases that produce
 * the same slug from the same name and differ only in why:
 *
 *   - the DEFAULT language regenerates rather than refusing to save, which is
 *     what this endpoint has always done. Excluding this collection's own id
 *     means an unchanged name keeps producing its current slug;
 *   - a TRANSLATION gets its FIRST address, the way a new collection gets its
 *     first — and only its first: an address that already exists is never
 *     regenerated, so a rename leaves the URL where it is. Blanking the field
 *     of a translation that HAS one therefore takes its public URL away,
 *     which is what an editor asking for that means.
 */
if ($isWritableLanguage && $slug === '' && (
    LocalizedSlugInput::addressIsRequired($language)
    || LocalizedSlugInput::needsFirstAddress($slug, $language, $currentSlug, $fields['name'])
)) {
    $slug = CollectionService::generateSlug($collectionRepository, $fields['name'], $language, $id);
}

if ($isWritableLanguage && $slug !== '') {
    $slugError = CollectionService::validateSlug($collectionRepository, $slug, $id, $language);
    if ($slugError !== null) {
        $errors[] = $slugError;
    }
}

$uploader = new SectionImageUploader();

/*
 * The collection's picture, from the shared Media Library picker
 * (admin/collection.php). `image_field` says which of two shapes the screen
 * rendered:
 *
 *   media   the picker holds the picture (or none): its value is the answer,
 *           so an emptied picker ("Wissen") removes the picture;
 *   legacy  the collection still has a picture uploaded before the library.
 *           It stays unless a library image is chosen to replace it or
 *           "Huidige afbeelding verwijderen" is ticked.
 *
 * A request without the marker changes no picture. An id counts only when it
 * names an image in the library.
 */
$imageField = $_POST['image_field'] ?? null;
$chosenImage = MediaService::findImage(filter_var($_POST['media_id'] ?? null, FILTER_VALIDATE_INT) ?: null);
$removeLegacyImage = ($_POST['remove_image'] ?? null) === '1';
$fields['media_id'] = $chosenImage?->id;
$fields['remove_image'] = $removeLegacyImage;

$imageChange = null; // null = leave it; ['path' => ?string, 'media' => ?int] = write it
if ($imageField === 'media') {
    $imageChange = ['path' => $chosenImage?->path, 'media' => $chosenImage?->id];
} elseif ($imageField === 'legacy' && $chosenImage !== null) {
    $imageChange = ['path' => $chosenImage->path, 'media' => $chosenImage->id];
} elseif ($imageField === 'legacy' && $removeLegacyImage) {
    $imageChange = ['path' => null, 'media' => null];
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
        // Only the default language moves the neutral key.
        'slug' => LocalizedSlugInput::neutralSlug($slug, $language, (string) $existing['slug']),
        'is_active' => $fields['is_active'],
    ]);

    ShopLocalization::saveCollection($id, $language, [
        // NULL is "this language has no public route", not an address.
        ShopLocalization::SLUG => LocalizedSlugInput::stored($slug),
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

    if ($imageChange !== null) {
        $collectionRepository->updateImage($id, $imageChange['path'], $imageChange['media']);

        // Only a picture uploaded before the library was this collection's
        // own file. A library picture belongs to the library and may be used
        // elsewhere, so it is never deleted here (MEDIA.md, "Verwijderen").
        $oldImagePath = (string) ($existing['image_path'] ?? '');
        if (empty($existing['media_id']) && $oldImagePath !== '' && $oldImagePath !== $imageChange['path']) {
            $unreferenced[] = $oldImagePath;
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
