<?php

/**
 * POST /api/admin/delete-text-image-split-image.php
 *
 * Removes one image FROM THIS SECTION — the row that references it, and
 * nothing else.
 *
 * The file itself stays. It belongs to the Media Library now and the same
 * photo may be on three other pages; deleting one is the library's own
 * decision, taken on admin/media.php, and it refuses while anything still
 * uses it. See MEDIA.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\TextImageSplitContent;
use App\Repository\TextImageSplitRepository;

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

try {
    $repository->deleteImage($imageId);
    TextImageSplitContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/delete-text-image-split-image.php] ' . $e->getMessage());
    $_SESSION['admin_tis_image_errors'] = ['Afbeelding kon niet worden verwijderd.'];
    header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
