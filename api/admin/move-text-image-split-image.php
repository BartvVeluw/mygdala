<?php

/**
 * POST /api/admin/move-text-image-split-image.php
 *
 * Simple image ordering: swaps one image with its previous/next neighbour
 * in display order (direction=up|down). This directly changes which
 * template branch renders (1 vs 2 vs 3+ images doesn't change on reorder,
 * but which image is "first" does).
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
$direction = $_POST['direction'] ?? '';

if ($imageId === false || $imageId === null || $imageId < 1) {
    http_response_code(400);
    exit('Invalid image id.');
}

if (!in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('Invalid direction.');
}

$repository = new TextImageSplitRepository();
$image = $repository->findImageById($imageId);

if ($image === null) {
    http_response_code(404);
    exit('Image not found.');
}

$sectionId = (int) $image['text_image_split_id'];
$section = $repository->findById($sectionId);
if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$sectionKey = $section['page_slug'] . ':' . $section['section_key'];

$repository->moveImage($sectionId, $imageId, $direction);
TextImageSplitContent::clearCache();

header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
