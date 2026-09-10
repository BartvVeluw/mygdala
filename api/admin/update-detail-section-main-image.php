<?php

/**
 * POST /api/admin/update-detail-section-main-image.php
 *
 * Sets, replaces or removes the main image of one Detailsectie by CHOOSING a
 * Media Library item (`media_id`, from the picker in
 * admin/_media_picker.php). `remove_image` clears the reference back to NULL,
 * which makes the section render as plain text with its kenmerken beside it.
 *
 * No file is uploaded, replaced or deleted here any more. Uploading happens
 * once, inside the picker (api/admin/media-upload.php); removing a file is
 * the Media Library's own decision, and it refuses while anything still uses
 * it. See MEDIA.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\DetailSectionContent;
use App\Service\Media\BlockImage;
use App\Repository\DetailSectionRepository;
use App\Repository\PageRepository;

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

$sectionParam = (string) ($_POST['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new DetailSectionRepository();

// Never trust an arbitrary page_slug:section_key pair from the request: the
// page must exist (by its immutable pages.content_key) and so must the
// content row App\Service\SectionRegistry::create() made for it.
if ($pageSlug === null || $sectionKey === null || $pageSlug === '' || $sectionKey === ''
    || (new PageRepository())->findByContentKey($pageSlug) === null
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$redirect = '/admin/detail-section.php?section=' . urlencode($sectionParam);

$section = $repository->findBySlugAndKey($pageSlug, $sectionKey);
$sectionId = (int) $section['id'];

if (isset($_POST['remove_image'])) {
    try {
        // Clears the REFERENCE. The file stays: it belongs to the Media
        // Library and may well be on three other pages.
        $repository->clearMainImage($sectionId);
        DetailSectionContent::clearCache();
    } catch (\Throwable $e) {
        error_log('[api/admin/update-detail-section-main-image.php] ' . $e->getMessage());
        $_SESSION['admin_detail_section_main_image_errors'] = ['Afbeelding kon niet worden verwijderd.'];
    }

    header('Location: ' . $redirect . '&saved=1');
    exit;
}

$chosen = BlockImage::fromRequest($_POST['media_id'] ?? null);

if ($chosen['media_id'] === null) {
    $_SESSION['admin_detail_section_main_image_errors'] = ['Kies een afbeelding uit de mediabibliotheek, of verwijder de hoofdafbeelding.'];
    header('Location: ' . $redirect);
    exit;
}

$fields = [
    'main_media_id' => $chosen['media_id'],
    'main_image_path' => $chosen['image_path'],
    'main_image_alt_nl' => trim((string) ($_POST['main_image_alt_nl'] ?? '')),
    'main_image_alt_en' => trim((string) ($_POST['main_image_alt_en'] ?? '')),
];

try {
    $repository->updateMainImage($sectionId, $fields);
    DetailSectionContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-detail-section-main-image.php] ' . $e->getMessage());
    // Nothing to clean up: no file was created here, only a reference.
    $_SESSION['admin_detail_section_main_image_errors'] = ['Afbeelding kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
