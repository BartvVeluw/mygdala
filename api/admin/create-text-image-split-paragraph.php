<?php

/**
 * POST /api/admin/create-text-image-split-paragraph.php
 *
 * Adds a new paragraph to a Text + image split section. Same guard order /
 * PRG pattern as api/admin/create-faq-item.php.
 *
 * A NEW PARAGRAPH IS WRITTEN IN THE DEFAULT LANGUAGE (Multilingual 2.0), like
 * a new page: the text the form sends is stored as the website's default
 * language, where it is required, and every other language is added
 * afterwards on the paragraph's own card
 * (update-text-image-split-paragraph.php). The row and its words are one
 * transaction, so a paragraph never exists without its words or the other
 * way round.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
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

$defaultLanguage = BlockLocalization::defaultLanguage();

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('text_image_split_paragraphs')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];
foreach (BlockLocalization::messageKeys(BlockLocalization::problems('text_image_split_paragraphs', $defaultLanguage, $words)) as $key) {
    $errors[] = AdminTranslator::trans($key);
}

if ($errors !== []) {
    $_SESSION['admin_tis_paragraph_errors'] = $errors;
    header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey));
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $paragraphId = $repository->createParagraph($sectionId);
    BlockLocalization::save('text_image_split_paragraphs', $paragraphId, $defaultLanguage, $words);

    $db->commit();
    TextImageSplitContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/create-text-image-split-paragraph.php] ' . $e->getMessage());
    $_SESSION['admin_tis_paragraph_errors'] = ['Alinea kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
