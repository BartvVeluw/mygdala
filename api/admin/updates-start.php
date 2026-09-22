<?php

/**
 * POST /api/admin/updates-start.php
 *
 * "Update installeren": starts an update towards the release the last check
 * found (App\Update\Updater::start()) and sends the administrator to the
 * Updates screen, whose script then runs the steps one request at a time
 * (api/admin/updates-step.php).
 *
 * Which release is installed is NOT a request parameter: it is the manifest
 * the last check verified and stored in private storage — what the screen
 * showed, and nothing a crafted POST could change.
 *
 * Same PRG/session-flash pattern as api/admin/updates-check.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Update\UpdateException;
use App\Update\Updater;

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

try {
    Updater::fromConfig()->start(AdminAuth::userName() . ' (#' . (AdminAuth::userId() ?? 0) . ')');
} catch (UpdateException $e) {
    $_SESSION['admin_update_flash'] = ['type' => 'error', 'key' => $e->messageKey, 'params' => $e->params];
    header('Location: /admin/updates.php', true, 303);
    exit;
}

header('Location: /admin/updates.php?run=1', true, 303);
exit;
