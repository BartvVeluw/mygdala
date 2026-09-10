<?php

/**
 * POST /api/admin/update-contact-request-status.php
 *
 * Toggles a contact request between "nieuw" and "gelezen". Requires an
 * authenticated admin session and a valid CSRF token.
 *
 * Request body (application/x-www-form-urlencoded, from the plain HTML
 * form on admin/contact-request.php):
 *   id=123&status=gelezen&csrf_token=...
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\ContactRequestRepository;

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
$status = $_POST['status'] ?? null;

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid contact request id.');
}

if (!is_string($status) || !in_array($status, ContactRequestRepository::STATUSES, true)) {
    http_response_code(400);
    exit('Invalid status.');
}

try {
    (new ContactRequestRepository())->updateStatus($id, $status);
} catch (\Throwable $e) {
    error_log('[api/admin/update-contact-request-status.php] ' . $e->getMessage());
    http_response_code(500);
    exit('Could not update this contact request right now.');
}

header('Location: /admin/contact-request.php?id=' . $id . '&updated=1');
exit;
