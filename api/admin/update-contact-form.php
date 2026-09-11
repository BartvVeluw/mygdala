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
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Repository\ContactFormRepository;
use App\Repository\FormRepository;
use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\ContactFormContent;
use App\Service\Csrf;
use App\Service\Forms\FormAttachmentPolicy;

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
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$submittedFormId = filter_input(INPUT_POST, 'form_id', FILTER_VALIDATE_INT);
$formId = ($submittedFormId === false || $submittedFormId === null || $submittedFormId < 1) ? null : $submittedFormId;

$fields = [
    'title_nl' => trim((string) ($_POST['title_nl'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
    'form_id' => $formId,
    'allow_attachment' => isset($_POST['allow_attachment']),
    'is_active' => isset($_POST['is_active']),
];

$errors = [];
if ($fields['title_nl'] === '') {
    $errors[] = AdminTranslator::trans('validation.kop_boven_formulier_verplicht');
}

if ($formId !== null && (new FormRepository())->find($formId) === null) {
    $errors[] = AdminTranslator::trans('validation.gekozen_formulier_bestaat');
}

if ($errors !== []) {
    $_SESSION['admin_contact_form_errors'] = $errors;
    $_SESSION['admin_contact_form_old'] = $fields;
    header('Location: /admin/contact-form.php?section=' . urlencode($sectionParam));
    exit;
}

try {
    $repository->upsertSection($pageSlug, $sectionKey, $fields);
    ContactFormContent::clearCache();
    // Whether a form may receive a file is derived from these rows, so the
    // answer this request may just have changed must not stay cached.
    FormAttachmentPolicy::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-contact-form.php] ' . $e->getMessage());

    $_SESSION['admin_contact_form_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_contact_form_old'] = $fields;
    header('Location: /admin/contact-form.php?section=' . urlencode($sectionParam));
    exit;
}

header('Location: /admin/contact-form.php?section=' . urlencode($sectionParam) . '&saved=1');
exit;
