<?php

/**
 * POST /api/admin/create-step-list-item.php
 *
 * Adds a new step to a step list section. Same guard order / PRG pattern as
 * api/admin/create-faq-item.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\StepListContent;
use App\Repository\StepListRepository;

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

$repository = new StepListRepository();
$section = $repository->findById($sectionId);

if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$sectionKey = $section['page_slug'] . ':' . $section['section_key'];

$fields = [
    'title_nl' => trim((string) ($_POST['title_nl'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
    'body_nl' => trim((string) ($_POST['body_nl'] ?? '')),
    'body_en' => trim((string) ($_POST['body_en'] ?? '')),
];

$errors = [];
if ($fields['title_nl'] === '') {
    $errors[] = AdminTranslator::trans('validation.titel_nl_verplicht');
}
if ($fields['body_nl'] === '') {
    $errors[] = AdminTranslator::trans('validation.omschrijving_nl_verplicht');
}

if ($errors !== []) {
    $_SESSION['admin_step_list_item_errors'] = $errors;
    header('Location: /admin/step-list.php?section=' . urlencode($sectionKey));
    exit;
}

try {
    $repository->createItem($sectionId, $fields);
    StepListContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-step-list-item.php] ' . $e->getMessage());
    $_SESSION['admin_step_list_item_errors'] = ['Stap kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/step-list.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/step-list.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
