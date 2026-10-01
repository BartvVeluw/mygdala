<?php

/**
 * POST /api/admin/bulk-form-submissions.php   (action, ids[], form, return_read, return_page)
 *
 * One action on a selection of stored submissions, from Beheer → Inzendingen:
 * mark them read, mark them unread, or delete them for good. `action` is one
 * of App\Service\Forms\FormSubmissionBulk::ACTIONS and nothing else; the
 * selection is checked as a whole and acted on in one transaction, or
 * refused as a whole (FORMS.md, "Inzendingen in bulk"). The detail screen
 * uses the same endpoint, with one id, for "Markeren als ongelezen".
 *
 * Behind `forms.submissions`, like delete-form-submission.php, whose order
 * this follows for deleting: rows first, then the files those rows named.
 *
 * `form` is the form the overview was filtered to. It is both the way back
 * and the SCOPE of the request: every selected submission must belong to it.
 *
 * TWO KINDS OF REFUSAL. What the screen itself can send — nothing selected
 * without the script, or a submission another tab deleted meanwhile — comes
 * back to the overview with a message. What only a forged request sends — an
 * action outside the list, an id that is not a positive whole number, more
 * ids than one page could hold, an id outside the form — is a 400 and
 * changes nothing. Neither answer repeats anything a submission says.
 *
 * The way back is rebuilt from validated values, never taken from the
 * request as a URL, the way api/admin/_media_return.php does it for the
 * Media Library.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Forms\FormSubmissionBulk;
use App\Service\Forms\FormSubmissionBulkRefused;
use App\Service\Language\AdminTranslator;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('forms.submissions');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$refuse = static function (string $reason): never {
    http_response_code(400);
    exit('Bulk action refused: ' . $reason . '.');
};

// The form scope: absent or empty is "all forms"; anything else must be a
// positive whole number.
$formField = $_POST['form'] ?? '';
$formId = null;
if ($formField !== '') {
    $formId = is_string($formField) ? filter_var($formField, FILTER_VALIDATE_INT) : false;
    if ($formId === false || $formId < 1) {
        $refuse(FormSubmissionBulkRefused::MALFORMED);
    }
}

$readFilter = (string) (is_string($_POST['return_read'] ?? null) ? $_POST['return_read'] : '');
$returnPage = filter_var($_POST['return_page'] ?? 1, FILTER_VALIDATE_INT);

$returnTo = '/admin/form-submissions.php';
$query = array_filter([
    'read' => in_array($readFilter, ['unread', 'read'], true) ? $readFilter : '',
    'form' => $formId ?? '',
    'page' => is_int($returnPage) && $returnPage > 1 ? $returnPage : '',
], static fn (string|int $value): bool => $value !== '');
if ($query !== []) {
    $returnTo .= '?' . http_build_query($query);
}

$back = static function (string $type, string $message) use ($returnTo): never {
    $_SESSION['admin_form_submissions_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $returnTo);
    exit;
};

$action = $_POST['action'] ?? '';
if (!is_string($action) || !in_array($action, FormSubmissionBulk::ACTIONS, true)) {
    $refuse(FormSubmissionBulkRefused::UNKNOWN_ACTION);
}

try {
    $ids = FormSubmissionBulk::normalizeIds($_POST['ids'] ?? null);
    $count = (new FormSubmissionBulk())->apply($action, $ids, $formId);
} catch (FormSubmissionBulkRefused $refusal) {
    match ($refusal->reason) {
        FormSubmissionBulkRefused::NOTHING_SELECTED => $back('error', AdminTranslator::trans('forms.bulk.nothing_selected')),
        FormSubmissionBulkRefused::NOT_FOUND => $back('error', AdminTranslator::trans('forms.bulk.not_found')),
        default => $refuse($refusal->reason),
    };
} catch (\Throwable $e) {
    error_log('[api/admin/bulk-form-submissions.php] ' . $e->getMessage());
    $back('error', AdminTranslator::trans('forms.bulk.failed'));
}

$done = [
    FormSubmissionBulk::MARK_READ => 'forms.bulk.marked_read',
    FormSubmissionBulk::MARK_UNREAD => 'forms.bulk.marked_unread',
    FormSubmissionBulk::DELETE => 'forms.bulk.deleted',
][$action];

$back('success', AdminTranslator::trans($done . ($count === 1 ? '_one' : ''), ['count' => $count]));
