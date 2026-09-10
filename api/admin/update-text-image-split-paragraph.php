<?php

/**
 * POST /api/admin/update-text-image-split-paragraph.php
 *
 * Edits one paragraph's NL/EN text.
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

$fields = [
    'content_nl' => trim((string) ($_POST['content_nl'] ?? '')),
    'content_en' => trim((string) ($_POST['content_en'] ?? '')),
];

if ($fields['content_nl'] === '') {
    $_SESSION['admin_tis_paragraph_errors'] = ['Tekst (NL) is verplicht.'];
    header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey));
    exit;
}

try {
    $repository->updateParagraph($paragraphId, $fields);
    TextImageSplitContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-text-image-split-paragraph.php] ' . $e->getMessage());
    $_SESSION['admin_tis_paragraph_errors'] = ['Alinea kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
