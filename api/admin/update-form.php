<?php

/**
 * POST /api/admin/update-form.php
 *
 * Saves the Algemeen and Melding sections of one form
 * (admin/form.php?id=<id>). The FIELDS have their own endpoints — a field is
 * created, edited, moved and deleted separately, so a slip in one of them
 * can never take the form's settings with it.
 *
 * FULL-FORM HANDLER: every column it writes is on the screen it posts from.
 * Both the recipient and the Reply-To field are validated here rather than
 * trusted: an address that is not an address would reach a mail header, and
 * a Reply-To naming a field that does not exist (or is not an e-mail field)
 * would silently do nothing.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Database;
use App\Repository\FormRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormFieldTypes;
use App\Service\Forms\FormLocalization;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\Forms\FormRecipient;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('forms.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid form id.');
}

$db = Database::connection();
$repository = new FormRepository($db);

if ($repository->find($id) === null) {
    http_response_code(404);
    exit('Form not found.');
}

$fields = [
    'name' => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 150),
    'is_active' => isset($_POST['is_active']),
    'notification_email' => trim((string) ($_POST['notification_email'] ?? '')),
    'reply_to_field_key' => trim((string) ($_POST['reply_to_field_key'] ?? '')),
    'store_submissions' => isset($_POST['store_submissions']),
];

// The button text and the thank-you message are written in the active
// website language named by `language_code`, and in no other.
$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$words = [
    FormLocalization::SUBMIT_LABEL => mb_substr(trim((string) ($_POST['submit_label'] ?? '')), 0, 150),
    FormLocalization::SUCCESS_MESSAGE => mb_substr(trim((string) ($_POST['success_message'] ?? '')), 0, 1000),
];
$old = ['language_code' => $languageCode] + $fields + $words;

$errors = [];

if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
}

if ($fields['name'] === '') {
    $errors[] = AdminTranslator::trans('validation.naam_formulier_verplicht');
}

if ($fields['notification_email'] !== '' && FormRecipient::validAddress($fields['notification_email']) === null) {
    $errors[] = AdminTranslator::trans('validation.notification_email_invalid');
} elseif (FormRecipient::losesSubmissions($fields, FormRecipient::siteFallback())) {
    // An active form with nobody to notify that keeps nothing either would
    // accept a visitor's message and drop it. Refused here, where the owner
    // can still choose an address or switch storing on.
    $errors[] = AdminTranslator::trans('validation.form_submissions_go_nowhere');
}

if ($fields['reply_to_field_key'] !== '') {
    // Only a field of this form, and only one that actually holds an e-mail
    // address, may be the Reply-To.
    $candidate = null;
    foreach ($repository->fieldsFor($id) as $row) {
        if ((string) $row['field_key'] === $fields['reply_to_field_key']) {
            $candidate = $row;
            break;
        }
    }

    $type = $candidate === null ? null : FormFieldTypes::get((string) $candidate['field_type']);

    if ($type === null || !$type->holdsEmailAddress()) {
        $errors[] = AdminTranslator::trans('validation.gekozen_antwoordadres_veld_bestaat_e');
    }
}

if ($errors !== []) {
    $_SESSION['admin_form_errors'] = $errors;
    $_SESSION['admin_form_old'] = $old;
    header('Location: /admin/form.php?id=' . $id);
    exit;
}

try {
    // The form's settings and its words in this language are one save.
    $db->beginTransaction();
    $repository->update($id, $fields);
    FormLocalization::forms()->save($id, $languageCode, $words);
    $db->commit();
    FormCatalog::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/update-form.php] ' . $e->getMessage());
    $_SESSION['admin_form_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_form_old'] = $old;
    header('Location: /admin/form.php?id=' . $id);
    exit;
}

header('Location: /admin/form.php?id=' . $id . '&saved=1');
exit;
