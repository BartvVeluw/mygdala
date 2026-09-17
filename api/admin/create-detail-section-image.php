<?php

/**
 * POST /api/admin/create-detail-section-image.php
 *
 * Adds one image to a Detailsectie's gallery by CHOOSING a Media Library item
 * (`media_id`, from the picker in admin/_media_picker.php).
 *
 * No upload of its own any more: an image enters the site once, through the
 * picker, and every block that wants it refers to it. See MEDIA.md.
 *
 * A NEW IMAGE'S ALT TEXT IS WRITTEN IN THE DEFAULT LANGUAGE (Multilingual
 * 2.0), like a new page, and translated afterwards on the image's own card
 * (update-detail-section-image.php). It is optional: empty falls back to the
 * Media Library's alt text. The row and its alt text are one transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\DetailSectionContent;
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

$sectionId = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT);
if ($sectionId === false || $sectionId === null || $sectionId < 1) {
    http_response_code(400);
    exit('Invalid section id.');
}

$repository = new DetailSectionRepository();
$section = $repository->findById($sectionId);

if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$redirect = '/admin/detail-section.php?section=' . urlencode((string) $section['page_slug'] . ':' . (string) $section['section_key']);

$defaultLanguage = BlockLocalization::defaultLanguage();

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('detail_section_images')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];
foreach (BlockLocalization::messageKeys(BlockLocalization::problems('detail_section_images', $defaultLanguage, $words)) as $key) {
    $errors[] = AdminTranslator::trans($key);
}

if ($errors !== []) {
    $_SESSION['admin_detail_section_image_errors'] = $errors;
    header('Location: ' . $redirect);
    exit;
}

$chosen = BlockImage::fromRequest($_POST['media_id'] ?? null);

if ($chosen['media_id'] === null) {
    $_SESSION['admin_detail_section_image_errors'] = ['Kies eerst een afbeelding uit de mediabibliotheek.'];
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $imageId = $repository->createImage($sectionId, $chosen);
    BlockLocalization::save('detail_section_images', $imageId, $defaultLanguage, $words);

    $db->commit();
    DetailSectionContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/create-detail-section-image.php] ' . $e->getMessage());
    // Nothing to clean up: this endpoint created no file, only a reference.
    $_SESSION['admin_detail_section_image_errors'] = [AdminTranslator::trans('validation.afbeelding_kon_opgeslagen_probeer_opnieuw')];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
