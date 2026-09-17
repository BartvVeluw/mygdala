<?php

/**
 * POST /api/admin/update-text-image-split-paragraph.php
 *
 * Edits one paragraph's text.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the text is the words of the
 * language named in `language_code`, which must be an active language of the
 * website registry, and is required only in the default language
 * (TextImageSplitBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization). Only that language is written, so
 * saving the Dutch text never removes an English or German translation. The
 * paragraph keeps its id.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
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

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('text_image_split_paragraphs')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];

if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('text_image_split_paragraphs', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if ($errors !== []) {
    $_SESSION['admin_tis_paragraph_errors'] = $errors;
    header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey));
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $repository->updateParagraph($paragraphId);
    BlockLocalization::save('text_image_split_paragraphs', $paragraphId, $languageCode, $words);

    $db->commit();
    TextImageSplitContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-text-image-split-paragraph.php] ' . $e->getMessage());
    $_SESSION['admin_tis_paragraph_errors'] = ['Alinea kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/text-image-split.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
