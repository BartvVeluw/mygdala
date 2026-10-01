<?php

/**
 * POST /api/admin/update-form-block.php
 *
 * Saves one "Formulier" block (admin/form-block.php?section=<page>:<key>).
 * Same guard order and PRG/session-flash pattern as
 * api/admin/update-rich-text-section.php, and the same "a page-builder-
 * attached section is valid only when the page AND its content row already
 * exist" gate — an arbitrary page_slug:section_key pair from the request is
 * never trusted beyond that.
 *
 * The block's list's permission (ContentBlockAccess: `pages.manage` on a
 * page), not `forms.manage`: choosing which form goes on a page is
 * placing content. It changes nothing about the form itself and gives no
 * access to what people sent.
 *
 * The chosen form id is checked against the `forms` table before it is
 * stored, so the column can never point at a row that does not exist.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the heading and introduction are
 * the words of the language named in `language_code`, which must be an
 * active language of the website registry; their lengths come from
 * FormBlock::translatableFields() through App\Service\Blocks\BlockLocalization,
 * and only that language is written. The form id and is_active are the same
 * in every language and are saved in the same transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Repository\FormBlockRepository;
use App\Repository\FormRepository;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\FormBlockContent;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;

AdminAuth::requireLoginForApi();
\App\Service\ContentOwners\ContentBlockAccess::requireAnyForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$sectionParam = (string) ($_POST['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new FormBlockRepository();

if ($pageSlug === null || $pageSlug === '' || $sectionKey === null || $sectionKey === ''
    || \App\Service\ContentOwners\ContentBlockAccess::pageForKeyForApi($pageSlug) === null
    || ($section = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$submittedFormId = filter_input(INPUT_POST, 'form_id', FILTER_VALIDATE_INT);
$formId = ($submittedFormId === false || $submittedFormId === null || $submittedFormId < 1) ? null : $submittedFormId;

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('form_blocks')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

// Where the heading and introduction sit: a closed list, the same in every
// language. An unknown value is refused; a request without it keeps what is
// stored.
$headerAlign = (string) ($_POST['header_align'] ?? FormBlockContent::headerAlign((string) ($section['header_align'] ?? '')));

$settings = [
    'form_id' => $formId,
    'is_active' => isset($_POST['is_active']),
    'header_align' => $headerAlign,
];

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('form_blocks', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if (!in_array($headerAlign, FormBlockContent::HEADER_ALIGNMENTS, true)) {
    $errors[] = AdminTranslator::trans('forms.error_kop_uitlijning');
}

if ($formId !== null && (new FormRepository())->find($formId) === null) {
    $errors[] = AdminTranslator::trans('validation.gekozen_formulier_bestaat');
}

$old = ['language_code' => $languageCode] + $words + ['form_id' => (string) ($formId ?? ''), 'is_active' => $settings['is_active'], 'header_align' => $headerAlign];

if ($errors !== []) {
    $_SESSION['admin_form_block_errors'] = $errors;
    $_SESSION['admin_form_block_old'] = $old;
    header('Location: /admin/form-block.php?section=' . urlencode($sectionParam));
    exit;
}

$db = Database::connection();

try {
    // Which form, and the block's words in this language, are one save.
    $db->beginTransaction();

    $repository->upsertSection($pageSlug, $sectionKey, $settings);
    BlockLocalization::save('form_blocks', (int) $section['id'], $languageCode, $words);

    // A new block joins its page now, in this save's transaction
    // (App\Service\Blocks\ContentBlockDrafts); an existing one is found.
    $placed = \App\Service\Blocks\ContentBlockDrafts::place('form', (int) $section['id']);
    $db->commit();
    FormBlockContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-form-block.php] ' . $e->getMessage());

    $_SESSION['admin_form_block_errors'] = [\App\Service\ContentOwners\OwnerContentGuard::messageFor($e) ?? 'Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_form_block_old'] = $old;
    header('Location: /admin/form-block.php?section=' . urlencode($sectionParam));
    exit;
}

header('Location: ' . \App\Service\ContentOwners\ContentBlockAccess::afterSaveUrl($placed, '/admin/form-block.php?section=' . urlencode($sectionParam)));
exit;
