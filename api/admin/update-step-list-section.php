<?php

/**
 * POST /api/admin/update-step-list-section.php
 *
 * Saves the WHOLE editor of one step list (admin/step-list.php?section=...)
 * in one request: its heading, whether it is shown, and its steps — their
 * words, whether each is shown, their order, new ones and the ones marked
 * for removal. One form, one save (PAGE-EDITOR.md, "Eén formulier per
 * blok-editor"); there is no endpoint per step any more.
 *
 * `editor_action` = items:up|down:<key> is the no-JavaScript path of a
 * step's ↑ and ↓ (App\Service\Blocks\EditorRows): it is performed on the
 * posted steps after validation and stored with everything else.
 *
 * ALL OR NOTHING. Everything is checked before anything is written, and the
 * writes are one transaction: a refused or failed save stores nothing, hands
 * every typed value back (steps, order and marks included) with each
 * message next to its field, and the screen shows it again as unsaved.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the eyebrow, the title and every
 * stored step's words are the language named in `language_code`, which
 * must be an active language of the website registry; which fields exist,
 * how long they may be and that they are required in the default language
 * comes from StepListBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization, and only that language is written. A
 * NEW step is written in the default language whatever the screen shows,
 * and a removed step takes its words in every language along, both
 * through App\Service\Blocks\EditorChildList.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\EditorChildList;
use App\Service\Blocks\EditorRows;
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

$sectionKey = (string) ($_POST['section'] ?? '');
$section = StepListContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // request.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new StepListRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit('Unknown section.');
    }
    $section = ['page_slug' => $dynPageSlug, 'section_key' => $dynSectionKey];
}

$repository = new StepListRepository();
$redirect = '/admin/step-list.php?section=' . urlencode($sectionKey);

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('step_list_sections')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$settings = ['is_active' => isset($_POST['is_active'])];

// The steps of THIS section; a key naming any other row is dropped. A
// fixed section opened for the first time has no row, and so no steps.
$stored = $repository->findBySlugAndKey($section['page_slug'], $section['section_key']);
$storedIds = $stored === null ? [] : array_map(
    static fn (array $item): int => (int) $item['id'],
    $repository->findItemsBySectionId((int) $stored['id'])
);
$items = EditorChildList::fromRequest($_POST, 'items', 'step_list_items', $storedIds, EditorRows::parseAction($_POST['editor_action'] ?? null));

$errors = [];
$fieldErrors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    // BlockLocalization::problems(), as a message per field.
    $fieldErrors = EditorChildList::wordErrors('step_list_sections', $languageCode, $words);
    $itemErrors = $items->problems($languageCode);

    // One line per problem at the top, the same line next to its field.
    foreach ($fieldErrors as $message) {
        if (!in_array($message, $errors, true)) {
            $errors[] = $message;
        }
    }
    array_push($errors, ...$items->summary($itemErrors, AdminTranslator::trans('block_steps.stap_noun')));
    $fieldErrors += $itemErrors;
}

$old = ['language_code' => $languageCode] + $words + $settings + ['items' => $items->old()];

if ($errors !== []) {
    $_SESSION['admin_step_list_errors'] = $errors;
    $_SESSION['admin_step_list_field_errors'] = $fieldErrors;
    $_SESSION['admin_step_list_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The section, its heading in this language and every step are one save.
    $db->beginTransaction();

    $repository->upsertSection($section['page_slug'], $section['section_key'], $settings);
    $sectionId = (int) $repository->findBySlugAndKey($section['page_slug'], $section['section_key'])['id'];
    BlockLocalization::save('step_list_sections', $sectionId, $languageCode, $words);

    $items->save(
        $languageCode,
        static function (array $row) use ($repository, $sectionId): int {
            $id = $repository->createItem($sectionId);
            $repository->updateItem($id, ['is_active' => EditorChildList::flag($row, 'active')]);

            return $id;
        },
        static fn (int $id, array $row) => $repository->updateItem($id, ['is_active' => EditorChildList::flag($row, 'active')]),
        static fn (int $id) => $repository->deleteItem($id),
        static fn (array $order) => $repository->reorderItems($sectionId, $order)
    );

    $db->commit();
    StepListContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-step-list-section.php] ' . $e->getMessage());

    $_SESSION['admin_step_list_errors'] = [AdminTranslator::trans('editor_rows.error_save_failed')];
    $_SESSION['admin_step_list_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
