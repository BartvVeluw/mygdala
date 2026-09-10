<?php

/**
 * POST /api/admin/delete-form-submission.php
 *
 * Permanently deletes one submission. `form_submission_values` and
 * `form_submission_attachments` cascade at the database level, but the
 * physical attachment file does not — a database cascade cannot touch the
 * filesystem — so it is removed here, after the rows pointing at it are
 * gone (App\Service\ContactAttachmentStorage, the same order
 * api/admin/delete-contact-request.php uses).
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

$attachment = $repository->attachmentFor($id);

try {
    $repository->delete($id);

    if ($attachment !== null) {
        (new ContactAttachmentStorage())->delete((string) $attachment['stored_filename']);
    }
} catch (\Throwable $e) {
    error_log('[api/admin/delete-form-submission.php] ' . $e->getMessage());
    http_response_code(500);
    exit('Could not delete this submission right now.');
}

header('Location: /admin/form-submissions.php?deleted=1');
exit;
