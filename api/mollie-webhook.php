<?php

/**
 * POST /api/mollie-webhook.php
 *
 * Mollie calls this (form-encoded, field "id") whenever a payment's status
 * changes. We look up the matching order by mollie_payment_id, ask Mollie
 * for the payment's current status, and update the order accordingly.
 *
 * Always responds 200 once the lookup/update logic has run (even for a
 * payment id we don't recognise), per Mollie's webhook requirements —
 * Mollie retries on non-2xx responses.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('shop');


use App\Service\MollieClientFactory;
use App\Service\OrderPaymentSync;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$paymentId = $_POST['id'] ?? null;

if (!is_string($paymentId) || $paymentId === '') {
    http_response_code(400);
    exit;
}

try {
    $payment = MollieClientFactory::client()->payments->get($paymentId);
    (new OrderPaymentSync())->sync($payment);
} catch (\Throwable $e) {
    error_log('[api/mollie-webhook.php] ' . $e->getMessage());
    // Still 200 — an unknown/deleted payment id isn't something Mollie should retry forever.
}

http_response_code(200);
