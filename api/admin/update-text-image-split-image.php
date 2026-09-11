<?php

/**
 * POST /api/admin/update-text-image-split-image.php
 *
 * Edits one image of a Text + image split section: which media item it shows
 * (`media_id`, from the picker) and its own local alt text.
 *
 * The local alt fields are deliberately still here. A media item carries a
 * default alt text and it is used whenever these are empty — but the same
 * photo can mean something different in two places, so the override stays
 * (MEDIA.md). Nothing was migrated away from these columns.
 *
 * No file is uploaded, replaced or deleted here any more. Swapping the image
 * changes a reference; the file belongs to the Media Library, which may well
 * still be using it somewhere else — which is exactly why deleting it from
 * under this block would be wrong.
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

$imageId = filter_input(INPUT_POST, 'image_id', FILTER_VALIDATE_INT);
if ($imageId === false || $imageId === null || $imageId < 1) {
    http_response_code(400);
    exit('Invalid image id.');
}

$repository = new TextImageSplitRepository();
$image = $repository->findImageById($imageId);

if ($image === null) {
    http_response_code(404);
    exit('Image not found.');
}

$section = $repository->findById((int) $image['text_image_split_id']);
if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$sectionKey = $section['page_slug'] . ':' . $section['section_key'];
$redirect = '/admin/text-image-split.php?section=' . urlencode($sectionKey);

$chosen = BlockImage::fromRequest($_POST['media_id'] ?? null);

if ($chosen['media_id'] === null) {
    $_SESSION['admin_tis_image_errors'] = ['Kies een afbeelding uit de mediabibliotheek, of verwijder deze afbeelding uit de sectie.'];
    header('Location: ' . $redirect);
    exit;
}

$fields = $chosen + [
    'alt_nl' => trim((string) ($_POST['alt_nl'] ?? '')),
    'alt_en' => trim((string) ($_POST['alt_en'] ?? '')),
];

try {
    $repository->updateImage($imageId, $fields);
    TextImageSplitContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-text-image-split-image.php] ' . $e->getMessage());

    $_SESSION['admin_tis_image_errors'] = [AdminTranslator::trans('validation.afbeelding_kon_opgeslagen_probeer_opnieuw')];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
