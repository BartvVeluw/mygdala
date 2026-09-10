<?php

/**
 * POST /api/admin/move-text-image-split-paragraph.php
 *
 * Simple paragraph ordering: swaps one paragraph with its previous/next
 * neighbour in display order (direction=up|down). Same approach as
 * api/admin/move-faq-item.php.
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

$paragraphId = filter_input(INPUT_POST, 'paragraph_id', FILTER_VALIDATE_INT);
$direction = $_POST['direction'] ?? '';

if ($paragraphId === false || $paragraphId === null || $paragraphId < 1) {
    http_response_code(400);
    exit('Invalid paragraph id.');
}

if (!in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('Invalid direction.');
}

$repository = new TextImageSplitRepository();
$paragraph = $repository->findParagraphById($paragraphId);

if ($paragraph === null) {
    http_response_code(404);
    exit('Paragraph not found.');
}

$sectionId = (int) $paragraph['text_image_split_id'];
$section = $repository->findById($sectionId);
if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$sectionKey = $section['page_slug'] . ':' . $section['section_key'];

$repository->moveParagraph($sectionId, $paragraphId, $direction);
TextImageSplitContent::clearCache();

header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
