<?php

/**
 * POST /api/admin/update-text-image-split-image.php
 *
 * Edits one image of a Text + image split section: which media item it shows
 * (`media_id`, from the picker) and its own local alt text.
 *
 * The local alt text is deliberately still here. A media item carries a
 * default alt text and it is used whenever this one is empty — but the same
 * photo can mean something different in two places, so the override stays
 * (MEDIA.md).
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the alt text is the word of the
 * language named in `language_code`, which must be an active language of the
 * website registry (TextImageSplitBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization). Only that language is written, so
 * saving the Dutch alt text never removes an English or German one. The
 * media item is the same in every language and is saved in the same
 * transaction.
 *
 * No file is uploaded, replaced or deleted here any more. Swapping the image
 * changes a reference; the file belongs to the Media Library, which may well
 * still be using it somewhere else — which is exactly why deleting it from
 * under this block would be wrong.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Repository\TextImageSplitRepository;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
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

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('text_image_split_images')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];

if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('text_image_split_images', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if ($errors !== []) {
    $_SESSION['admin_tis_image_errors'] = $errors;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The media item and the alt text in this language are one save.
    $db->beginTransaction();

    $repository->updateImage($imageId, $chosen);
    BlockLocalization::save('text_image_split_images', $imageId, $languageCode, $words);

    $db->commit();
    TextImageSplitContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-text-image-split-image.php] ' . $e->getMessage());

    $_SESSION['admin_tis_image_errors'] = [AdminTranslator::trans('validation.afbeelding_kon_opgeslagen_probeer_opnieuw')];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
