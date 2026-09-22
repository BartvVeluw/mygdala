<?php

/**
 * POST /api/admin/updates-step.php
 *
 * Runs ONE step of the running update (App\Update\Updater::step()) and says
 * where the update is now. admin/assets/updates.js calls it in a loop with
 * `Accept: application/json`; without JavaScript the Doorgaan button posts
 * here as a plain form and is redirected back to the Updates screen.
 *
 * The request names the update it means and the step it expects to run —
 * nothing else. Both must match the server's state or nothing happens (409),
 * so a second tab, a double click or a replayed POST cannot run a step twice
 * or out of order; a step already running elsewhere answers 423. Everything
 * the step acts on comes from the signed manifest and the updater's own
 * files, never from this request (docs/updates/ARCHITECTURE.md).
 *
 * One of the endpoints that stay reachable during maintenance
 * (App\Update\MaintenanceGuard::EXEMPT): it IS the update. It lets go of the
 * session lock before the step starts, so a long step does not block the
 * administrator's other admin requests.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Update\UpdateException;
use App\Update\Updater;
use App\Update\UpdateState;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('updates.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
$updateId = (string) ($_POST['update_id'] ?? '');
$step = (string) ($_POST['step'] ?? '');

if (preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}$/', $updateId) !== 1
    || !in_array($step, [...UpdateState::STEPS, ...UpdateState::ROLLBACK_STEPS], true)
) {
    http_response_code(400);
    exit('Unknown update or step.');
}

session_write_close();

// Load the catalog BEFORE the step: after `apply` the files on disk are the
// new release's, and this answer should not mix in a class or catalog
// autoloaded from them halfway through the request.
AdminTranslator::trans('update.step.' . $step);

$updater = Updater::fromConfig();
$httpStatus = 200;
$error = null;

try {
    $state = $updater->step($updateId, $step);
} catch (\Throwable $e) {
    if (!$e instanceof UpdateException) {
        error_log('[updates-step] ' . get_class($e) . ': ' . $e->getMessage());
        $e = new UpdateException('update.error.unexpected', [], $e->getMessage());
    }

    $httpStatus = match ($e->messageKey) {
        'update.error.busy' => 423,
        'update.error.wrong_step', 'update.error.wrong_update', 'update.error.not_running' => 409,
        default => 500,
    };
    $error = ['key' => $e->messageKey, 'params' => $e->params];

    try {
        $state = $updater->state();
    } catch (UpdateException) {
        $state = UpdateState::idle();
    }
}

if (!$wantsJson) {
    if ($error !== null) {
        AdminAuth::start();
        $_SESSION['admin_update_flash'] = ['type' => 'error'] + $error;
    }

    header('Location: /admin/updates.php', true, 303);
    exit;
}

$message = $state->message();
$label = AdminTranslator::trans('update.step.' . ($state->step() !== '' ? $state->step() : 'start'));
// The download and the apply take several requests; the server says how far
// they are, the script only shows it.
$progress = $state->isRunning() ? $state->progress() : null;

http_response_code($httpStatus);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'update_id' => $state->updateId(),
    'status' => $state->status(),
    'step' => $state->step(),
    'step_label' => $progress !== null ? AdminTranslator::trans('update.step.progress', ['step' => $label, 'percent' => $progress]) : $label,
    'progress' => $progress,
    'running' => $state->isRunning(),
    'delay_ms' => (int) $state->get('delay_ms', 0),
    'message' => $message !== null ? AdminTranslator::trans($message['key'], $message['params']) : '',
    'error' => $error !== null ? AdminTranslator::trans($error['key'], $error['params']) : null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
