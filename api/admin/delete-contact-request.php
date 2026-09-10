<?php

/**
 * POST /api/admin/delete-contact-request.php
 *
 * Permanently deletes a contact request. contact_request_attachments cascades
 * at the DB level (ON DELETE CASCADE), but the physical stored file doesn't
 * — that's cleaned up here via App\Service\ContactAttachmentStorage, after
 * the DB row (and therefore its attachment row) is gone. Requires an
 * authenticated admin session, a valid CSRF token, and a confirmation
 * dialog on the admin page itself (this endpoint does not ask again).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\ContactAttachmentStorage;
use App\Repository\ContactRequestRepository;
use App\Repository\ContactRequestAttachmentRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('contact.manage');

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
    exit('Invalid contact request id.');
}

$contactRequestRepository = new ContactRequestRepository();
$request = $contactRequestRepository->findByIdForAdmin($id);

if ($request === null) {
    header('Location: /admin/contact-requests.php');
    exit;
}

$attachment = (new ContactRequestAttachmentRepository())->findByContactRequestId($id);

try {
    $contactRequestRepository->delete($id);

    if ($attachment !== null) {
        (new ContactAttachmentStorage())->delete($attachment['stored_filename']);
    }
} catch (\Throwable $e) {
    error_log('[api/admin/delete-contact-request.php] ' . $e->getMessage());
    http_response_code(500);
    exit('Could not delete this contact request right now.');
}

header('Location: /admin/contact-requests.php?deleted=1');
exit;
