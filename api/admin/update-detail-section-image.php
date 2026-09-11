<?php

/**
 * POST /api/admin/update-detail-section-image.php
 *
 * Saves one gallery image of a Detailsectie: which Media Library item it
 * shows (`media_id`, from the picker) and its own local alt texts.
 *
 * The local alt fields stay: a media item's alt text is the DEFAULT, and the
 * same photo can mean something different in two places (MEDIA.md). Swapping
 * the image changes a reference and deletes nothing — the file belongs to the
 * library and may still be in use elsewhere.
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

$chosen = BlockImage::fromRequest($_POST['media_id'] ?? null);

if ($chosen['media_id'] === null) {
    $_SESSION['admin_detail_section_image_errors'] = ['Kies een afbeelding uit de mediabibliotheek, of verwijder deze afbeelding uit de sectie.'];
    header('Location: ' . $redirect);
    exit;
}

try {
    $repository->updateImage($imageId, $chosen + [
        'alt_nl' => trim((string) ($_POST['alt_nl'] ?? '')),
        'alt_en' => trim((string) ($_POST['alt_en'] ?? '')),
    ]);
    DetailSectionContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-detail-section-image.php] ' . $e->getMessage());
    // Nothing to clean up: this endpoint created no file, only a reference.
    $_SESSION['admin_detail_section_image_errors'] = [AdminTranslator::trans('validation.afbeelding_kon_opgeslagen_probeer_opnieuw')];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
