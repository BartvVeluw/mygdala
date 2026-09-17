<?php

/**
 * POST /api/admin/update-step-list-item.php
 *
 * Edits one step's title/body and its visibility.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the title and body are the words
 * of the language named in `language_code`, which must be an active language
 * of the website registry, and are required only in the default language
 * (StepListBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization). Only that language is written, so
 * saving the Dutch words never removes an English or German translation. The
 * step keeps its id; is_active is the same in every language and is saved in
 * the same transaction.
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

$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
if ($itemId === false || $itemId === null || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item id.');
}

$repository = new StepListRepository();
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$section = $repository->findById((int) $item['step_list_section_id']);
if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$sectionKey = $section['page_slug'] . ':' . $section['section_key'];

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('step_list_items')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];

if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('step_list_items', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if ($errors !== []) {
    $_SESSION['admin_step_list_item_errors'] = $errors;
    header('Location: /admin/step-list.php?section=' . urlencode($sectionKey));
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $repository->updateItem($itemId, ['is_active' => isset($_POST['is_active'])]);
    BlockLocalization::save('step_list_items', $itemId, $languageCode, $words);

    $db->commit();
    StepListContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-step-list-item.php] ' . $e->getMessage());
    $_SESSION['admin_step_list_item_errors'] = ['Stap kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/step-list.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/step-list.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
