<?php

/**
 * POST /api/admin/test-payment-connection.php
 *
 * "Verbinding testen" on Shop → Betalingen: one read-only call to Mollie
 * (App\Service\Payment\MollieConnectionResult) that proves a key works. It
 * creates no order, no payment and no refund, fires no webhook and changes
 * nothing at Mollie or here: nothing is written, not even the key.
 *
 * WHICH KEY, from `test_mode`:
 *
 *   active      the key payments are made with now (the status card)
 *   test, live  the key typed in that field, when one is typed — so a key
 *               can be tried before it is saved — or else the stored key of
 *               that mode
 *
 * The typed key travels in the POST body only, like every other field of the
 * form; never in a URL. The answer never repeats it.
 *
 * TWO ANSWERS. With `Accept: application/json` (admin/assets/payments.js) the
 * AdminEditorResponse shape, always 200 — a refused key is a test that ran,
 * not a failed request — with `data.result` naming the outcome. Without the
 * script the button posts the whole editor form here (`formaction`), and the
 * outcome comes back as a session flash on the screen; what was typed is not
 * kept.
 *
 * The four guards first, with payments.manage: a typed key is a credential
 * even when it is only tested. The ModuleGuard as on
 * update-payment-settings.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Module\ModuleGuard;
use App\Service\AdminAuth;
use App\Service\AdminEditorResponse;
use App\Service\Csrf;
use App\Service\Language\AdminLocale;
use App\Service\Payment\MollieConfiguration;
use App\Service\Payment\MollieConnectionResult;
use App\Service\Payment\MolliePaymentProvider;

ModuleGuard::requireApi('shop');

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('payments.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$configuration = new MollieConfiguration();
$provider = new MolliePaymentProvider($configuration);

$requested = is_string($_POST['test_mode'] ?? null) ? $_POST['test_mode'] : 'active';
$key = null;

if ($configuration->isPinnedByEnvironment() || !in_array($requested, MollieConfiguration::MODES, true)) {
    // The key payments use now. With the environment pinned that is the
    // only key there is, whichever button asked.
    $mode = $configuration->activeMode() ?? MollieConfiguration::MODE_TEST;
    $key = $configuration->environmentKey();
} else {
    $mode = $requested;
    $typed = $_POST[$mode . '_api_key'] ?? '';
    if (is_string($typed) && trim($typed) !== '') {
        $key = trim($typed);
    }
}

$result = MollieConnectionResult::check($provider, $configuration, $key, $mode, AdminLocale::current());

if (AdminEditorResponse::wantsJson()) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(AdminEditorResponse::body($result->ok(), $result->message(), [
        'result' => $result->result,
        'mode' => $result->mode ?? $mode,
        'methods' => count($result->methods),
    ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$_SESSION['admin_payments_test_result'] = ['ok' => $result->ok(), 'message' => $result->message()];
header('Location: /admin/payments.php');
exit;
