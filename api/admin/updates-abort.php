<?php

/**
 * POST /api/admin/updates-abort.php
 *
 * "Afbreken" for an update that has not changed the site yet
 * (App\Update\Updater::abort()): the maintenance flag goes, the download,
 * staging and any partial backup are thrown away. Once files or the database
 * have been touched this refuses; from there the only ways are forward or
 * back through the rollback.
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
    Updater::fromConfig()->abort((string) ($_POST['update_id'] ?? ''));
    $_SESSION['admin_update_flash'] = ['type' => 'success', 'key' => 'update.message.aborted', 'params' => []];
} catch (UpdateException $e) {
    $_SESSION['admin_update_flash'] = ['type' => 'error', 'key' => $e->messageKey, 'params' => $e->params];
}

header('Location: /admin/updates.php', true, 303);
exit;
