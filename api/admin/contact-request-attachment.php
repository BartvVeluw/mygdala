<?php

/**
 * GET /api/admin/contact-request-attachment.php?id=<contact_request_id>
 *
 * The only way to read a stored contact-request attachment: streams the
 * file to an authenticated admin, never exposes the storage/ filesystem
 * path itself. Looked up by contact_request_id (not the attachment's own
 * id) since a request has at most one attachment today — see
 * admin/contact-request.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\ContactAttachmentStorage;
use App\Repository\ContactRequestAttachmentRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('contact.manage');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid id.');
}

$attachment = (new ContactRequestAttachmentRepository())->findByContactRequestId($id);

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
