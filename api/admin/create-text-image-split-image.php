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
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Repository\TextImageSplitRepository;
use App\Service\AdminAuth;
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

$fields = $image + [
    'alt_nl' => trim((string) ($_POST['alt_nl'] ?? '')),
    'alt_en' => trim((string) ($_POST['alt_en'] ?? '')),
];

try {
    $repository->createImage($sectionId, $fields);
    TextImageSplitContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-text-image-split-image.php] ' . $e->getMessage());

    // Nothing to clean up: this endpoint created no file. The media item
    // belongs to the library and stays exactly where it is.
    $_SESSION['admin_tis_image_errors'] = [AdminTranslator::trans('validation.afbeelding_kon_opgeslagen_probeer_opnieuw')];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
