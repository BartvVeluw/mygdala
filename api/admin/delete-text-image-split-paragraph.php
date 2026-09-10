<?php

/**
 * POST /api/admin/delete-text-image-split-paragraph.php
 *
 * Permanently deletes one paragraph.
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
if ($paragraphId === false || $paragraphId === null || $paragraphId < 1) {
    http_response_code(400);
    exit('Invalid paragraph id.');
}

$repository = new TextImageSplitRepository();
$paragraph = $repository->findParagraphById($paragraphId);

if ($paragraph === null) {
    http_response_code(404);
    exit('Paragraph not found.');
}

$section = $repository->findById((int) $paragraph['text_image_split_id']);
if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$sectionKey = $section['page_slug'] . ':' . $section['section_key'];

try {
    $repository->deleteParagraph($paragraphId);
    TextImageSplitContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/delete-text-image-split-paragraph.php] ' . $e->getMessage());
    $_SESSION['admin_tis_paragraph_errors'] = ['Alinea kon niet worden verwijderd.'];
    header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
