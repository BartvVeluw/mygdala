<?php

/**
 * POST /api/admin/resend-order-confirmation.php
 *
 * Explicit admin action (admin/order.php "Verstuur bevestigingsmail
 * opnieuw") — distinct from the automatic, idempotent send in
 * OrderPaymentSync::sync(). Uses App\Service\OrderConfirmationService::
 * resend(), which sends again regardless of confirmation_sent_at but never
 * creates a new invoice/invoice number (it only reuses whatever invoice
 * already exists — see that method's docblock).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\OrderConfirmationService;

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

$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

if ($orderId === false || $orderId === null || $orderId < 1) {
    http_response_code(400);
    exit('Invalid order id.');
}

$sent = (new OrderConfirmationService())->resend($orderId);

$flag = $sent ? 'email_resent=1' : 'email_resend_failed=1';
header('Location: /admin/order.php?id=' . $orderId . '&' . $flag);
exit;
