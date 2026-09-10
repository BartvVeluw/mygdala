<?php

/**
 * POST /api/withdrawal-request.php
 *
 * Backs the herroeping.php form. Flow: method check -> honeypot ->
 * minimum-submit-time -> identify the order (order id + the email address
 * on that order must match, otherwise this endpoint would let anyone probe
 * arbitrary order ids) -> reject if already withdrawn/duplicate-pending ->
 * persist -> best-effort notification + confirmation emails -> redirect back
 * to herroeping.php with a status the page renders as a banner (same
 * redirect-only pattern as api/contact.php's no-JS path — this form has no
 * JS/fetch variant).
 *
 * Deliberately does NOT try to determine whether (part of) the order is
 * personalised/made-to-order and does NOT auto-accept or auto-reject the
 * request — see db/migrations/20260906150000_create_withdrawal_requests_table.php.
 * Every valid submission is created with status 'nieuw' for manual review.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('shop');


use App\Mail\WithdrawalRequestBuilder;
use App\Repository\OrderRepository;
use App\Repository\WithdrawalRequestRepository;
use App\Service\Mailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed');
}

function redirectToForm(string $status, ?string $reason = null, ?int $orderId = null): never
{
    $params = ['status' => $status];
    if ($reason !== null) {
        $params['reason'] = $reason;
    }
    if ($orderId !== null) {
        $params['order'] = $orderId;
    }
    header('Location: /herroeping.php?' . http_build_query($params), true, 303);
    exit;
}

function cleanString(mixed $value, int $maxLength): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    $value = trim($value);
    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }
    return $value;
}

// Honeypot + minimum-submit-time: same frictionless, no-CAPTCHA bot signals
// as api/contact.php. A bot that trips either gets a fake success (nothing
// is stored, no email is sent) so it never learns to skip these fields.
if (cleanString($_POST['hp-note'] ?? '', 200) !== '') {
    redirectToForm('success');
}

$formTs = filter_var($_POST['form_ts'] ?? '', FILTER_VALIDATE_INT);
const MIN_SUBMIT_SECONDS = 3;
if ($formTs === false || (time() - $formTs) < MIN_SUBMIT_SECONDS) {
    redirectToForm('success');
}

$orderIdRaw = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$email = cleanString($_POST['email'] ?? '', 254);
$reason = cleanString($_POST['reason'] ?? '', 2000);

if ($orderIdRaw === false || $orderIdRaw === null || $orderIdRaw < 1 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirectToForm('error', 'validation');
}

$orderId = $orderIdRaw;

try {
    $orderRepository = new OrderRepository();
    $order = $orderRepository->findById($orderId);
} catch (\Throwable $e) {
    error_log('[api/withdrawal-request.php] Order lookup failed: ' . $e->getMessage());
    redirectToForm('error', 'validation', $orderId);
}

// Order must exist, be paid (nothing to withdraw from an unpaid/failed
// order), and the submitted email must match the order's own customer email
// — the same generic error either way, so this can't be used to probe which
// order ids exist or to act on someone else's order.
if (
    $order === null
    || $order['status'] !== 'paid'
    || strcasecmp(trim((string) $order['customer_email']), $email) !== 0
) {
    redirectToForm('error', 'validation', $orderId);
}

$withdrawalRepository = new WithdrawalRequestRepository();

try {
    if ($withdrawalRepository->hasOpenRequestForOrder($orderId)) {
        redirectToForm('error', 'duplicate', $orderId);
    }

    $requestId = $withdrawalRepository->create($orderId, $email, $reason);
} catch (\Throwable $e) {
    error_log('[api/withdrawal-request.php] Failed to store withdrawal request: ' . $e->getMessage());
    redirectToForm('error', 'validation', $orderId);
}

// From here on the request has been safely received: a failure to send
// either email is logged but never turned into an error response for the
// customer, who already got what they came for (same pattern as
// api/contact.php).
$shopEmail = trim((string) ($_ENV['SHOP_NOTIFICATION_EMAIL'] ?? ''));
$fromName = \App\Mail\EmailIdentity::name();

$emails = WithdrawalRequestBuilder::build([
    'orderId' => $orderId,
    'customerEmail' => $email,
    'reason' => $reason,
]);

$mailer = new Mailer();

if ($shopEmail !== '') {
    try {
        $mailer->send($shopEmail, $fromName, $emails['shop']['subject'], $emails['shop']['html'], $emails['shop']['text'], $email);
    } catch (PHPMailerException $e) {
        error_log('[api/withdrawal-request.php] Shop notification failed for request #' . $requestId . ': ' . Mailer::redactCredentials($e->getMessage()));
    }
} else {
    error_log('[api/withdrawal-request.php] SHOP_NOTIFICATION_EMAIL is not configured; shop notification not sent for request #' . $requestId . '.');
}

try {
    $mailer->send($email, $email, $emails['customer']['subject'], $emails['customer']['html'], $emails['customer']['text'], $shopEmail !== '' ? $shopEmail : null, $fromName);
} catch (PHPMailerException $e) {
    error_log('[api/withdrawal-request.php] Customer confirmation failed for request #' . $requestId . ': ' . Mailer::redactCredentials($e->getMessage()));
}

redirectToForm('success');
