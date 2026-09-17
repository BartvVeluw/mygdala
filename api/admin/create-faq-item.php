<?php

/**
 * POST /api/admin/create-faq-item.php
 *
 * Adds a new question/answer to a FAQ section. Same guard order / PRG
 * pattern as api/admin/create-feature-grid-item.php.
 *
 * A NEW QUESTION IS WRITTEN IN THE DEFAULT LANGUAGE (Multilingual 2.0), like
 * a new page: the words the form sends are stored as the website's default
 * language, where both fields are required, and every other language is
 * added afterwards on the question's own card (update-faq-item.php). The row
 * and its words are one transaction, so a question never exists without its
 * words or the other way round.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\FaqContent;
use App\Repository\FaqRepository;

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

$repository = new FaqRepository();
$section = $repository->findById($sectionId);

if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$sectionKey = $section['page_slug'] . ':' . $section['section_key'];

$defaultLanguage = BlockLocalization::defaultLanguage();

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('faq_items')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];
foreach (BlockLocalization::messageKeys(BlockLocalization::problems('faq_items', $defaultLanguage, $words)) as $key) {
    $errors[] = AdminTranslator::trans($key);
}

if ($errors !== []) {
    $_SESSION['admin_faq_item_errors'] = $errors;
    header('Location: /admin/faq.php?section=' . urlencode($sectionKey));
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $itemId = $repository->createItem($sectionId);
    BlockLocalization::save('faq_items', $itemId, $defaultLanguage, $words);

    $db->commit();
    FaqContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/create-faq-item.php] ' . $e->getMessage());
    $_SESSION['admin_faq_item_errors'] = ['Vraag kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/faq.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/faq.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
