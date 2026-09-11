<?php

/**
 * POST /api/admin/create-detail-section-image.php
 *
 * Adds one image to a Detailsectie's gallery by CHOOSING a Media Library item
 * (`media_id`, from the picker in admin/_media_picker.php).
 *
 * No upload of its own any more: an image enters the site once, through the
 * picker, and every block that wants it refers to it. See MEDIA.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
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

$chosen = BlockImage::fromRequest($_POST['media_id'] ?? null);

if ($chosen['media_id'] === null) {
    $_SESSION['admin_detail_section_image_errors'] = ['Kies eerst een afbeelding uit de mediabibliotheek.'];
    header('Location: ' . $redirect);
    exit;
}

try {
    $repository->createImage($sectionId, $chosen + [
        'alt_nl' => trim((string) ($_POST['alt_nl'] ?? '')),
        'alt_en' => trim((string) ($_POST['alt_en'] ?? '')),
    ]);
    DetailSectionContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-detail-section-image.php] ' . $e->getMessage());
    // Nothing to clean up: this endpoint created no file, only a reference.
    $_SESSION['admin_detail_section_image_errors'] = [AdminTranslator::trans('validation.afbeelding_kon_opgeslagen_probeer_opnieuw')];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
