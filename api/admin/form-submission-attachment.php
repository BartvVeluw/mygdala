<?php

/**
 * GET /api/admin/form-submission-attachment.php?submission=<id>&file=<id>
 *
 * The only way to read a file that came in with a form submission: it
 * streams to an authenticated admin and the storage path itself is never
 * exposed. Files live outside the webroot
 * (App\Service\ContactAttachmentStorage), so there is no public URL for one
 * even to guess at (FORMS.md, "Een bestand downloaden").
 *
 * LOOKED UP, NEVER OPENED BY NAME. Both ids must belong together
 * (FormSubmissionRepository::attachment()): a file id from another
 * submission, a submission without that file, or anything that is not a
 * positive integer is a 404, the same answer as a file that does not exist.
 * The row gives the stored NAME, and the storage turns only a bare name into
 * a path inside its own directory.
 *
 * NEVER RENDERED. Always `Content-Disposition: attachment`, with the type
 * only when it is one of the kinds App\Service\Forms\FormFileTypes knows
 * (anything else goes out as application/octet-stream), `nosniff`, and a
 * Content-Security-Policy that sandboxes the response should a browser open
 * it anyway. The visitor's file name is only the suggested name: stripped of
 * quotes, backslashes and control characters, with an ASCII fallback and the
 * full name as RFC 5987 `filename*`.
 *
 * The same permission as the submission itself: `forms.submissions`, not
 * `forms.manage`. It is a GET that changes nothing, so it has no CSRF token,
 * like every other admin download.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\FormSubmissionRepository;
use App\Service\AdminAuth;
use App\Service\ContactAttachmentStorage;
use App\Service\Forms\FormFileTypes;

AdminAuth::requireLogin();
AdminAuth::requirePermission('forms.submissions');

$submissionId = filter_input(INPUT_GET, 'submission', FILTER_VALIDATE_INT);
$fileId = filter_input(INPUT_GET, 'file', FILTER_VALIDATE_INT);

$notFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    exit('Attachment not found.');
};

if (!is_int($submissionId) || !is_int($fileId) || $submissionId < 1 || $fileId < 1) {
    $notFound();
}

$attachment = (new FormSubmissionRepository())->attachment($submissionId, $fileId);

if ($attachment === null) {
    $notFound();
}

$storedName = (string) $attachment['stored_filename'];
if ($storedName === '' || basename($storedName) !== $storedName) {
    $notFound();
}

$path = (new ContactAttachmentStorage())->path($storedName);

if (!is_file($path)) {
    $notFound();
}

$mime = (string) $attachment['mime_type'];
if (FormFileTypes::forMime($mime) === null) {
    $mime = 'application/octet-stream';
}

// The visitor's name, only as a suggestion: no quote, backslash or control
// character can end the header or start another one.
$name = preg_replace('/[\x00-\x1F\x7F"\\\\]/u', '', (string) $attachment['original_filename']) ?? '';
$name = trim($name) !== '' ? trim($name) : 'bijlage';
$asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?? 'bijlage';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store');
readfile($path);
exit;
