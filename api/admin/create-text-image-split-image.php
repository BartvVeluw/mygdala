<?php

/**
 * POST /api/admin/create-text-image-split-image.php
 *
 * Adds an image to a Text + image split section by CHOOSING one from the
 * Media Library (`media_id`, from the picker in admin/_media_picker.php).
 *
 * This endpoint used to accept a file upload of its own. It no longer does,
 * and that is the point of Media Library V1: an image is uploaded once, in
 * one place, under one set of validation rules, and every block that wants
 * it refers to it. Uploading still happens inside the picker — through
 * api/admin/media-upload.php — so an editor's flow is unchanged; what
 * changed is that this block no longer owns an upload universe of its own.
 *
 * The submitted id is checked against the library
 * (App\Service\Media\BlockImage::fromRequest()) before anything is stored: a
 * number naming no media item is "no image", never a stored reference.
 *
 * A NEW IMAGE'S ALT TEXT IS WRITTEN IN THE DEFAULT LANGUAGE (Multilingual
 * 2.0), like a new page: the optional alt text the form sends is stored as
 * the website's default language (TextImageSplitBlock::translatableFields(),
 * through App\Service\Blocks\BlockLocalization), and every other language is
 * added afterwards on the image's own card
 * (update-text-image-split-image.php). The row and its alt text are one
 * transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Repository\TextImageSplitRepository;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\Media\BlockImage;
use App\Service\TextImageSplitContent;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('pages.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$sectionId = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT);
if ($sectionId === false || $sectionId === null || $sectionId < 1) {
    http_response_code(400);
    exit('Invalid section id.');
}

$repository = new TextImageSplitRepository();
$section = $repository->findById($sectionId);

if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$sectionKey = $section['page_slug'] . ':' . $section['section_key'];
$redirect = '/admin/text-image-split.php?section=' . urlencode($sectionKey);

$image = BlockImage::fromRequest($_POST['media_id'] ?? null);

if ($image['media_id'] === null) {
    $_SESSION['admin_tis_image_errors'] = ['Kies eerst een afbeelding uit de mediabibliotheek.'];
    header('Location: ' . $redirect);
    exit;
}

$defaultLanguage = BlockLocalization::defaultLanguage();

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('text_image_split_images')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];
foreach (BlockLocalization::messageKeys(BlockLocalization::problems('text_image_split_images', $defaultLanguage, $words)) as $key) {
    $errors[] = AdminTranslator::trans($key);
}

if ($errors !== []) {
    $_SESSION['admin_tis_image_errors'] = $errors;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The image and its alt text in the default language are one save.
    $db->beginTransaction();

    $imageId = $repository->createImage($sectionId, $image);
    BlockLocalization::save('text_image_split_images', $imageId, $defaultLanguage, $words);

    $db->commit();
    TextImageSplitContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/create-text-image-split-image.php] ' . $e->getMessage());

    // Nothing to clean up: this endpoint created no file. The media item
    // belongs to the library and stays exactly where it is.
    $_SESSION['admin_tis_image_errors'] = [AdminTranslator::trans('validation.afbeelding_kon_opgeslagen_probeer_opnieuw')];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
