<?php

/**
 * POST /api/admin/updates-resolve.php
 *
 * After an update ended in recovery_required, and only then: the
 * administrator confirms that the site was put right by hand
 * (docs/updates/RECOVERY.md), so the maintenance flag may go and a new update
 * may start (App\Update\Updater::resolve()). The backup and the log are kept.
 *
 * Reachable during maintenance (App\Update\MaintenanceGuard::EXEMPT): it is
 * the way out of it that does not need FTP.
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
    Updater::fromConfig()->resolve((string) ($_POST['update_id'] ?? ''), AdminAuth::userName() . ' (#' . (AdminAuth::userId() ?? 0) . ')');
    $_SESSION['admin_update_flash'] = ['type' => 'success', 'key' => 'update.flash.resolved', 'params' => []];
} catch (UpdateException $e) {
    $_SESSION['admin_update_flash'] = ['type' => 'error', 'key' => $e->messageKey, 'params' => $e->params];
}

header('Location: /admin/updates.php', true, 303);
exit;
