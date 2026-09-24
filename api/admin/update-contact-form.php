<?php

/**
 * POST /api/admin/update-contact-form.php
 *
 * Saves one Offerte-/contactformulier block
 * (admin/contact-form.php?section=<page>:<key>). Same guard order and
 * PRG/session-flash pattern as api/admin/update-rich-text-section.php, and
 * the same "a page-builder-attached section is valid only when the page AND
 * its content row already exist" gate — an arbitrary page_slug:section_key
 * pair from the request is never trusted beyond that.
 *
 * FULL-FORM HANDLER: every column it writes is on the screen it posts from,
 * so nothing it omits can quietly overwrite a value it never saw.
 *
 * `pages.manage`, not `forms.manage`: choosing which form goes on a page is
 * placing content, and it changes nothing about the form itself.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the heading is the words of the
 * language named in `language_code`, which must be an active language of the
 * website registry. It is required in the default language only
 * (ContactFormBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization), and only that language is written.
 * The form and is_active are the same in every
 * language and are saved in the same transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Repository\ContactFormRepository;
use App\Repository\FormRepository;
use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\ContactFormContent;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;

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

$sectionParam = (string) ($_POST['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new ContactFormRepository();

if ($pageSlug === null || $pageSlug === '' || $sectionKey === null || $sectionKey === ''
    || (new PageRepository())->findByContentKey($pageSlug) === null
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
foreach (array_keys(BlockLocalization::fields('contact_form_sections')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$settings = [
    'form_id' => $formId,
    'is_active' => isset($_POST['is_active']),
];

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('contact_form_sections', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if ($formId !== null && (new FormRepository())->find($formId) === null) {
    $errors[] = AdminTranslator::trans('validation.gekozen_formulier_bestaat');
}

$old = ['language_code' => $languageCode] + $words + $settings;

if ($errors !== []) {
    $_SESSION['admin_contact_form_errors'] = $errors;
    $_SESSION['admin_contact_form_old'] = $old;
    header('Location: /admin/contact-form.php?section=' . urlencode($sectionParam));
    exit;
}

$db = Database::connection();

try {
    // The block's settings and its heading in this language are one save.
    $db->beginTransaction();

    $repository->upsertSection($pageSlug, $sectionKey, $settings);
    BlockLocalization::save('contact_form_sections', (int) $section['id'], $languageCode, $words);

    $db->commit();
    ContactFormContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-contact-form.php] ' . $e->getMessage());

    $_SESSION['admin_contact_form_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_contact_form_old'] = $old;
    header('Location: /admin/contact-form.php?section=' . urlencode($sectionParam));
    exit;
}

header('Location: /admin/contact-form.php?section=' . urlencode($sectionParam) . '&saved=1');
exit;
