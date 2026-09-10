<?php

/**
 * POST /api/admin/update-withdrawal-request-status.php
 *
 * Saves the status + internal note for one withdrawal request
 * (admin/withdrawal-request.php?id=...). Same guard order and PRG pattern as
 * api/admin/update-contact-request-status.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\WithdrawalRequestRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('orders.manage');

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
    exit('Invalid id.');
}

$status = trim((string) ($_POST['status'] ?? ''));
$adminNote = trim((string) ($_POST['admin_note'] ?? ''));

if (!in_array($status, WithdrawalRequestRepository::STATUSES, true)) {
    http_response_code(400);
    exit('Invalid status.');
}

try {
    (new WithdrawalRequestRepository())->updateStatus($id, $status, $adminNote);
} catch (\Throwable $e) {
    error_log('[api/admin/update-withdrawal-request-status.php] ' . $e->getMessage());
    http_response_code(500);
    exit('Could not update this request right now.');
}

header('Location: /admin/withdrawal-request.php?id=' . $id . '&updated=1');
exit;
