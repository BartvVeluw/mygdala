<?php

/**
 * POST /api/admin/update-detail-section-image.php
 *
 * Saves one gallery image of a Detailsectie: which Media Library item it
 * shows (`media_id`, from the picker) and its own local alt text.
 *
 * The local alt field stays: a media item's alt text is the DEFAULT, and the
 * same photo can mean something different in two places (MEDIA.md). Swapping
 * the image changes a reference and deletes nothing — the file belongs to the
 * library and may still be in use elsewhere.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the alt text is the word of the
 * language named in `language_code`, which must be an active language of the
 * website registry (DetailSectionBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization). Only that language is written, so
 * saving the Dutch alt text never removes an English or German one. The
 * image keeps its id; the media item is the same in every language and is
 * saved in the same transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\DetailSectionContent;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\Media\BlockImage;
use App\Repository\DetailSectionRepository;

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

$repository = new DetailSectionRepository();
$image = $repository->findImageById($imageId);

if ($image === null) {
    http_response_code(404);
    exit('Image not found.');
}

$section = $repository->findById((int) $image['section_id']);
if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$redirect = '/admin/detail-section.php?section=' . urlencode((string) $section['page_slug'] . ':' . (string) $section['section_key']);

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('detail_section_images')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];

if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('detail_section_images', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if ($errors !== []) {
    $_SESSION['admin_detail_section_image_errors'] = $errors;
    header('Location: ' . $redirect);
    exit;
}

$chosen = BlockImage::fromRequest($_POST['media_id'] ?? null);

if ($chosen['media_id'] === null) {
    $_SESSION['admin_detail_section_image_errors'] = ['Kies een afbeelding uit de mediabibliotheek, of verwijder deze afbeelding uit de sectie.'];
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $repository->updateImage($imageId, $chosen);
    BlockLocalization::save('detail_section_images', $imageId, $languageCode, $words);

    $db->commit();
    DetailSectionContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-detail-section-image.php] ' . $e->getMessage());
    // Nothing to clean up: this endpoint created no file, only a reference.
    $_SESSION['admin_detail_section_image_errors'] = [AdminTranslator::trans('validation.afbeelding_kon_opgeslagen_probeer_opnieuw')];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
