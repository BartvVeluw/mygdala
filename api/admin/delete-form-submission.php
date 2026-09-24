<?php

/**
 * POST /api/admin/delete-form-submission.php
 *
 * Permanently deletes one submission. `form_submission_values` and
 * `form_submission_attachments` cascade at the database level, but the
 * physical files do not — a database cascade cannot touch the filesystem —
 * so every file of this submission (one per upload field, and an older
 * contact-block attachment) is removed here, after the rows pointing at them
 * are gone (App\Service\ContactAttachmentStorage, the same order
 * api/admin/delete-contact-request.php uses). Only the files this
 * submission's own rows name; no other submission's file can be reached.
 *
 * Behind `forms.submissions`, the restrictive permission: this is where
 * somebody's name, address and question actually go away. Deletion is
 * permanent by design — there is no bin to restore from, because a bin is
 * just personal data kept somewhere less visible (FORMS.md, "Privacy").
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\FormSubmissionRepository;
use App\Service\AdminAuth;
use App\Service\ContactAttachmentStorage;
use App\Service\Csrf;

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

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid submission id.');
}

$repository = new FormSubmissionRepository();
$submission = $repository->findForAdmin($id);

if ($submission === null) {
    header('Location: /admin/form-submissions.php');
    exit;
}

// Every file of THIS submission, read before the rows cascade away: one per
// upload field, plus a contact-block attachment from before Forms 2.0 phase 2.
$attachments = $repository->attachmentsFor($id);

try {
    $repository->delete($id);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-form-submission.php] ' . $e->getMessage());
    http_response_code(500);
    exit('Could not delete this submission right now.');
}

// Only now that the rows are gone: a file whose row still existed would be a
// broken download, a row without its file is not. A file that is already
// missing is skipped without complaint (ContactAttachmentStorage::delete()),
// and a failure here only leaves a file nothing points at, which is logged.
$storage = new ContactAttachmentStorage();
foreach ($attachments as $attachment) {
    try {
        $storage->delete((string) $attachment['stored_filename']);
    } catch (\Throwable $e) {
        error_log('[api/admin/delete-form-submission.php] could not remove a file of submission #' . $id . ': ' . $e->getMessage());
    }
}

header('Location: /admin/form-submissions.php?deleted=1');
exit;
