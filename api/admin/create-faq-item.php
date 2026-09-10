<?php

/**
 * POST /api/admin/create-faq-item.php
 *
 * Adds a new question/answer to a FAQ section. Same guard order / PRG
 * pattern as api/admin/create-feature-grid-item.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

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

$fields = [
    'question_nl' => trim((string) ($_POST['question_nl'] ?? '')),
    'question_en' => trim((string) ($_POST['question_en'] ?? '')),
    'answer_nl' => trim((string) ($_POST['answer_nl'] ?? '')),
    'answer_en' => trim((string) ($_POST['answer_en'] ?? '')),
];

$errors = [];
if ($fields['question_nl'] === '') {
    $errors[] = 'Vraag (NL) is verplicht.';
}
if ($fields['answer_nl'] === '') {
    $errors[] = 'Antwoord (NL) is verplicht.';
}

if ($errors !== []) {
    $_SESSION['admin_faq_item_errors'] = $errors;
    header('Location: /admin/faq.php?section=' . urlencode($sectionKey));
    exit;
}

try {
    $repository->createItem($sectionId, $fields);
    FaqContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-faq-item.php] ' . $e->getMessage());
    $_SESSION['admin_faq_item_errors'] = ['Vraag kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/faq.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/faq.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
