<?php

/**
 * POST /api/mollie-webhook.php
 *
 * Mollie calls this (form-encoded, field "id") whenever a payment's status
 * changes. The request says only WHICH payment changed, never how: the
 * payment is asked for again at Mollie with this installation's own key
 * (App\Service\Payment\PaymentProviders), and App\Service\OrderPaymentSync
 * applies what Mollie answers to the order that holds that payment id.
 * Nothing else in the request is read.
 *
 * WITH WHICH KEY. The order holding the payment id says in which mode its
 * payment was created (`orders.payment_mode`); the payment is asked for with
 * that mode's key, never with whatever mode the shop is in now. Only an order
 * from before the mode was recorded falls back to trying both keys.
 *
 * THE ANSWER DECIDES WHETHER MOLLIE TRIES AGAIN. Mollie delivers a webhook
 * again, a limited number of times over several hours, until it gets a 2xx.
 * So the status code says whether trying again can help:
 *
 *   400  no payment id, or something that is not one: not from Mollie,
 *        nothing is looked up
 *   200  done; and also for a payment nobody here knows and for a lookup
 *        Mollie refused for good — asking again changes nothing, so it
 *        must not be asked again. A payment id no order holds is answered
 *        before Mollie is asked at all: an anonymous POST with a made-up
 *        id must not spend the shop's requests at Mollie
 *   503  Mollie could not be reached, timed out or had a problem of its
 *        own; the payment key could not be used (none, unreadable, refused
 *        — something the owner can repair); or storing the result failed.
 *        The payment is real and must still reach its order, so Mollie is
 *        asked to deliver again later.
 *
 * Idempotent: a second delivery of the same status writes nothing new
 * (OrderPaymentSync), and every retry fetches the payment afresh.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('shop');


use App\Repository\OrderRepository;
use App\Service\OrderPaymentSync;
use App\Service\Payment\PaymentProviderException;
use App\Service\Payment\PaymentProviders;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$paymentId = $_POST['id'] ?? null;

// A Mollie payment id is "tr_" and letters and digits; anything else is not
// one of Mollie's, and is refused before a request goes out for it.
if (!is_string($paymentId) || preg_match('/^tr_[A-Za-z0-9]{1,60}$/', $paymentId) !== 1) {
    http_response_code(400);
    exit;
}

try {
    $order = (new OrderRepository())->findByMolliePaymentId($paymentId);
} catch (\Throwable $e) {
    error_log('[api/mollie-webhook.php] ' . $paymentId . ' could not be looked up: ' . PaymentProviderException::redact($e->getMessage()));
    http_response_code(503);
    header('Retry-After: 300');
    exit;
}

if ($order === null) {
    error_log('[api/mollie-webhook.php] no order holds payment ' . $paymentId . '; Mollie was not asked');
    http_response_code(200);
    exit;
}

try {
    // With the credentials of the mode the order recorded when its payment
    // was created, whatever mode the shop is in now (NULL: an older order).
    $payment = PaymentProviders::active()->fetchPayment($paymentId, $order['payment_mode'] ?? null);
} catch (PaymentProviderException $e) {
    error_log('[api/mollie-webhook.php] ' . $paymentId . ' ' . $e->getMessage());

    // Final answers: asking again gives the same one.
    if ($e->kind === PaymentProviderException::NOT_FOUND || $e->kind === PaymentProviderException::REJECTED) {
        http_response_code(200);
        exit;
    }

    http_response_code(503);
    header('Retry-After: 300');
    exit;
}

try {
    if ((new OrderPaymentSync())->sync($payment) === null) {
        error_log('[api/mollie-webhook.php] no order holds payment ' . $paymentId);
    }
} catch (\Throwable $e) {
    error_log('[api/mollie-webhook.php] ' . $paymentId . ' could not be applied: ' . PaymentProviderException::redact($e->getMessage()));
    http_response_code(503);
    header('Retry-After: 300');
    exit;
}

http_response_code(200);
