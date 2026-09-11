<?php

/**
 * POST /api/admin/create-text-image-split-paragraph.php
 *
 * Adds a new paragraph to a Text + image split section. Same guard order /
 * PRG pattern as api/admin/create-faq-item.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
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

$sectionId = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT);
if ($sectionId === false || $sectionId === null || $sectionId < 1) {
    http_response_code(400);
    exit('Invalid section id.');
}

$repository = new TextImageSplitRepository();
$section = $repository->findById($sectionId);

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
    $_SESSION['admin_tis_paragraph_errors'] = [AdminTranslator::trans('validation.tekst_nl_verplicht')];
    header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey));
    exit;
}

try {
    $repository->createParagraph($sectionId, $fields);
    TextImageSplitContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-text-image-split-paragraph.php] ' . $e->getMessage());
    $_SESSION['admin_tis_paragraph_errors'] = ['Alinea kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
