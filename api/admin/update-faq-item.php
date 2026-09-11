<?php

/**
 * POST /api/admin/update-faq-item.php
 *
 * Edits one FAQ question/answer and its visibility.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
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

$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
if ($itemId === false || $itemId === null || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item id.');
}

$repository = new FaqRepository();
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$section = $repository->findById((int) $item['faq_section_id']);
if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$sectionKey = $section['page_slug'] . ':' . $section['section_key'];

$fields = [
    'question_nl' => trim((string) ($_POST['question_nl'] ?? '')),
    'question_en' => trim((string) ($_POST['question_en'] ?? '')),
    'answer_nl' => trim((string) ($_POST['answer_nl'] ?? '')),
    'answer_en' => trim((string) ($_POST['answer_en'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

$errors = [];
if ($fields['question_nl'] === '') {
    $errors[] = AdminTranslator::trans('validation.vraag_nl_verplicht');
}
if ($fields['answer_nl'] === '') {
    $errors[] = AdminTranslator::trans('validation.antwoord_nl_verplicht');
}

if ($errors !== []) {
    $_SESSION['admin_faq_item_errors'] = $errors;
    header('Location: /admin/faq.php?section=' . urlencode($sectionKey));
    exit;
}

try {
    $repository->updateItem($itemId, $fields);
    FaqContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-faq-item.php] ' . $e->getMessage());
    $_SESSION['admin_faq_item_errors'] = ['Vraag kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/faq.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/faq.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
