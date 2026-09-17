<?php

/**
 * POST /api/admin/delete-detail-section-image.php
 *
 * Permanently deletes one gallery image of a Detailsectie: its row, which is
 * a reference to a Media Library item, never the file behind it.
 *
 * The image's alt text in every website language goes first, in the same
 * transaction as the row (BlockLocalization::deleteOwner()): there is no
 * foreign key that could take it along, and once the row is gone nothing
 * would find it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\DetailSectionContent;
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

$db = Database::connection();

try {
    // Removes the REFERENCE only. The file belongs to the Media Library and
    // may still be in use elsewhere; deleting one is the library's own
    // decision, and it refuses while anything still uses it (MEDIA.md). So
    // there is no file cleanup to order before this transaction.
    $db->beginTransaction();

    BlockLocalization::deleteOwner('detail_section_images', $imageId);
    $repository->deleteImage($imageId);

    $db->commit();
    DetailSectionContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/delete-detail-section-image.php] ' . $e->getMessage());
    $_SESSION['admin_detail_section_image_errors'] = ['Afbeelding kon niet worden verwijderd.'];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
