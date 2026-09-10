<?php

/**
 * GET /api/admin/form-submission-attachment.php?id=<submission_id>
 *
 * The only way to read a file that came in with a form submission: it
 * streams to an authenticated admin and the storage path itself is never
 * exposed. Attachments live outside the webroot
 * (App\Service\ContactAttachmentStorage), so there is no public URL for one
 * even to guess at.
 *
 * The same shape as api/admin/contact-request-attachment.php, with the
 * permission that matches what is being read: an attachment is part of a
 * submission, so it needs `forms.submissions`, not `forms.manage`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\FormSubmissionRepository;
use App\Service\AdminAuth;
use App\Service\ContactAttachmentStorage;

AdminAuth::requireLogin();
AdminAuth::requirePermission('forms.submissions');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid id.');
}

$attachment = (new FormSubmissionRepository())->attachmentFor($id);

if ($attachment === null) {
    http_response_code(404);
    exit('Attachment not found.');
}

$storage = new ContactAttachmentStorage();
$path = $storage->path((string) $attachment['stored_filename']);

if (!is_file($path)) {
    http_response_code(404);
    exit('Attachment not found.');
}

// Strip characters that could break out of the quoted filename in the
// Content-Disposition header; the original filename was already stripped
// of control characters/path components at upload time.
$safeFilename = str_replace(['"', '\\'], '', (string) $attachment['original_filename']);

header('Content-Type: ' . (string) $attachment['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
